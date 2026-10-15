<?php

/*
 * C2 — commercial access: product by ad or conversation, free sample once,
 * subscription states, payments (start, review) and blocking only the paid
 * service, for PHP modules and configured topics alike.
 */

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\CustomerFact;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Models\Plan;
use App\Models\Product;
use App\Models\SampleDelivery;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\Topic;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Platform\Capabilities\CapabilityResult;
use App\Platform\Commerce\AccessDecision;
use App\Platform\Commerce\AccessGate;
use App\Platform\Commerce\PaymentReview;
use App\Platform\Commerce\SampleLedger;
use App\Platform\Conversation\ConversationPipeline;
use App\Platform\Domains\DomainRegistry;
use App\Platform\Memory\CustomerMemory;
use App\Platform\Memory\FactDefinition;
use App\Services\Inventory\ProductFinderService;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\Domains\Capabilities\LuckyNumberCapability;
use Tests\Support\Domains\FinanceTestDomain;
use Tests\Support\Domains\OracleTestDomain;

const C2_PHONE = '573007770000';

beforeEach(function () {
    config(['botinker.domains' => ['oracle' => OracleTestDomain::class, 'finance' => FinanceTestDomain::class]]);
    Storage::fake(PaymentReceipt::DISK);
    // Stored dates have second precision.
    $this->freezeSecond();

    $this->store = makeStore([
        'conversation_mode' => Store::MODE_KNOWLEDGE,
        'payment_instructions' => 'INSTRUCCIONES-PAGO: Nequi 3001234567',
    ]);
    $this->reading = c2Product($this->store, ['name' => 'Lectura']);
    $this->monthly = Plan::create(['product_id' => $this->reading->id, 'name' => 'Mensual', 'price' => 20000]);
});

function c2Product(Store $store, array $overrides = []): Product
{
    return Product::create(array_merge([
        'store_id' => $store->id, 'domain_key' => 'oracle', 'name' => 'Lectura', 'description' => 'x', 'price' => 1, 'stock' => 1,
        'required_facts' => ['birth_date'], 'capability_key' => 'oracle.lucky_number',
        'ai_sales_strategy' => 'SERVICIO-COMPLETO: lectura entera.',
        'access_mode' => Product::ACCESS_SUBSCRIPTION,
        'sample_instructions' => 'MUESTRA-INSTR: da solo el número de la suerte.',
        'sample_capability_key' => 'oracle.lucky_number',
    ], $overrides));
}

function c2Customer(Store $store, string $phone = C2_PHONE): Customer
{
    return app(CustomerMemory::class)->customerFor($store, $phone);
}

/** The customer's birth date, given to the oracle domain. */
function c2BirthDate(Store $store, string $phone = C2_PHONE): Customer
{
    $customer = c2Customer($store, $phone);
    app(CustomerMemory::class)->record($customer, 'oracle', FactDefinition::person('birth_date'), '1990-03-15', CustomerFact::SOURCE_CUSTOMER_ANSWER);

    return $customer;
}

function c2Subscribe(Customer $customer, Product $product, array $overrides = []): Subscription
{
    return Subscription::create(array_merge([
        'customer_id' => $customer->id, 'product_id' => $product->id, 'started_at' => now()->subDays(5), 'expires_at' => now()->addDays(25),
    ], $overrides));
}

function c2Job(Store $store, string $body, ?int $adProductId = null, string $phone = C2_PHONE): void
{
    ProcessWhatsAppMessage::dispatchSync($store->fresh(), $phone, $body, 'wamid.in.'.uniqid(), 'text', null, $adProductId);
    test()->travel(1)->seconds();
}

function c2Text(): string
{
    $sent = sentWhatsAppTexts();

    return end($sent)->data()['text']['body'];
}

function c2Prompt(): string
{
    $requests = mainChatRequests();

    return end($requests)->data()['messages'][0]['content'];
}

/** A payment of the customer already under review (receipt received). */
function c2PaymentUnderReview(Customer $customer, Plan $plan, bool $withReceipt = false): Payment
{
    $payment = Payment::start($customer, $plan, now()->addHours(48));
    $payment->update(['status' => Payment::STATUS_UNDER_REVIEW]);

    if ($withReceipt) {
        $path = "{$payment->store_id}/".Str::uuid().'.jpg';
        Storage::disk(PaymentReceipt::DISK)->put($path, 'imagen');
        PaymentReceipt::create(['payment_id' => $payment->id, 'file_path' => $path, 'file_hash' => hash('sha256', $path), 'extracted_data' => ['payer_name' => 'Ana']]);
    }

    return $payment;
}

