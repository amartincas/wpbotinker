<?php

/*
 * A confirmed subscription payment (knowledge mode) becomes one Lead:
 * PaymentObserver queues RecordPaymentLead after the review commits, and
 * the existing LeadObserver reports it to Meta's Conversions API under its
 * own conditions. The test queue is sync, so the job runs at once unless
 * faked. Meta and the AI providers are simulated (Http::fake).
 */

use App\Jobs\ProcessWhatsAppMessage;
use App\Jobs\RecordPaymentLead;
use App\Livewire\WhatsAppChatCenter;
use App\Models\Conversation;
use App\Models\CustomerFact;
use App\Models\Lead;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WhatsAppTemplate;
use App\Platform\Commerce\PaymentReview;
use App\Platform\Memory\CustomerMemory;
use App\Platform\Memory\FactDefinition;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\Domains\OracleTestDomain;

const PL_CUSTOMER = '573007774444';

const PL_ADMIN = '573110000008';

beforeEach(function () {
    config(['botinker.domains' => ['oracle' => OracleTestDomain::class]]);
    Storage::fake('local');
    Storage::fake(PaymentReceipt::DISK);
    $this->freezeSecond();

    Http::fake(function (Request $request) {
        $url = $request->url();

        return match (true) {
            str_starts_with($url, 'https://api.openai.com/') => Http::response(['choices' => [['message' => ['content' => json_encode([
                'amount' => 20000, 'date' => now()->format('d/m/Y'), 'time' => '10:00', 'reference' => 'AB-1', 'entity' => 'Nequi', 'payer_name' => 'Ana', 'uncertain' => false,
            ])]]]]),
            str_starts_with($url, 'https://graph.facebook.com/v20.0/media-') => Http::response(['url' => 'https://lookaside.fbsbx.com/'.basename($url)]),
            str_starts_with($url, 'https://lookaside.fbsbx.com/') => Http::response('IMAGE-'.basename($url), 200, ['Content-Type' => 'image/jpeg']),
            str_ends_with($url, '/events') => Http::response(['events_received' => 1]),
            str_ends_with($url, '/messages') => Http::response(['messages' => [['id' => 'wamid.out.'.Str::random(8)]]]),
            default => Http::response([], 404),
        };
    });

    $this->store = makeStore([
        'conversation_mode' => Store::MODE_KNOWLEDGE, 'payment_instructions' => 'Nequi 300', 'alert_phone' => PL_ADMIN,
        'meta_dataset_id' => 'DATASET-1', 'meta_capi_access_token' => 'capi-token', 'meta_capi_currency' => 'COP', 'wa_business_account_id' => 'WABA-1',
    ]);
    $this->product = Product::create([
        'store_id' => $this->store->id, 'domain_key' => 'oracle', 'name' => 'Lectura', 'description' => 'x', 'price' => 1, 'stock' => 1,
        'access_mode' => Product::ACCESS_SUBSCRIPTION, 'ai_sales_strategy' => 'SERVICIO',
    ]);
    $this->plan = Plan::create(['product_id' => $this->product->id, 'name' => 'Mensual', 'price' => 20000]);
    $this->customer = app(CustomerMemory::class)->customerFor($this->store, PL_CUSTOMER);
    Conversation::create(['store_id' => $this->store->id, 'customer_phone' => PL_CUSTOMER, 'last_session_at' => now(), 'ctwa_clid' => 'CLID-1']);
    // A lead alert template exists: a confirmed payment must not use it.
    WhatsAppTemplate::create(['store_id' => $this->store->id, 'name' => 'alerta_lead', 'body_preview' => 'x', 'language' => 'es_CO', 'type' => 'utility', 'parameters_map' => [], 'is_lead_alert' => true]);

    $this->payment = plUnderReview($this->store, $this->customer, $this->plan);

    $this->logged = [];
    Event::listen(MessageLogged::class, fn (MessageLogged $event) => $this->logged[] = $event->message);
});

/** A payment with an alerted receipt, ready for the admin's decision. */
function plUnderReview(Store $store, $customer, Plan $plan, string $mediaId = 'media-1'): Payment
{
    $payment = Payment::start($customer, $plan, now()->addHours(48));
    ProcessWhatsAppMessage::dispatchSync($store->fresh(), $customer->customer_phone, null, 'wamid.in.'.uniqid(), 'image', $mediaId);

    return $payment->fresh();
}

