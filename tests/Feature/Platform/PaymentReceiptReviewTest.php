<?php

/*
 * C4 — payment receipts by WhatsApp; C5 — the store admin decides by
 * replying «Sí/No P-n». Meta and the AI providers are simulated (Http::fake):
 * these are not tests against the real WhatsApp API.
 */

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppTemplate;
use App\Platform\Commerce\ReceiptIntake;
use App\Platform\Memory\CustomerMemory;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\Domains\OracleTestDomain;

const RR_CUSTOMER = '573007771111';
const RR_ADMIN = '573110000001';

beforeEach(function () {
    config(['botinker.domains' => ['oracle' => OracleTestDomain::class]]);
    Storage::fake('local');
    Storage::fake(PaymentReceipt::DISK);
    $this->freezeSecond();

    $this->store = makeStore(['conversation_mode' => Store::MODE_KNOWLEDGE, 'payment_instructions' => 'Nequi 3001234567', 'alert_phone' => '+57 311 000 0001']);
    $this->product = rrProduct($this->store, 'Lectura');
    $this->plan = Plan::create(['product_id' => $this->product->id, 'name' => 'Mensual', 'price' => 20000]);
    $this->customer = app(CustomerMemory::class)->customerFor($this->store, RR_CUSTOMER);

    $this->fake = new ArrayObject([
        'ocr' => json_encode(['amount' => 20000, 'date' => now()->format('d/m/Y'), 'time' => '10:00', 'reference' => 'AB-123', 'entity' => 'Nequi', 'payer_name' => 'Ana Pérez', 'uncertain' => false]),
        'ocr_status' => 200,
        'down_for' => null, // a phone whose sends fail
    ]);
    rrFakeHttp($this->fake);

    $this->logged = [];
    Event::listen(MessageLogged::class, fn (MessageLogged $event) => $this->logged[] = $event->message);
});

function rrProduct(Store $store, string $name): Product
{
    return Product::create([
        'store_id' => $store->id, 'domain_key' => 'oracle', 'name' => $name, 'description' => 'x', 'price' => 1, 'stock' => 1,
        'access_mode' => Product::ACCESS_SUBSCRIPTION, 'ai_sales_strategy' => 'SERVICIO',
    ]);
}

function rrFakeHttp(ArrayObject $fake): void
{
    Http::fake(function (Request $request) use ($fake) {
        $url = $request->url();

        return match (true) {
            str_starts_with($url, 'https://api.openai.com/') && str_contains($request->body(), 'image_url') => Http::response(['choices' => [['message' => ['content' => $fake['ocr']]]]], $fake['ocr_status']),
            str_starts_with($url, 'https://api.openai.com/') => Http::response(['choices' => [['message' => ['content' => 'Respuesta del servicio.']]]]),
            str_starts_with($url, 'https://graph.facebook.com/v20.0/media-') => Http::response(['url' => 'https://lookaside.fbsbx.com/'.basename($url)]),
            str_starts_with($url, 'https://lookaside.fbsbx.com/') => Http::response('IMAGE-BYTES-'.basename($url), 200, ['Content-Type' => 'image/jpeg']),
            str_ends_with($url, '/media') => Http::response(['id' => 'upload.'.Str::random(6)]),
            str_ends_with($url, '/messages') && ($request->data()['to'] ?? null) === $fake['down_for'] => Http::response(['error' => 'down'], 500),
            str_ends_with($url, '/messages') => Http::response(['messages' => [['id' => 'wamid.out.'.Str::random(8)]]]),
            default => Http::response([], 404),
        };
    });
}

function rrImage(Store $store, string $mediaId, ?string $caption = null, ?string $wamid = null, string $from = RR_CUSTOMER): void
{
    ProcessWhatsAppMessage::dispatchSync($store->fresh(), $from, $caption, $wamid ?? 'wamid.in.'.uniqid(), 'image', $mediaId);
}