function c2Webhook($test, Store $store, string $body, ?string $adId = null)
{
    $message = ['from' => C2_PHONE, 'id' => 'wamid.in.'.uniqid(), 'type' => 'text', 'text' => ['body' => $body]];

    if ($adId) {
        $message['referral'] = ['source_id' => $adId];
    }

    $raw = json_encode(['entry' => [['changes' => [['value' => ['metadata' => ['phone_number_id' => $store->wa_phone_number_id], 'messages' => [$message]]]]]]]);

    return $test->call('POST', '/api/whatsapp/webhook/any-token', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, 'app-secret'),
    ], $raw);
}

describe('product identification', function () {
    beforeEach(function () {
        $this->reading->update(['sample_instructions' => null, 'sample_capability_key' => null, 'meta_ad_ids' => ['ad-lectura']]);
        $this->chart = c2Product($this->store, ['name' => 'Carta astral', 'sample_instructions' => null, 'sample_capability_key' => null]);
        Plan::create(['product_id' => $this->chart->id, 'name' => 'Anual', 'price' => 90000, 'duration_days' => 365]);
        fakeExternalApis();
    });

    it('takes the product of a valid ad through the webhook', function () {
        c2Webhook($this, $this->store, 'Hola', 'ad-lectura')->assertOk();

        expect(c2Text())->toContain('Para usar «Lectura» necesitas una suscripción')
            ->and(Conversation::sole()->current_product_id)->toBe($this->reading->id);
    });

    it('identifies the product in the conversation without an ad', function () {
        c2Job($this->store, 'Quiero la Carta astral');

        expect(c2Text())->toContain('«Carta astral»');
    });

    it('falls back to asking when the ad is unknown, of another store or shared by several products', function (Closure $setup, string $adId) {
        $setup->call($this);

        c2Webhook($this, $this->store, 'Hola', $adId)->assertOk();

        expect(c2Text())->toStartWith('¿Sobre cuál de estas opciones quieres hablar?')
            ->and(Payment::count())->toBe(0)
            ->and(Subscription::count())->toBe(0);
    })->with([
        'unknown' => [fn () => null, 'ad-inexistente'],
        'of another store' => [fn () => c2Product(makeStore(['conversation_mode' => Store::MODE_KNOWLEDGE]), ['meta_ad_ids' => ['ad-ajeno']]), 'ad-ajeno'],
        'shared by two products' => [fn () => $this->chart->update(['meta_ad_ids' => ['ad-lectura']]), 'ad-lectura'],
    ]);

    it('never picks an arbitrary product for a shared ad, in the sales flow either', function () {
        Queue::fake();
        $sales = makeStore();
        foreach (['Crema', 'Jabón'] as $name) {
            Product::create(['store_id' => $sales->id, 'name' => $name, 'description' => 'x', 'price' => 1, 'stock' => 1, 'meta_ad_ids' => ['ad-compartido']]);
        }
        $single = Product::create(['store_id' => $sales->id, 'name' => 'Loción', 'description' => 'x', 'price' => 1, 'stock' => 1, 'meta_ad_ids' => ['ad-unico']]);

        expect(app(ProductFinderService::class)->findProductByAdId('ad-compartido', $sales->id))->toBeNull()
            ->and(app(ProductFinderService::class)->findProductByAdId('ad-unico', $sales->id)->id)->toBe($single->id);

        c2Webhook($this, $sales, 'Hola', 'ad-compartido')->assertOk();
        Queue::assertPushed(ProcessWhatsAppMessage::class, fn ($job) => $job->productContext === null);
    });

    it('ignores the ad of a product outside the active catalog', function () {
        Topic::create(['store_id' => $this->store->id, 'key' => 'runas', 'label' => 'Runas', 'instructions' => 'x', 'is_active' => false]);
        Product::create(['store_id' => $this->store->id, 'domain_key' => 'runas', 'name' => 'Runas', 'description' => 'x', 'price' => 1, 'stock' => 1, 'meta_ad_ids' => ['ad-runas']]);

        c2Webhook($this, $this->store, 'Hola', 'ad-runas')->assertOk();

        expect(c2Text())->toStartWith('¿Sobre cuál de estas opciones quieres hablar?')->not->toContain('Runas');
    });

    it('never grants access because of an ad', function () {
        c2BirthDate($this->store);

        c2Job($this->store, 'Hola', $this->reading->id);

        expect(mainChatRequests())->toBe([])->and(c2Text())->toContain('necesitas una suscripción');
    });
});