function plAdmin(Store $store, string $body): void
{
    ProcessWhatsAppMessage::dispatchSync($store->fresh(), PL_ADMIN, $body, 'wamid.in.'.uniqid());
}

/** @return list<string> names of the Conversions API events sent */
function plEvents(): array
{
    return Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/events'))
        ->map(fn ($p) => $p[0]->data()['data'][0]['event_name'])->values()->all();
}

function plLeadAlerts(): int
{
    return Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/messages') && ($r->data()['template']['name'] ?? null) === 'alerta_lead')->count();
}

it('records one Lead when the payment is confirmed, and Meta receives it once', function () {
    app(CustomerMemory::class)->record($this->customer, 'oracle', FactDefinition::person('name'), 'Ana Pérez', CustomerFact::SOURCE_CUSTOMER_ANSWER);

    plAdmin($this->store, "Sí P-{$this->payment->id}");

    $lead = Lead::sole();
    expect($lead->only(['store_id', 'product_id', 'payment_id', 'customer_phone', 'customer_name', 'product_service_name', 'ctwa_clid']))->toBe([
        'store_id' => $this->store->id, 'product_id' => $this->product->id, 'payment_id' => $this->payment->id,
        'customer_phone' => PL_CUSTOMER, 'customer_name' => 'Ana Pérez', 'product_service_name' => 'Lectura', 'ctwa_clid' => 'CLID-1',
    ])
        ->and((float) $lead->sale_value)->toBe(20000.0)
        ->and($lead->meta_capi_sent_at)->not->toBeNull()
        ->and($lead->is_processed)->toBeTrue()
        ->and(plEvents())->toBe(['LeadSubmitted', 'Purchase'])
        ->and(plLeadAlerts())->toBe(0)
        ->and(Subscription::count())->toBe(1);
});

it('names the Lead with the phone when the customer gave no name', function () {
    plAdmin($this->store, "Sí P-{$this->payment->id}");

    expect(Lead::sole()->customer_name)->toBe(PL_CUSTOMER);
});

it('never duplicates the Lead or its events when the confirmation is repeated', function () {
    plAdmin($this->store, "Sí P-{$this->payment->id}");
    plAdmin($this->store, "Sí P-{$this->payment->id}");
    app(PaymentReview::class)->decide($this->payment->fresh(), true); // another path, same payment

    expect(Lead::count())->toBe(1)->and(plEvents())->toBe(['LeadSubmitted', 'Purchase']);
});

it('records nothing new when a confirmed payment is saved again', function () {
    plAdmin($this->store, "Sí P-{$this->payment->id}");

    $this->payment->fresh()->update(['review_note' => 'nota posterior']);

    expect(Lead::count())->toBe(1)->and(plEvents())->toBe(['LeadSubmitted', 'Purchase']);
});

it('keeps one Lead per payment at the database level', function () {
    Lead::create(['store_id' => $this->store->id, 'payment_id' => $this->payment->id, 'customer_phone' => PL_CUSTOMER, 'customer_name' => 'Unknown', 'summary' => 'x']);

    expect(fn () => Lead::create(['store_id' => $this->store->id, 'payment_id' => $this->payment->id, 'customer_phone' => PL_CUSTOMER, 'customer_name' => 'Unknown', 'summary' => 'y']))
        ->toThrow(UniqueConstraintViolationException::class);

    plAdmin($this->store, "Sí P-{$this->payment->id}"); // a lead already exists for it

    expect(Lead::count())->toBe(1)->and(plEvents())->toBe([]);
});

it('gives each payment its own Lead', function () {
    plAdmin($this->store, "Sí P-{$this->payment->id}");
    $this->travel(31)->days();
    $second = plUnderReview($this->store, $this->customer, $this->plan, 'media-2');

    plAdmin($this->store, "Sí P-{$second->id}");

    expect(Lead::orderBy('id')->pluck('payment_id')->all())->toBe([$this->payment->id, $second->id]);
});

it('records the Lead without ctwa_clid, and LeadObserver sends nothing to Meta', function () {
    Conversation::where('customer_phone', PL_CUSTOMER)->update(['ctwa_clid' => null]);

    plAdmin($this->store, "Sí P-{$this->payment->id}");

    expect(Lead::sole()->ctwa_clid)->toBeNull()
        ->and(plEvents())->toBe([])
        ->and($this->logged)->toContain('MetaConversionsApiService: event skipped, no ctwa_clid (conversation did not start from a Click-to-WhatsApp ad)');
});