function rrText(Store $store, string $body, string $from = RR_ADMIN): void
{
    ProcessWhatsAppMessage::dispatchSync($store->fresh(), $from, $body, 'wamid.in.'.uniqid());
}

/** Messages sent to $phone (any type), as their request data. */
function rrSentTo(string $phone): array
{
    return Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/messages') && ($request->data()['to'] ?? null) === $phone)
        ->map(fn ($pair) => $pair[0]->data())->values()->all();
}

function rrTextsTo(string $phone): array
{
    return array_values(array_map(fn ($data) => $data['text']['body'], array_filter(rrSentTo($phone), fn ($data) => ($data['type'] ?? null) === 'text')));
}

function rrLastTo(string $phone): ?string
{
    $texts = rrTextsTo($phone);

    return $texts === [] ? null : $texts[array_key_last($texts)];
}

function rrVisionCalls(): int
{
    return Http::recorded(fn (Request $request) => str_contains($request->body(), 'image_url'))->count();
}

function rrStart(Customer $customer, Plan $plan): Payment
{
    return Payment::start($customer, $plan, now()->addHours(48));
}

/** A payment with an alerted receipt, ready for the admin's decision. */
function rrUnderReview(Store $store, Plan $plan, string $mediaId = 'media-1'): Payment
{
    $payment = rrStart(app(CustomerMemory::class)->customerFor($store, RR_CUSTOMER), $plan);
    rrImage($store, $mediaId);

    return $payment->fresh();
}