describe('free sample', function () {
    it('is given once, with the sample instructions, then the offer', function () {
        fakeExternalApis('Tu número de la suerte es 1.');

        c2Job($this->store, 'Quiero mi Lectura');
        expect(c2Text())->toBe('¿Cuál es tu fecha de nacimiento? (día/mes/año)');

        c2Job($this->store, '15/03/1990');

        expect(c2Prompt())
            ->toContain("### MUESTRA GRATUITA\n")
            ->toContain('MUESTRA-INSTR: da solo el número de la suerte.')
            ->toContain('Número de la suerte calculado: 1.')
            ->not->toContain('SERVICIO-COMPLETO')
            ->and(c2Text())->toStartWith('Tu número de la suerte es 1.')
            ->toContain('Esta fue tu muestra gratuita de «Lectura».')
            ->toContain('1. Mensual: $20.000 COP por 30 días')
            ->and(SampleDelivery::sole())->capability_key->toBe('oracle.lucky_number');

        $calls = count(mainChatRequests());
        c2Job($this->store, '¿Y qué más?');

        expect(count(mainChatRequests()))->toBe($calls)
            ->and(c2Text())->toStartWith('Para usar «Lectura» necesitas una suscripción.')
            ->and(SampleDelivery::count())->toBe(1);
    });

    it('is reserved once per customer and product', function () {
        $customer = c2Customer($this->store);
        $ledger = app(SampleLedger::class);

        $first = $ledger->reserve($customer, $this->reading);

        expect($first)->not->toBeNull()
            ->and($ledger->reserve($customer, $this->reading))->toBeNull()
            ->and($ledger->reserve(c2Customer($this->store, '573000000001'), $this->reading))->not->toBeNull()
            ->and($ledger->reserve($customer, c2Product($this->store, ['name' => 'Otra'])))->not->toBeNull();
    });

    it('releases only its own reservation', function () {
        $customer = c2Customer($this->store);
        $ledger = app(SampleLedger::class);
        $mine = $ledger->reserve($customer, $this->reading);
        $mine->delete();
        $other = $ledger->reserve($customer, $this->reading);

        $ledger->release($mine);

        expect(SampleDelivery::whereKey($other->id)->exists())->toBeTrue();
    });

    it('is not recorded anywhere when WhatsApp did not take it, and a retry delivers it once', function () {
        $whatsAppDown = true;
        Http::fake(function (Request $request) use (&$whatsAppDown) {
            return match (true) {
                str_contains($request->url(), 'api.openai.com') => Http::response(['choices' => [['message' => ['content' => 'MUESTRA-ENVIADA']]]]),
                $whatsAppDown => Http::response(['error' => 'down'], 500),
                default => Http::response(['messages' => [['id' => 'wamid.out.'.Str::random(8)]]]),
            };
        });
        c2BirthDate($this->store);

        // The job fails, so the existing queue retries take over.
        expect(fn () => c2Job($this->store, 'Quiero mi Lectura'))->toThrow(RuntimeException::class, 'could not be sent')
            ->and(SampleDelivery::count())->toBe(0)
            ->and(WhatsAppMessage::count())->toBe(0);

        $whatsAppDown = false;
        c2Job($this->store, 'Quiero mi Lectura');

        expect(SampleDelivery::count())->toBe(1)
            ->and(WhatsAppMessage::where('role', 'assistant')->where('content', 'like', 'MUESTRA-ENVIADA%')->count())->toBe(1)
            ->and(WhatsAppMessage::where('role', 'user')->count())->toBe(1)
            ->and(WhatsAppMessage::where('role', 'assistant')->sole()->wamid)->not->toBeNull();
    });

    it('stays available when the LLM fails', function () {
        fakeExternalApis(failChat: true);
        c2BirthDate($this->store);

        expect(fn () => c2Job($this->store, 'Quiero mi Lectura'))->toThrow(Exception::class)
            ->and(SampleDelivery::count())->toBe(0);
    });
});

describe('subscription states', function () {
    beforeEach(function () {
        $this->reading->update(['sample_instructions' => null, 'sample_capability_key' => null]);
        $this->customer = c2BirthDate($this->store);
        fakeExternalApis('Lectura completa.');
    });

    it('gives the full service with an active subscription', function () {
        c2Subscribe($this->customer, $this->reading);

        c2Job($this->store, 'Quiero mi Lectura');

        expect(c2Prompt())->toContain('SERVICIO-COMPLETO')->not->toContain('MUESTRA GRATUITA')
            ->and(c2Text())->toBe('Lectura completa.');
    });

    it('blocks the paid service when the subscription is missing, expired or revoked', function (array $subscription, string $expected) {
        if ($subscription !== []) {
            c2Subscribe($this->customer, $this->reading, $subscription);
        }

        c2Job($this->store, 'Quiero mi Lectura');

        expect(mainChatRequests())->toBe([])
            ->and(c2Text())->toContain($expected)->toContain('1. Mensual: $20.000 COP por 30 días');
    })->with([
        'none' => [[], 'Para usar «Lectura» necesitas una suscripción.'],
        'expired' => [['started_at' => now()->subDays(40), 'expires_at' => now()->subDays(10)], 'venció el'],
        'revoked' => [['status' => Subscription::STATUS_REVOKED], 'Tu acceso a «Lectura» no está activo.'],
    ]);

    it('checks access at every entry, not only at the start', function () {
        c2Subscribe($this->customer, $this->reading, ['expires_at' => now()->addMinutes(5)]);

        c2Job($this->store, 'Quiero mi Lectura');
        expect(c2Text())->toBe('Lectura completa.');

        $this->travel(10)->minutes();
        c2Job($this->store, '¿Y el resto?');

        expect(c2Text())->toContain('venció el')->and(count(mainChatRequests()))->toBe(1);
    });
});