it('records the Lead without Conversions API credentials, and nothing is sent to Meta', function () {
    $this->store->update(['meta_dataset_id' => null, 'meta_capi_access_token' => null]);

    plAdmin($this->store, "Sí P-{$this->payment->id}");

    expect(Lead::count())->toBe(1)
        ->and(plEvents())->toBe([])
        ->and($this->logged)->toContain('MetaConversionsApiService: skipped, store has no CAPI credentials');
});

it('records no Lead for a rejected or not reflected payment', function () {
    app(PaymentReview::class)->markNotReflected($this->payment);
    expect(Lead::count())->toBe(0);

    plAdmin($this->store, "No P-{$this->payment->id}");

    expect(Lead::count())->toBe(0)->and(plEvents())->toBe([]);
});

it('records nothing for a confirmation that is rolled back', function () {
    try {
        DB::transaction(function () {
            app(PaymentReview::class)->decide($this->payment, true);

            throw new RuntimeException('rolled back');
        });
    } catch (RuntimeException) {
    }

    expect($this->payment->fresh()->status)->toBe(Payment::STATUS_UNDER_REVIEW)
        ->and(Lead::count())->toBe(0)
        ->and(plEvents())->toBe([]);
});

it('never fails the confirmation when the Lead cannot be recorded', function () {
    Lead::creating(fn () => throw new RuntimeException('leads unavailable'));

    plAdmin($this->store, "Sí P-{$this->payment->id}");

    expect($this->payment->fresh()->status)->toBe(Payment::STATUS_CONFIRMED)
        ->and(Subscription::count())->toBe(1)
        ->and($this->logged)->toContain('PAYMENT_LEAD_NOT_RECORDED')
        ->and(Http::recorded(fn (Request $r) => ($r->data()['to'] ?? null) === PL_CUSTOMER && str_contains($r->data()['text']['body'] ?? '', 'fue confirmado')))->toHaveCount(1);
});

it('still alerts the admin about leads of the sales flow', function () {
    $sales = makeStore(['alert_phone' => PL_ADMIN]);
    WhatsAppTemplate::create(['store_id' => $sales->id, 'name' => 'alerta_lead', 'body_preview' => 'x', 'language' => 'es_CO', 'type' => 'utility', 'parameters_map' => [], 'is_lead_alert' => true]);

    Lead::create(['store_id' => $sales->id, 'customer_phone' => '573001112233', 'customer_name' => 'Cliente', 'summary' => 'pedido']);

    expect(plLeadAlerts())->toBe(1);
});

describe('queued recording', function () {
    it('queues the Lead once, after the confirmation, without waiting for Meta', function () {
        Queue::fake([RecordPaymentLead::class]);

        plAdmin($this->store, "Sí P-{$this->payment->id}");
        plAdmin($this->store, "Sí P-{$this->payment->id}"); // repeated answer

        Queue::assertPushed(RecordPaymentLead::class, 1);
        Queue::assertPushed(RecordPaymentLead::class, fn (RecordPaymentLead $job) => $job->paymentId === $this->payment->id);
        expect(Lead::count())->toBe(0)
            ->and(plEvents())->toBe([]) // the confirmation itself never calls Meta
            ->and(Http::recorded(fn (Request $r) => ($r->data()['to'] ?? null) === PL_CUSTOMER && str_contains($r->data()['text']['body'] ?? '', 'fue confirmado')))->toHaveCount(1);
    });

    it('queues nothing for a rolled back, rejected or not reflected payment', function () {
        Queue::fake([RecordPaymentLead::class]);

        try {
            DB::transaction(function () {
                app(PaymentReview::class)->decide($this->payment, true);

                throw new RuntimeException('rolled back');
            });
        } catch (RuntimeException) {
        }

        app(PaymentReview::class)->markNotReflected($this->payment->fresh());
        plAdmin($this->store, "No P-{$this->payment->id}");

        Queue::assertNotPushed(RecordPaymentLead::class);
    });

    it('retries with backoff, below the queue retry window', function () {
        $job = new RecordPaymentLead($this->payment->id);

        expect($job->tries)->toBe(5)
            ->and($job->backoff())->toBe([60, 300, 900, 3600])
            ->and($job->timeout)->toBeLessThan((int) config('queue.connections.database.retry_after'));
    });

    it('is idempotent: running it again records nothing more', function () {
        Queue::fake([RecordPaymentLead::class]);
        plAdmin($this->store, "Sí P-{$this->payment->id}");

        app()->call([new RecordPaymentLead($this->payment->id), 'handle']);
        app()->call([new RecordPaymentLead($this->payment->id), 'handle']);

        expect(Lead::count())->toBe(1)->and(plEvents())->toBe(['LeadSubmitted', 'Purchase']);
    });

    it('records nothing for a payment that is not confirmed, or no longer exists', function () {
        app()->call([new RecordPaymentLead($this->payment->id), 'handle']);
        app()->call([new RecordPaymentLead(999999), 'handle']);

        expect(Lead::count())->toBe(0);
    });

    it('records the Lead on a retry after a failed run, once', function () {
        Queue::fake([RecordPaymentLead::class]);
        plAdmin($this->store, "Sí P-{$this->payment->id}");

        $failing = true;
        Lead::creating(function () use (&$failing) {
            if ($failing) {
                throw new RuntimeException('leads unavailable');
            }
        });

        expect(fn () => app()->call([new RecordPaymentLead($this->payment->id), 'handle']))->toThrow(RuntimeException::class);
        expect(Lead::count())->toBe(0);

        $failing = false;
        app()->call([new RecordPaymentLead($this->payment->id), 'handle']); // the queue's retry

        expect(Lead::count())->toBe(1)->and(plEvents())->toBe(['LeadSubmitted', 'Purchase']);
    });

    it('logs a Lead whose tries are exhausted', function () {
        (new RecordPaymentLead($this->payment->id))->failed(new RuntimeException('gave up'));

        expect($this->logged)->toContain('PAYMENT_LEAD_NOT_RECORDED');
    });

    it('leaves sales leads unprocessed, as before', function () {
        $sales = makeStore();

        expect(Lead::create(['store_id' => $sales->id, 'customer_phone' => '573001112233', 'customer_name' => 'Cliente', 'summary' => 'pedido'])->fresh()->is_processed)->toBeFalse();
    });
});