describe('C4 — receipt intake', function () {
    it('attaches the receipt to the pending payment, stores it privately and alerts the store admin', function () {
        $payment = rrStart($this->customer, $this->plan);

        rrImage($this->store, 'media-1', null, 'wamid.in.receipt');

        $receipt = PaymentReceipt::sole();
        $alert = rrTextsTo(RR_ADMIN)[0];

        expect($payment->fresh())
            ->status->toBe(Payment::STATUS_UNDER_REVIEW)
            ->reference->toBe('AB123')
            ->receipt_submitted_at->not->toBeNull()
            ->and($receipt)->payment_id->toBe($payment->id)->source_wamid->toBe('wamid.in.receipt')->alerted_at->not->toBeNull()
            ->and($receipt->extracted_data)->toMatchArray(['amount' => 20000, 'reference' => 'AB-123', 'read_status' => 'read', 'flags' => []])
            ->and($receipt->extracted_data)->not->toHaveKey('payer_name')
            ->and(Storage::disk(PaymentReceipt::DISK)->exists($receipt->file_path))->toBeTrue()
            ->and(Storage::disk('local')->allFiles())->toBe([]) // the downloaded temp copy is gone
            ->and($alert)->toContain("P-{$payment->id}")->toContain('Valor esperado: $20.000 COP')->toContain('Referencia: AB-123')
            ->toContain("«Sí P-{$payment->id}»")->not->toContain('Ana Pérez')
            ->and(collect(rrSentTo(RR_ADMIN))->pluck('type')->all())->toBe(['text', 'image'])
            ->and(rrTextsTo(RR_CUSTOMER))->toBe(['Recibí tu comprobante de «Lectura». Lo estamos verificando y te avisaré cuando quede confirmado.'])
            ->and(Subscription::count())->toBe(0);
    });

    it('reads the image with the store own provider and key', function () {
        rrStart($this->customer, $this->plan);

        rrImage($this->store, 'media-1');

        $vision = Http::recorded(fn (Request $request) => str_contains($request->body(), 'image_url'))->first()[0];
        expect($vision->url())->toBe('https://api.openai.com/v1/chat/completions')
            ->and($vision->header('Authorization')[0])->toBe('Bearer sk-test')
            ->and($vision->data()['model'])->toBe('gpt-4o-mini');
    });

    it('asks for a clearer photo when the image is not readable as a receipt', function () {
        $this->fake['ocr'] = json_encode(['amount' => null, 'reference' => null, 'date' => null, 'uncertain' => true]);
        $payment = rrStart($this->customer, $this->plan);

        rrImage($this->store, 'media-1');

        expect(PaymentReceipt::count())->toBe(0)
            ->and($payment->fresh()->status)->toBe(Payment::STATUS_PENDING)
            ->and(rrSentTo(RR_ADMIN))->toBe([])
            ->and(Storage::disk(PaymentReceipt::DISK)->allFiles())->toBe([])
            ->and(Storage::disk('local')->allFiles())->toBe([])
            ->and(rrTextsTo(RR_CUSTOMER)[0])->toStartWith('No pude leer el comprobante');
    });

    it('still sends the receipt to review when the automatic reading fails or is unavailable', function (Closure $setup, string $flag, string $warning) {
        $setup->call($this);
        $payment = rrStart($this->customer, $this->plan);

        rrImage($this->store, 'media-1');

        expect($payment->fresh()->status)->toBe(Payment::STATUS_UNDER_REVIEW)
            ->and(PaymentReceipt::sole()->extracted_data['flags'])->toBe([$flag])
            ->and(rrTextsTo(RR_ADMIN)[0])->toContain($warning)->toContain('Valor: no disponible');
    })->with([
        'provider error' => [fn () => $this->fake['ocr_status'] = 500, 'ocr_failed', 'No se pudo leer el comprobante automáticamente.'],
        'invalid answer' => [fn () => $this->fake['ocr'] = 'no es JSON', 'ocr_failed', 'No se pudo leer el comprobante automáticamente.'],
        'provider without vision' => [fn () => $this->store->update(['ai_provider' => 'grok', 'ai_model' => 'grok-beta']), 'ocr_unavailable', 'La lectura automática no está disponible'],
    ]);

    it('warns the admin about a wrong amount and a reference already used in the store', function () {
        $other = app(CustomerMemory::class)->customerFor($this->store, '573000000009');
        $used = rrStart($other, $this->plan);
        $used->update(['status' => Payment::STATUS_UNDER_REVIEW, 'reference' => 'AB-123']);
        $this->fake['ocr'] = json_encode(['amount' => 15000, 'date' => now()->format('d/m/Y'), 'reference' => 'ab 123', 'uncertain' => false]);
        rrStart($this->customer, $this->plan);

        rrImage($this->store, 'media-2');

        expect(PaymentReceipt::sole()->extracted_data['flags'])->toBe(['amount_mismatch', 'reference_already_used'])
            ->and(rrTextsTo(RR_ADMIN)[0])->toContain('El valor leído no coincide')->toContain('Esa referencia ya aparece en otro pago');
    });

    it('never creates a payment from an image', function () {
        rrImage($this->store, 'media-1');

        expect(Payment::count())->toBe(0)
            ->and(PaymentReceipt::count())->toBe(0)
            ->and(rrSentTo(RR_ADMIN))->toBe([])
            ->and(Http::recorded(fn (Request $r) => str_contains($r->url(), 'media-1')))->toHaveCount(0)
            ->and(rrTextsTo(RR_CUSTOMER)[0])->toStartWith('No tengo un pago en curso');
    });

    it('expires a pending payment past its window instead of attaching to it', function () {
        $payment = rrStart($this->customer, $this->plan);
        $this->travel(49)->hours();

        rrImage($this->store, 'media-1');

        expect($payment->fresh()->status)->toBe(Payment::STATUS_EXPIRED)
            ->and(PaymentReceipt::count())->toBe(0)
            ->and(rrTextsTo(RR_CUSTOMER)[0])->toStartWith('Tu solicitud de pago venció');
    });

    it('asks which product when several payments are open, and takes the one named', function () {
        $chart = rrProduct($this->store, 'Carta astral');
        $chartPlan = Plan::create(['product_id' => $chart->id, 'name' => 'Anual', 'price' => 90000]);
        rrStart($this->customer, $this->plan);
        $chartPayment = rrStart($this->customer, $chartPlan);

        rrImage($this->store, 'media-1');
        expect(PaymentReceipt::count())->toBe(0)->and(rrTextsTo(RR_CUSTOMER)[0])->toContain('«Lectura», «Carta astral»');

        rrImage($this->store, 'media-1', 'Pago de la carta astral');
        expect(PaymentReceipt::sole()->payment_id)->toBe($chartPayment->id);
    });

    it('recognizes the same image sent again and alerts only once', function () {
        rrStart($this->customer, $this->plan);

        rrImage($this->store, 'media-1');
        rrImage($this->store, 'media-1'); // same bytes, another message

        expect(PaymentReceipt::count())->toBe(1)
            ->and(rrTextsTo(RR_ADMIN))->toHaveCount(1)
            ->and(rrLastTo(RR_CUSTOMER))->toStartWith('Ya recibimos este comprobante');
    });

    it('retries a failed alert from the stored receipt without reading it again', function () {
        $payment = rrStart($this->customer, $this->plan);
        $this->fake['down_for'] = RR_ADMIN;
        // Two attempts of the same queued message (the job only calls
        // failed() once its retries run out, which dispatchSync would not show).
        $attempt = fn () => app(ReceiptIntake::class)->handle($this->store->fresh(), RR_CUSTOMER, 'media-1', null, 'wamid.in.retry');

        expect($attempt)->toThrow(RuntimeException::class, 'could not be sent');
        expect(PaymentReceipt::sole()->alerted_at)->toBeNull()
            ->and($payment->fresh()->status)->toBe(Payment::STATUS_UNDER_REVIEW)
            ->and(rrTextsTo(RR_CUSTOMER))->toBe([]);

        $this->fake['down_for'] = null;
        $attempt();

        expect(rrVisionCalls())->toBe(1)
            ->and(PaymentReceipt::count())->toBe(1)
            ->and(PaymentReceipt::sole()->alerted_at)->not->toBeNull()
            // One failed attempt (WhatsApp 500) and one delivered alert.
            ->and(rrTextsTo(RR_ADMIN))->toHaveCount(2)
            ->and(WhatsAppMessage::where('customer_phone', RR_ADMIN)->where('role', 'assistant')->whereNotNull('wamid')->count())->toBe(1)
            ->and(rrTextsTo(RR_CUSTOMER))->toHaveCount(1);
    });

    it('uses the store template for the admin alert when the 24-hour window is closed', function () {
        WhatsAppTemplate::create([
            'store_id' => $this->store->id, 'name' => 'alerta_pago', 'body_preview' => 'Pago {{1}}', 'language' => 'es_CO', 'type' => 'utility',
            'parameters_map' => ['1' => 'payment_ref', '2' => 'amount'], 'event_key' => WhatsAppTemplate::EVENT_PAYMENT_ALERT,
        ]);
        $payment = rrStart($this->customer, $this->plan);

        rrImage($this->store, 'media-1');

        $template = collect(rrSentTo(RR_ADMIN))->firstWhere('type', 'template');
        expect($template['template']['name'])->toBe('alerta_pago')
            ->and(collect($template['template']['components'][0]['parameters'])->pluck('text')->all())->toBe(["P-{$payment->id}", '$20.000 COP']);
    });

    it('writes free text to the admin inside the 24-hour window', function () {
        WhatsAppTemplate::create(['store_id' => $this->store->id, 'name' => 'alerta_pago', 'body_preview' => 'x', 'language' => 'es_CO', 'type' => 'utility', 'parameters_map' => [], 'event_key' => WhatsAppTemplate::EVENT_PAYMENT_ALERT]);
        Conversation::create(['store_id' => $this->store->id, 'customer_phone' => RR_ADMIN, 'last_session_at' => now()->subHour()]);
        rrStart($this->customer, $this->plan);

        rrImage($this->store, 'media-1');

        expect(collect(rrSentTo(RR_ADMIN))->pluck('type')->all())->toBe(['text', 'image']);
    });

    it('accepts receipt images through the webhook only for knowledge stores', function () {
        rrStart($this->customer, $this->plan);
        $sales = makeStore(['alert_phone' => RR_ADMIN]);

        foreach ([$this->store, $sales] as $store) {
            $raw = json_encode(['entry' => [['changes' => [['value' => ['metadata' => ['phone_number_id' => $store->wa_phone_number_id],
                'messages' => [['from' => RR_CUSTOMER, 'id' => 'wamid.in.'.uniqid(), 'type' => 'image', 'image' => ['id' => "media-{$store->id}"]]]]]]]]]);
            $this->call('POST', '/api/whatsapp/webhook/x', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, 'app-secret')], $raw)->assertOk();
        }

        expect(PaymentReceipt::sole()->store_id)->toBe($this->store->id)
            ->and(Http::recorded(fn (Request $r) => str_contains($r->url(), "media-{$sales->id}")))->toHaveCount(0);
    });
});