describe('payments', function () {
    beforeEach(function () {
        $this->reading->update(['sample_instructions' => null, 'sample_capability_key' => null]);
        $this->customer = c2BirthDate($this->store);
        fakeExternalApis('Lectura completa.');
    });

    it('starts a payment with the only active plan and its persisted price', function () {
        c2Job($this->store, 'Quiero pagar la Lectura');

        $payment = Payment::sole();
        expect($payment)->status->toBe(Payment::STATUS_PENDING)->plan_id->toBe($this->monthly->id)
            ->and($payment->expires_at->equalTo(now()->subSecond()->addHours(48)))->toBeTrue()
            ->and(c2Text())->toContain('plan Mensual: $20.000 COP por 30 días')->toContain('INSTRUCCIONES-PAGO: Nequi 3001234567')
            ->and(mainChatRequests())->toBe([]);

        c2Job($this->store, 'pagar');

        expect(Payment::count())->toBe(1)->and(c2Text())->toContain('INSTRUCCIONES-PAGO');
    });

    it('asks which plan when there are several', function () {
        $yearly = Plan::create(['product_id' => $this->reading->id, 'name' => 'Anual', 'price' => 180000.50, 'duration_days' => 365, 'sort_order' => 1]);

        c2Job($this->store, 'quiero suscribirme');
        expect(c2Text())->toContain("1. Mensual: \$20.000 COP por 30 días\n2. Anual: \$180.000,50 COP por 365 días")
            ->and(Payment::count())->toBe(0);

        c2Job($this->store, '2');

        expect(Payment::sole())->plan_id->toBe($yearly->id)->duration_days->toBe(365);
    });

    it('does not start a payment without payment instructions', function () {
        $this->store->update(['payment_instructions' => null]);

        c2Job($this->store, 'pagar');

        expect(Payment::count())->toBe(0)->and(c2Text())->toContain('no es posible pagar');
    });

    it('never grants access while the payment is pending, under review or not reflected', function (array $statuses, string $expected) {
        $payment = Payment::start($this->customer, $this->monthly, now()->addHours(48));
        foreach ($statuses as $status) {
            $payment->update(['status' => $status]);
        }

        c2Job($this->store, 'Quiero mi Lectura');

        expect(mainChatRequests())->toBe([])->and(c2Text())->toContain($expected);
    })->with([
        'pending' => [[], 'INSTRUCCIONES-PAGO'],
        'under review' => [[Payment::STATUS_UNDER_REVIEW], 'está en revisión'],
        'not reflected' => [[Payment::STATUS_UNDER_REVIEW, Payment::STATUS_NOT_REFLECTED], 'aún no se ha confirmado'],
    ]);

    it('grants the product once the payment is confirmed', function () {
        app(PaymentReview::class)->confirm(c2PaymentUnderReview($this->customer, $this->monthly));

        c2Job($this->store, 'Quiero mi Lectura');

        expect(c2Text())->toBe('Lectura completa.');
    });

    it('keeps prices, payment start and status available while blocked', function () {
        c2Job($this->store, '¿Cuál es el precio?');
        expect(c2Text())->toContain('Planes de «Lectura»:');

        c2Job($this->store, 'estado de mi pago');
        expect(c2Text())->toContain('No tienes pagos en curso');

        c2Job($this->store, 'pagar');
        expect(Payment::count())->toBe(1);

        c2Job($this->store, 'estado de mi pago');
        expect(c2Text())->toContain('INSTRUCCIONES-PAGO')->and(mainChatRequests())->toBe([]);
    });
});