describe('Chat Center bot toggle', function () {
    beforeEach(function () {
        $this->operator = User::factory()->create(['store_id' => $this->store->id, 'is_super_admin' => false]);
    });

    it('pauses the bot without overwriting the name or summary of an existing Lead', function () {
        plAdmin($this->store, "Sí P-{$this->payment->id}");
        $lead = Lead::sole();

        Livewire::actingAs($this->operator)->test(WhatsAppChatCenter::class)
            ->set('selectedPhone', PL_CUSTOMER)
            ->set('botActive', false);

        expect($lead->fresh()->only(['customer_name', 'summary', 'payment_id']))->toBe($lead->only(['customer_name', 'summary', 'payment_id']))
            ->and($lead->fresh()->bot_active)->toBeFalse()
            ->and(Lead::count())->toBe(1);

        Livewire::actingAs($this->operator)->test(WhatsAppChatCenter::class)
            ->set('selectedPhone', PL_CUSTOMER)
            ->set('botActive', true);

        expect($lead->fresh()->bot_active)->toBeTrue()->and($lead->fresh()->customer_name)->toBe($lead->customer_name);
    });

    it('pauses every lead of the phone, so the bot really stops', function () {
        $first = Lead::create(['store_id' => $this->store->id, 'customer_phone' => PL_CUSTOMER, 'customer_name' => 'Ana', 'summary' => 'pedido 1']);
        $second = Lead::create(['store_id' => $this->store->id, 'customer_phone' => PL_CUSTOMER, 'customer_name' => 'Ana', 'summary' => 'pedido 2']);

        Livewire::actingAs($this->operator)->test(WhatsAppChatCenter::class)
            ->set('selectedPhone', PL_CUSTOMER)
            ->set('botActive', false);

        expect($first->fresh()->bot_active)->toBeFalse()->and($second->fresh()->bot_active)->toBeFalse()
            ->and($first->fresh()->summary)->toBe('pedido 1');
    });

    it('creates the Unknown control record only when the phone has no lead', function () {
        Livewire::actingAs($this->operator)->test(WhatsAppChatCenter::class)
            ->set('selectedPhone', '573009990000')
            ->set('botActive', false);

        expect(Lead::sole()->only(['store_id', 'customer_phone', 'customer_name', 'summary', 'bot_active']))->toBe([
            'store_id' => $this->store->id, 'customer_phone' => '573009990000', 'customer_name' => 'Unknown', 'summary' => 'WhatsApp Chat Center', 'bot_active' => false,
        ])->and(plEvents())->toBe([]);
    });
});