describe('C5 — admin decision by WhatsApp', function () {
    it('confirms with «Sí P-n», activates the subscription once and tells the customer', function () {
        $payment = rrUnderReview($this->store, $this->plan);
        $file = PaymentReceipt::sole()->file_path;

        rrText($this->store, "Sí P-{$payment->id}");

        $subscription = Subscription::sole();
        expect($payment->fresh()->status)->toBe(Payment::STATUS_CONFIRMED)
            ->and($subscription->expires_at->equalTo(now()->addDays(30)))->toBeTrue()
            ->and(Storage::disk(PaymentReceipt::DISK)->exists($file))->toBeFalse()
            ->and(rrLastTo(RR_ADMIN))->toStartWith("✅ P-{$payment->id} confirmado.")
            ->and(rrLastTo(RR_CUSTOMER))->toBe("✅ Tu pago de «Lectura» fue confirmado. Tu acceso está activo hasta el {$subscription->expires_at->format('d/m/Y')}.");

        $customerMessages = count(rrTextsTo(RR_CUSTOMER));
        rrText($this->store, "si p{$payment->id}"); // repeated answer

        expect(Subscription::sole()->expires_at->equalTo($subscription->expires_at))->toBeTrue()
            ->and(count(rrTextsTo(RR_CUSTOMER)))->toBe($customerMessages)
            ->and(rrLastTo(RR_ADMIN))->toContain('ya estaba confirmado');
    });

    it('gives access to the paid service after the confirmation', function () {
        $payment = rrUnderReview($this->store, $this->plan);
        rrText($this->store, "Sí P-{$payment->id}");

        rrText($this->store, 'Quiero mi Lectura', RR_CUSTOMER);

        expect(rrLastTo(RR_CUSTOMER))->toBe('Respuesta del servicio.');
    });

    it('rejects with «No P-n» without granting access, and tells the customer', function () {
        $payment = rrUnderReview($this->store, $this->plan);

        rrText($this->store, "No P-{$payment->id}");

        expect($payment->fresh()->status)->toBe(Payment::STATUS_REJECTED)
            ->and(Subscription::count())->toBe(0)
            ->and(PaymentReceipt::sole()->hasFile())->toBeFalse()
            ->and(rrLastTo(RR_CUSTOMER))->toStartWith('Tu pago de «Lectura» no pudo confirmarse.');

        rrText($this->store, "Sí P-{$payment->id}");

        expect($payment->fresh()->status)->toBe(Payment::STATUS_REJECTED)
            ->and(Subscription::count())->toBe(0)
            ->and(rrLastTo(RR_ADMIN))->toContain('ya no está en revisión');
    });

    it('accepts a bare «Sí» only when a single payment awaits a decision', function () {
        $first = rrUnderReview($this->store, $this->plan, 'media-1');
        $otherCustomer = app(CustomerMemory::class)->customerFor($this->store, '573000000002');
        $second = rrStart($otherCustomer, $this->plan);
        rrImage($this->store, 'media-2', null, null, '573000000002');

        rrText($this->store, 'Sí');

        expect($first->fresh()->status)->toBe(Payment::STATUS_UNDER_REVIEW)
            ->and($second->fresh()->status)->toBe(Payment::STATUS_UNDER_REVIEW)
            ->and(rrLastTo(RR_ADMIN))->toContain('Hay 2 pagos en revisión')->toContain("P-{$first->id}")->toContain("P-{$second->id}");

        rrText($this->store, "No P-{$second->id}");
        rrText($this->store, 'sí');

        expect($first->fresh()->status)->toBe(Payment::STATUS_CONFIRMED)->and($second->fresh()->status)->toBe(Payment::STATUS_REJECTED);
    });

    it('never takes a customer «sí» as an approval', function () {
        $payment = rrUnderReview($this->store, $this->plan);

        rrText($this->store, "Sí P-{$payment->id}", RR_CUSTOMER);

        expect($payment->fresh()->status)->toBe(Payment::STATUS_UNDER_REVIEW)->and(Subscription::count())->toBe(0);
    });

    it('changes nothing for an unknown, foreign or not-yet-reviewable payment', function () {
        $reviewable = rrUnderReview($this->store, $this->plan);
        $otherStore = makeStore(['conversation_mode' => Store::MODE_KNOWLEDGE, 'alert_phone' => '573110000002']);
        $foreign = rrStart(app(CustomerMemory::class)->customerFor($otherStore, RR_CUSTOMER), Plan::create(['product_id' => rrProduct($otherStore, 'Ajeno')->id, 'name' => 'M', 'price' => 1]));
        $pendingOnly = rrStart(app(CustomerMemory::class)->customerFor($this->store, '573000000003'), $this->plan);

        rrText($this->store, 'Sí P-999999');
        expect(rrLastTo(RR_ADMIN))->toContain('No encuentro el pago P-999999');

        rrText($this->store, "Sí P-{$foreign->id}");
        expect(rrLastTo(RR_ADMIN))->toContain("No encuentro el pago P-{$foreign->id}");

        rrText($this->store, "Sí P-{$pendingOnly->id}");
        expect(rrLastTo(RR_ADMIN))->toContain('no tiene un comprobante en revisión');

        // The other store's admin cannot decide this store's payments, even naming them.
        rrText($otherStore, "Sí P-{$reviewable->id}", '573110000002');

        expect($reviewable->fresh()->status)->toBe(Payment::STATUS_UNDER_REVIEW)
            ->and($foreign->fresh()->status)->toBe(Payment::STATUS_PENDING)
            ->and($pendingOnly->fresh()->status)->toBe(Payment::STATUS_PENDING)
            ->and(Subscription::count())->toBe(0);
    });

    it('treats an admin message that is not a decision as a normal conversation', function () {
        rrUnderReview($this->store, $this->plan);

        rrText($this->store, '¿Sí pero cuánto cuesta la Lectura?');

        expect(Payment::sole()->status)->toBe(Payment::STATUS_UNDER_REVIEW)
            ->and(rrLastTo(RR_ADMIN))->toContain('Planes de «Lectura»');
    });

    it('keeps the decision when the customer cannot be told', function () {
        $payment = rrUnderReview($this->store, $this->plan);
        $this->fake['down_for'] = RR_CUSTOMER;

        rrText($this->store, "Sí P-{$payment->id}");

        expect($payment->fresh()->status)->toBe(Payment::STATUS_CONFIRMED)
            ->and(Subscription::count())->toBe(1)
            ->and($this->logged)->toContain('PAYMENT_RESULT_NOT_DELIVERED');
    });

    it('never answers with another store number or credentials', function () {
        $payment = rrUnderReview($this->store, $this->plan);

        rrText($this->store, "Sí P-{$payment->id}");

        $sends = Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/messages'));
        expect($sends->every(fn ($pair) => $pair[0]->url() === "https://graph.facebook.com/v20.0/{$this->store->wa_phone_number_id}/messages"))->toBeTrue();
    });
});