describe('payment review', function () {
    beforeEach(function () {
        $this->customer = c2Customer($this->store);
        $this->review = app(PaymentReview::class);
    });

    it('creates the subscription and is a no-op when repeated', function () {
        $payment = c2PaymentUnderReview($this->customer, $this->monthly);
        $reviewer = User::factory()->create(['store_id' => $this->store->id]);

        $subscription = $this->review->confirm($payment, $reviewer, 'ok');
        $expiry = $subscription->expires_at;
        $again = $this->review->confirm($payment->fresh(), $reviewer);

        expect($subscription->expires_at->equalTo(now()->addDays(30)))->toBeTrue()
            ->and($again->id)->toBe($subscription->id)
            ->and($again->fresh()->expires_at->equalTo($expiry))->toBeTrue()
            ->and(Subscription::count())->toBe(1)
            ->and($payment->fresh())->status->toBe(Payment::STATUS_CONFIRMED)->reviewed_by->toBe($reviewer->id);
    });

    it('renews from the current expiry while valid, from now once expired', function () {
        $first = $this->review->confirm(c2PaymentUnderReview($this->customer, $this->monthly));
        $this->travel(10)->days();

        $renewed = $this->review->confirm(c2PaymentUnderReview($this->customer, $this->monthly));
        expect($renewed->id)->toBe($first->id)->and($renewed->expires_at->equalTo($first->expires_at->addDays(30)))->toBeTrue();

        $this->travel(60)->days();
        $late = $this->review->confirm(c2PaymentUnderReview($this->customer, $this->monthly));

        expect($late->expires_at->equalTo(now()->addDays(30)))->toBeTrue()->and($late->started_at->equalTo(now()))->toBeTrue();
    });

    it('reactivates a revoked subscription with a new confirmed payment', function () {
        c2Subscribe($this->customer, $this->reading, ['status' => Subscription::STATUS_REVOKED]);

        $subscription = $this->review->confirm(c2PaymentUnderReview($this->customer, $this->monthly));

        expect($subscription->status)->toBe(Subscription::STATUS_ACTIVE)->and($subscription->expires_at->equalTo(now()->addDays(30)))->toBeTrue();
    });

    it('keeps receipts while not reflected and drops them once confirmed or rejected', function (string $closing) {
        $payment = c2PaymentUnderReview($this->customer, $this->monthly, withReceipt: true);
        $receipt = $payment->receipts()->sole();

        $this->review->markNotReflected($payment);
        $this->review->markNotReflected($payment->fresh());
        Storage::disk(PaymentReceipt::DISK)->assertExists($receipt->file_path);
        expect(Subscription::count())->toBe(0);

        $closing === 'confirm' ? $this->review->confirm($payment->fresh()) : $this->review->reject($payment->fresh(), null, 'No llegó');

        Storage::disk(PaymentReceipt::DISK)->assertMissing($receipt->file_path);
        expect($receipt->fresh()->hasFile())->toBeFalse()
            ->and(Subscription::count())->toBe($closing === 'confirm' ? 1 : 0);
    })->with(['confirm', 'reject']);

    it('rejects operations the payment state does not allow', function (array $statuses, string $operation) {
        $payment = Payment::start($this->customer, $this->monthly, now()->addHours(48));
        foreach ($statuses as $status) {
            $payment->update(['status' => $status]);
        }

        expect(fn () => $this->review->{$operation}($payment))->toThrow(LogicException::class)
            ->and(Subscription::count())->toBe(0);
    })->with([
        'confirm pending' => [[], 'confirm'],
        'reject pending' => [[], 'reject'],
        'not reflected from pending' => [[], 'markNotReflected'],
        'reject confirmed' => [[Payment::STATUS_UNDER_REVIEW, Payment::STATUS_CONFIRMED], 'reject'],
        'confirm rejected' => [[Payment::STATUS_UNDER_REVIEW, Payment::STATUS_REJECTED], 'confirm'],
        'confirm expired' => [[Payment::STATUS_EXPIRED], 'confirm'],
    ]);
});

describe('isolation', function () {
    it('grants only the subscribed product', function () {
        $other = c2Product($this->store, ['name' => 'Carta astral', 'sample_instructions' => null, 'sample_capability_key' => null]);
        $customer = c2Customer($this->store);
        c2Subscribe($customer, $this->reading);

        expect(app(AccessGate::class)->decide($customer, $this->reading)->allowed)->toBeTrue()
            ->and(app(AccessGate::class)->decide($customer, $other)->allowed)->toBeFalse();
    });

    it('never shares access between stores', function () {
        $otherStore = makeStore(['conversation_mode' => Store::MODE_KNOWLEDGE]);
        $otherProduct = c2Product($otherStore);
        c2Subscribe(c2Customer($this->store), $this->reading);
        $sameEverywhere = c2Customer($otherStore);

        expect(app(AccessGate::class)->decide($sameEverywhere, $otherProduct)->allowed)->toBeFalse()
            ->and(fn () => app(AccessGate::class)->decide($sameEverywhere, $this->reading))->toThrow(InvalidArgumentException::class);
    });
});

describe('compatibility', function () {
    it('leaves open products and their commercial-looking words untouched', function () {
        $this->reading->update(['access_mode' => Product::ACCESS_OPEN, 'sample_instructions' => null, 'sample_capability_key' => null]);
        Plan::query()->delete();
        c2BirthDate($this->store);
        fakeExternalApis('Respuesta libre.');

        c2Job($this->store, '¿Cuál es el precio de mi Lectura?');

        expect(c2Text())->toBe('Respuesta libre.')->and(c2Prompt())->toContain('SERVICIO-COMPLETO')
            ->and(app(AccessGate::class)->decide(c2Customer($this->store), $this->reading->fresh())->reason)->toBe(AccessDecision::OPEN);
    });

    it('applies the same access rules to a configured topic, with no PHP', function () {
        $topicStore = makeStore(['conversation_mode' => Store::MODE_KNOWLEDGE, 'payment_instructions' => 'PAGO-TEMA']);
        Topic::create(['store_id' => $topicStore->id, 'key' => 'tarot', 'label' => 'Tarot', 'instructions' => 'INSTR-TAROT', 'vocabulary' => ['cartas']]);
        $tarot = Product::create(['store_id' => $topicStore->id, 'domain_key' => 'tarot', 'name' => 'Tarot', 'description' => 'x', 'price' => 1, 'stock' => 1,
            'access_mode' => Product::ACCESS_SUBSCRIPTION, 'sample_instructions' => 'MUESTRA-TAROT: una sola carta.']);
        Plan::create(['product_id' => $tarot->id, 'name' => 'Mensual', 'price' => 15000]);
        fakeExternalApis('Tu carta es El Loco.');

        c2Job($topicStore, 'Quiero una lectura de cartas');
        expect(c2Prompt())->toContain('MUESTRA-TAROT: una sola carta.')->toContain('INSTR-TAROT')
            ->and(c2Text())->toContain('Esta fue tu muestra gratuita de «Tarot».');

        c2Job($topicStore, 'Otra carta');
        expect(c2Text())->toStartWith('Para usar «Tarot» necesitas una suscripción.');

        app(PaymentReview::class)->confirm(c2PaymentUnderReview(c2Customer($topicStore), Plan::where('product_id', $tarot->id)->sole()));
        c2Job($topicStore, 'Otra carta');

        expect(c2Text())->toBe('Tu carta es El Loco.')->and(c2Prompt())->not->toContain('MUESTRA GRATUITA');
    });
});

describe('optional capability failures', function () {
    beforeEach(function () {
        app()->bind(LuckyNumberCapability::class, fn () => new class extends LuckyNumberCapability
        {
            public function run(array $facts): CapabilityResult
            {
                throw new RuntimeException('cálculo caído');
            }
        });
        app()->forgetInstance(DomainRegistry::class);
        $this->customer = c2BirthDate($this->store);
        fakeExternalApis('Respuesta.');
    });

    it('withholds the paid service for that turn, with a controlled reply', function () {
        c2Subscribe($this->customer, $this->reading);

        c2Job($this->store, 'Quiero mi Lectura');

        expect(mainChatRequests())->toBe([])
            ->and(c2Text())->toBe('No pude completar tu solicitud sobre «Lectura» en este momento. Intenta de nuevo en unos minutos.');
    });

    it('never consumes the sample', function () {
        c2Job($this->store, 'Quiero mi Lectura');

        expect(SampleDelivery::count())->toBe(0)->and(c2Text())->toStartWith('No pude completar');
    });

    it('does not affect other products', function () {
        c2Subscribe($this->customer, $this->reading);
        Product::create(['store_id' => $this->store->id, 'domain_key' => 'oracle', 'name' => 'Consulta libre', 'description' => 'x', 'price' => 1, 'stock' => 1]);

        c2Job($this->store, 'Quiero mi Lectura');
        c2Job($this->store, 'Quiero la Consulta libre');

        expect(c2Text())->toBe('Respuesta.')->and(count(mainChatRequests()))->toBe(1);
    });
});

describe('commercial requests vs service questions', function () {
    beforeEach(function () {
        $this->reading->update(['sample_instructions' => null, 'sample_capability_key' => null]);
        $this->customer = c2BirthDate($this->store);
        fakeExternalApis('Lectura completa.');
    });

    it('answers service questions that merely contain a commercial word', function (string $message, bool $subscribed) {
        if ($subscribed) {
            c2Subscribe($this->customer, $this->reading);
        }

        c2Job($this->store, 'Quiero mi Lectura');
        c2Job($this->store, $message);

        expect(Payment::count())->toBe(0)
            ->and(c2Text())->toBe($subscribed ? 'Lectura completa.' : "Para usar «Lectura» necesitas una suscripción.\n\nPlanes de «Lectura»:\n1. Mensual: \$20.000 COP por 30 días\n\nEscribe «pagar» para suscribirte.");
    })->with([
        '¿Debería comprar una casa?',
        '¿Debo pagar mis deudas primero?',
        '¿Cuánto cuesta una casa en Bogotá?',
        'no quiero pagar',
        '¿Me conviene renovar mi contrato de arriendo?',
        '¿Cómo va mi plan de vida?',
        '¿Debo pagar el servicio de luz?',
    ])->with(['subscribed' => true, 'not subscribed' => false]);

    it('serves explicit commercial requests, subscribed or not, without the LLM', function (string $message, string $expected, bool $subscribed) {
        if ($subscribed) {
            c2Subscribe($this->customer, $this->reading);
        }

        c2Job($this->store, $message);

        expect(mainChatRequests())->toBe([])->and(c2Text())->toContain($expected);
    })->with([
        'pay' => ['pagar', 'INSTRUCCIONES-PAGO'],
        'how to pay' => ['¿Cómo pago?', 'INSTRUCCIONES-PAGO'],
        'buy the product' => ['Quiero comprar la Lectura', 'INSTRUCCIONES-PAGO'],
        'renew' => ['Quiero renovar mi suscripción', 'INSTRUCCIONES-PAGO'],
        'price' => ['¿Cuál es el precio?', 'Planes de «Lectura»:'],
        'subscription price' => ['¿Cuánto cuesta la suscripción?', 'Planes de «Lectura»:'],
        'plans' => ['¿Qué planes tienen?', 'Planes de «Lectura»:'],
        'status' => ['estado de mi pago', '«Lectura»'],
    ])->with(['subscribed' => true, 'not subscribed' => false]);

    it('tells a subscriber asking for prices that the subscription is active', function () {
        c2Subscribe($this->customer, $this->reading);

        c2Job($this->store, '¿Cuál es el precio?');

        expect(c2Text())->toStartWith('Tu suscripción a «Lectura» está activa hasta el');
    });
});

describe('ambiguous ads in the sales flow', function () {
    beforeEach(function () {
        $this->sales = makeStore();
        foreach (['Crema', 'Jabón'] as $name) {
            Product::create(['store_id' => $this->sales->id, 'name' => $name, 'description' => 'x', 'price' => 1, 'stock' => 1, 'meta_ad_ids' => ['ad-compartido']]);
        }
        $this->lotion = Product::create(['store_id' => $this->sales->id, 'name' => 'Loción', 'description' => 'x', 'price' => 1, 'stock' => 1, 'meta_ad_ids' => ['ad-unico']]);
        fakeExternalApis('Respuesta de ventas.');
    });

    it('asks which product the shared ad means, without guessing', function () {
        c2Webhook($this, $this->sales, 'Hola, quiero info', 'ad-compartido')->assertOk();

        expect(c2Text())->toBe("¿Sobre cuál de estos productos quieres información?\n- Crema\n- Jabón\n\nEscríbeme el nombre.")
            ->and(mainChatRequests())->toBe([])
            ->and(WhatsAppMessage::where('store_id', $this->sales->id)->pluck('role')->all())->toBe(['user', 'assistant']);

        c2Webhook($this, $this->sales, 'Crema')->assertOk();

        expect(c2Text())->toBe('Respuesta de ventas.')->and(mainChatRequests())->toHaveCount(1);
    });

    it('keeps the attribution of a valid ad unchanged', function () {
        Queue::fake();

        c2Webhook($this, $this->sales, 'Hola', 'ad-unico')->assertOk();

        Queue::assertPushed(ProcessWhatsAppMessage::class, fn ($job) => $job->productContext === $this->lotion->id && $job->adProductCandidates === null);
    });
});

describe('permanent free access', function () {
    it('tells apart a free product, a paid product with a sample and a paid product without one', function () {
        $customer = c2Customer($this->store);
        $free = c2Product($this->store, ['name' => 'Gratis', 'access_mode' => Product::ACCESS_OPEN, 'sample_instructions' => null, 'sample_capability_key' => null]);
        $paidNoSample = c2Product($this->store, ['name' => 'Sin muestra', 'sample_instructions' => null, 'sample_capability_key' => null]);
        $gate = app(AccessGate::class);

        expect($gate->decide($customer, $free))->allowed->toBeTrue()->reason->toBe(AccessDecision::OPEN)
            ->and($gate->decide($customer, $this->reading))->allowed->toBeFalse()->sampleAvailable->toBeTrue()
            ->and($gate->decide($customer, $paidNoSample))->allowed->toBeFalse()->sampleAvailable->toBeFalse();
    });

    it('answers a free product again and again, with no subscription and no sample', function () {
        // A sample configured on a free product is kept but never used.
        $this->reading->update(['access_mode' => Product::ACCESS_OPEN]);
        Plan::query()->delete();
        c2BirthDate($this->store);
        fakeExternalApis('Respuesta gratuita.');

        foreach (['Quiero mi Lectura', '¿Y qué más?', 'Otra pregunta'] as $message) {
            c2Job($this->store, $message);
            expect(c2Text())->toBe('Respuesta gratuita.')->and(c2Prompt())->toContain('SERVICIO-COMPLETO')->not->toContain('MUESTRA GRATUITA');
        }

        expect(mainChatRequests())->toHaveCount(3)
            ->and(SampleDelivery::count())->toBe(0)
            ->and(Subscription::count())->toBe(0)
            ->and(Payment::count())->toBe(0);
    });
});

describe('sample delivery and retries', function () {
    beforeEach(function () {
        c2BirthDate($this->store);
    });

    it('keeps a sample WhatsApp accepted when saving the history fails, without a retry', function () {
        fakeExternalApis('MUESTRA-ENTREGADA');
        $logged = [];
        Event::listen(MessageLogged::class, function ($event) use (&$logged) {
            $logged[] = $event->message;
        });
        WhatsAppMessage::creating(function (WhatsAppMessage $message) {
            if ($message->role === 'assistant') {
                throw new RuntimeException('database unavailable');
            }
        });

        // The job ends normally: a retry would send a contradicting offer.
        ProcessWhatsAppMessage::dispatchSync($this->store->fresh(), C2_PHONE, 'Quiero mi Lectura', 'wamid.in.persist');

        expect(sentWhatsAppTexts())->toHaveCount(1)
            ->and(c2Text())->toStartWith('MUESTRA-ENTREGADA')
            ->and(SampleDelivery::count())->toBe(1)
            ->and(WhatsAppMessage::count())->toBe(0) // both turns or neither
            ->and($logged)->toContain('SAMPLE_HISTORY_NOT_SAVED');
    });

    it('reuses the generated sample when the same message is retried', function () {
        $whatsAppDown = true;
        Http::fake(function (Request $request) use (&$whatsAppDown) {
            return match (true) {
                str_contains($request->url(), 'api.openai.com') => Http::response(['choices' => [['message' => ['content' => 'MUESTRA-UNICA']]]]),
                $whatsAppDown => Http::response(['error' => 'down'], 500),
                default => Http::response(['messages' => [['id' => 'wamid.out.'.Str::random(8)]]]),
            };
        });
        $cacheKey = "sample-answer:{$this->store->id}:".hash('sha256', 'wamid.in.retry');

        expect(fn () => ProcessWhatsAppMessage::dispatchSync($this->store->fresh(), C2_PHONE, 'Quiero mi Lectura', 'wamid.in.retry'))
            ->toThrow(RuntimeException::class, 'could not be sent');

        // Kept encrypted for the retry: the customer's sample is not readable in the cache.
        expect(Cache::get($cacheKey))->toBeString()->not->toContain('MUESTRA-UNICA');

        $whatsAppDown = false;
        ProcessWhatsAppMessage::dispatchSync($this->store->fresh(), C2_PHONE, 'Quiero mi Lectura', 'wamid.in.retry');

        expect(mainChatRequests())->toHaveCount(1)
            ->and(c2Text())->toStartWith('MUESTRA-UNICA')
            ->and(SampleDelivery::count())->toBe(1)
            ->and(WhatsAppMessage::where('role', 'assistant')->count())->toBe(1)
            ->and(WhatsAppMessage::where('role', 'user')->count())->toBe(1)
            ->and(Cache::has($cacheKey))->toBeFalse();
    });

    it('keeps generating again without an inbound message id', function () {
        $whatsAppDown = true;
        Http::fake(function (Request $request) use (&$whatsAppDown) {
            return match (true) {
                str_contains($request->url(), 'api.openai.com') => Http::response(['choices' => [['message' => ['content' => 'MUESTRA']]]]),
                $whatsAppDown => Http::response(['error' => 'down'], 500),
                default => Http::response(['messages' => [['id' => 'wamid.out.'.Str::random(8)]]]),
            };
        });
        $pipeline = app(ConversationPipeline::class);

        expect(fn () => $pipeline->handle($this->store->fresh(), C2_PHONE, 'Quiero mi Lectura'))->toThrow(RuntimeException::class);

        $whatsAppDown = false;
        $pipeline->handle($this->store->fresh(), C2_PHONE, 'Quiero mi Lectura');

        expect(mainChatRequests())->toHaveCount(2)->and(SampleDelivery::count())->toBe(1);
    });
});
