<?php

/*
 * Free interactions: every subscription product gives a customer without
 * access up to the Core's global number of free replies (3) before the
 * offer, which follows only the last one; the plan list is not repeated
 * unasked, and picking a topic from a list answers the question asked.
 */

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Conversation;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Product;
use App\Models\SampleDelivery;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\WhatsAppMessage;
use App\Platform\Commerce\AccessGate;
use App\Platform\Commerce\FreeTurn;
use App\Platform\Commerce\SampleLedger;
use App\Platform\Memory\CustomerMemory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\Domains\FinanceTestDomain;
use Tests\Support\Domains\OracleTestDomain;

const FI_PHONE = '573008880000';

beforeEach(function () {
    config(['botinker.domains' => ['oracle' => OracleTestDomain::class, 'finance' => FinanceTestDomain::class]]);
    $this->freezeSecond();

    $this->store = makeStore([
        'conversation_mode' => Store::MODE_KNOWLEDGE,
        'payment_instructions' => 'INSTRUCCIONES-PAGO: Nequi 3001234567',
    ]);
    $this->product = fiProduct($this->store);
    Plan::create(['product_id' => $this->product->id, 'name' => 'Mensual', 'price' => 20000]);
});

function fiProduct(Store $store, array $overrides = []): Product
{
    return Product::create(array_merge([
        'store_id' => $store->id, 'domain_key' => 'oracle', 'name' => 'Lectura', 'description' => 'x', 'price' => 1, 'stock' => 1,
        'ai_sales_strategy' => 'SERVICIO-COMPLETO: lectura entera.',
        'access_mode' => Product::ACCESS_SUBSCRIPTION,
        'sample_instructions' => null,
    ], $overrides));
}

function fiJob(Store $store, string $body, string $phone = FI_PHONE): void
{
    ProcessWhatsAppMessage::dispatchSync($store->fresh(), $phone, $body, 'wamid.in.'.uniqid(), 'text', null);
    test()->travel(1)->seconds();
}

function fiText(): string
{
    $sent = sentWhatsAppTexts();

    return end($sent)->data()['text']['body'];
}

function fiPrompt(): string
{
    $requests = mainChatRequests();

    return end($requests)->data()['messages'][0]['content'];
}

/** One fake for the whole test: the LLM or WhatsApp go down when their flag is set. */
function fiSwitchableFake(&$llmDown, &$whatsAppDown, string $reply = 'CONSEJO'): void
{
    $llmDown ??= false;
    $whatsAppDown ??= false;

    Http::fake(function (Request $request) use (&$llmDown, &$whatsAppDown, $reply) {
        return match (true) {
            str_contains($request->url(), 'api.openai.com') => $llmDown
                ? Http::response(['error' => 'upstream'], 500)
                : Http::response(['choices' => [['message' => ['content' => $reply]]]]),
            $whatsAppDown => Http::response(['error' => 'down'], 500),
            default => Http::response(['messages' => [['id' => 'wamid.out.'.Str::random(8)]]]),
        };
    });
}

function fiTurns(Product $product): ?int
{
    return SampleDelivery::where('product_id', $product->id)->value('turns_used');
}

describe('three free replies', function () {
    it('gives two replies without the offer, then the offer once with the third', function () {
        fakeExternalApis('CONSEJO-UTIL');

        fiJob($this->store, 'Quiero una Lectura: ¿cómo empiezo?');
        expect(fiText())->toContain('CONSEJO-UTIL')->not->toContain('Planes de')->not->toContain('pagar')
            ->and(fiPrompt())->toContain('Respuesta gratuita 1 de 3')
            ->toContain(config('botinker.free_interactions.strategy.first'))
            ->toContain(config('botinker.free_interactions.strategy.all'))
            ->and(fiTurns($this->product))->toBe(1);

        fiJob($this->store, 'En el trabajo');
        expect(fiText())->toBe('CONSEJO-UTIL')
            ->and(fiPrompt())->toContain('Respuesta gratuita 2 de 3')->toContain(config('botinker.free_interactions.strategy.middle'))
            ->and(fiTurns($this->product))->toBe(2);

        fiJob($this->store, 'Lo probé y funcionó');
        expect(fiText())->toStartWith('CONSEJO-UTIL')
            ->and(substr_count(fiText(), '1. Mensual: $20.000 COP por 30 días'))->toBe(1)
            ->and(fiText())->toEndWith('Escribe «pagar» para suscribirte.')
            ->and(fiPrompt())->toContain('Respuesta gratuita 3 de 3')->toContain(config('botinker.free_interactions.strategy.last'))
            ->and(fiTurns($this->product))->toBe(3);

        $calls = count(mainChatRequests());
        fiJob($this->store, '¿Y ahora qué?');

        expect(count(mainChatRequests()))->toBe($calls)
            ->and(fiText())->toStartWith('Para usar «Lectura» necesitas una suscripción.')
            ->and(fiText())->not->toContain('Planes de')
            ->and(fiText())->toContain('Escribe «planes» para ver los precios o «pagar» para suscribirte.')
            ->and(fiTurns($this->product))->toBe(3);
    });

    it('shows the closing the product configured before the plans', function () {
        $this->product->update(['sample_closing_message' => 'BENEFICIOS-REALES del servicio completo.']);
        fakeExternalApis('CONSEJO');

        foreach (['Una Lectura', 'Sí', 'Listo'] as $message) {
            fiJob($this->store, $message);
        }

        expect(fiText())->toBe("CONSEJO\n\nBENEFICIOS-REALES del servicio completo.\n\nPlanes de «Lectura»:\n1. Mensual: \$20.000 COP por 30 días\n\nEscribe «pagar» para suscribirte.");
    });

    it('lets the product adapt the free replies, never their number', function () {
        $this->product->update(['sample_instructions' => 'MUESTRA-INSTR: solo una pista.']);
        fakeExternalApis('PISTA');

        fiJob($this->store, 'Una Lectura');
        expect(fiPrompt())->toContain("### MUESTRA GRATUITA\n")->toContain('MUESTRA-INSTR: solo una pista.')->not->toContain('SERVICIO-COMPLETO');

        fiJob($this->store, 'Sí');
        fiJob($this->store, 'Listo');
        expect(fiTurns($this->product))->toBe(3)->and(fiText())->toContain('Planes de');
    });

    it('uses the service instructions when the product has no sample instructions', function () {
        fakeExternalApis('RESPUESTA');

        fiJob($this->store, 'Una Lectura');

        expect(fiPrompt())->toContain('SERVICIO-COMPLETO')->toContain('Respuesta gratuita 1 de 3');
    });

    it('keeps the subject of a short follow-up in the knowledge search', function () {
        fakeExternalApis('RESPUESTA');

        fiJob($this->store, 'Una Lectura sobre el trabajo');
        fiJob($this->store, 'Sí, en la oficina');

        // The history the model gets carries the first exchange too.
        $requests = mainChatRequests();
        $messages = collect(end($requests)->data()['messages'])->pluck('content');
        expect($messages)->toContain('Una Lectura sobre el trabajo');
    });

    it('follows the global limit, and none when it is 0', function () {
        config(['botinker.free_interactions.max' => 1]);
        fakeExternalApis('UNICA');

        fiJob($this->store, 'Una Lectura');
        expect(fiText())->toContain('Planes de')->and(fiPrompt())->toContain('Respuesta gratuita 1 de 1')
            ->toContain(config('botinker.free_interactions.strategy.last'));

        config(['botinker.free_interactions.max' => 0]);
        $other = fiProduct($this->store, ['name' => 'Otra']);

        expect($other->offersSample())->toBeFalse()
            ->and(app(AccessGate::class)->decide(app(CustomerMemory::class)->customerFor($this->store, FI_PHONE), $other)->sampleAvailable)->toBeFalse();
    });
});

describe('commercial requests during the free replies', function () {
    it('answers prices at once without spending a free reply', function () {
        fakeExternalApis('CONSEJO');

        fiJob($this->store, '¿Cuánto cuesta la Lectura?');

        expect(fiText())->toContain('1. Mensual: $20.000 COP por 30 días')
            ->and(mainChatRequests())->toHaveCount(0)
            ->and(fiTurns($this->product))->toBeNull();

        fiJob($this->store, 'Quiero mi Lectura');
        expect(fiTurns($this->product))->toBe(1)->and(fiText())->not->toContain('Planes de');
    });

    it('keeps the payment flow for «pagar» at any free reply', function () {
        fakeExternalApis('CONSEJO');

        fiJob($this->store, 'Quiero mi Lectura');
        fiJob($this->store, 'pagar');

        expect(Payment::count())->toBe(1)
            ->and(fiText())->toContain('INSTRUCCIONES-PAGO')
            ->and(fiTurns($this->product))->toBe(1);
    });

    it('shows the plans asked for even right after showing them', function () {
        fakeExternalApis('CONSEJO');

        foreach (['Una Lectura', 'Sí', 'Listo'] as $message) {
            fiJob($this->store, $message);
        }

        fiJob($this->store, 'planes');

        expect(fiText())->toContain('1. Mensual: $20.000 COP por 30 días');
    });

    it('shows the plan list again unasked only after the interval', function () {
        fakeExternalApis('CONSEJO');

        foreach (['Una Lectura', 'Sí', 'Listo', 'Otra duda'] as $message) {
            fiJob($this->store, $message);
        }
        expect(fiText())->not->toContain('Planes de');

        $this->travel(25)->hours();
        fiJob($this->store, 'Otra duda más');

        expect(fiText())->toContain('Planes de «Lectura»')
            ->and(Conversation::sole()->offers_shown)->toHaveKey((string) $this->product->id);
    });
});

describe('no automatic messages', function () {
    it('sends nothing when the customer stops replying', function () {
        fakeExternalApis('CONSEJO');

        fiJob($this->store, 'Una Lectura');
        $this->travel(3)->days();
        $this->artisan('schedule:run');

        expect(sentWhatsAppTexts())->toHaveCount(1)
            ->and(WhatsAppMessage::where('role', 'assistant')->count())->toBe(1);
    });
});

describe('reservations, retries and failures', function () {
    it('reserves at most the limit, and a release gives the reply back', function () {
        $customer = app(CustomerMemory::class)->customerFor($this->store, FI_PHONE);
        $ledger = app(SampleLedger::class);

        $turns = collect(range(1, 3))->map(fn () => $ledger->reserve($customer, $this->product)?->turns_used);

        expect($turns->all())->toBe([1, 2, 3])
            ->and($ledger->reserve($customer, $this->product))->toBeNull();

        $ledger->release(SampleDelivery::sole());
        expect(fiTurns($this->product))->toBe(2)
            ->and($ledger->reserve($customer, $this->product)?->turns_used)->toBe(3);
    });

    it('removes the record when the first reply is released', function () {
        $customer = app(CustomerMemory::class)->customerFor($this->store, FI_PHONE);
        $ledger = app(SampleLedger::class);

        $ledger->release($ledger->reserve($customer, $this->product));

        expect(SampleDelivery::count())->toBe(0);
    });

    it('gives the reply back when the LLM fails', function () {
        $llmDown = false;
        fiSwitchableFake($llmDown, $whatsAppDown);
        fiJob($this->store, 'Una Lectura');

        $llmDown = true;
        expect(fn () => fiJob($this->store, 'Sí'))->toThrow(Exception::class)
            ->and(fiTurns($this->product))->toBe(1);
    });

    it('does not spend a reply WhatsApp did not take, and the retry delivers it once', function () {
        $whatsAppDown = false;
        fiSwitchableFake($llmDown, $whatsAppDown, 'SEGUNDA');
        fiJob($this->store, 'Una Lectura');

        $whatsAppDown = true;
        expect(fn () => ProcessWhatsAppMessage::dispatchSync($this->store->fresh(), FI_PHONE, 'Sí', 'wamid.in.retry'))->toThrow(RuntimeException::class)
            ->and(fiTurns($this->product))->toBe(1);

        $whatsAppDown = false;
        ProcessWhatsAppMessage::dispatchSync($this->store->fresh(), FI_PHONE, 'Sí', 'wamid.in.retry');

        expect(fiTurns($this->product))->toBe(2)
            ->and(WhatsAppMessage::where('role', 'assistant')->where('content', 'SEGUNDA')->count())->toBe(2);
    });

    it('counts a sample given before as one free reply used', function () {
        $customer = app(CustomerMemory::class)->customerFor($this->store, FI_PHONE);
        // A row from before the free interactions: the column default.
        SampleDelivery::create(['store_id' => $this->store->id, 'customer_id' => $customer->id, 'product_id' => $this->product->id]);
        fakeExternalApis('CONSEJO');

        fiJob($this->store, 'Una Lectura');

        expect(fiPrompt())->toContain('Respuesta gratuita 2 de 3')->and(fiTurns($this->product))->toBe(2);
    });

    it('rejects a turn outside the limit', function () {
        expect(fn () => new FreeTurn(4, 3))->toThrow(InvalidArgumentException::class)
            ->and(fn () => new FreeTurn(0, 3))->toThrow(InvalidArgumentException::class);
    });
});

describe('access controls', function () {
    it('gives subscribers the full service, with no free reply spent', function () {
        $customer = app(CustomerMemory::class)->customerFor($this->store, FI_PHONE);
        Subscription::create(['customer_id' => $customer->id, 'product_id' => $this->product->id, 'started_at' => now()->subDay(), 'expires_at' => now()->addDays(20)]);
        fakeExternalApis('SERVICIO');

        fiJob($this->store, 'Una Lectura');

        expect(fiText())->toBe('SERVICIO')
            ->and(fiPrompt())->toContain('SERVICIO-COMPLETO')->not->toContain('MUESTRA GRATUITA')
            ->and(SampleDelivery::count())->toBe(0);
    });

    it('leaves open products without free replies or offers', function () {
        $this->product->update(['access_mode' => Product::ACCESS_OPEN]);
        Plan::query()->delete();
        fakeExternalApis('ABIERTO');

        foreach (['Una Lectura', 'Sí', 'Listo', 'Otra'] as $message) {
            fiJob($this->store, $message);
            expect(fiText())->toBe('ABIERTO')->and(fiPrompt())->not->toContain('MUESTRA GRATUITA');
        }

        expect(SampleDelivery::count())->toBe(0);
    });
});

describe('picking a topic from the list', function () {
    beforeEach(function () {
        $this->finance = fiProduct($this->store, ['domain_key' => 'finance', 'name' => 'Asesoría']);
        Plan::create(['product_id' => $this->finance->id, 'name' => 'Mensual', 'price' => 30000]);
        fakeExternalApis('RESPUESTA-A-LA-PREGUNTA');
    });

    it('answers the question asked when the customer picks a number', function () {
        fiJob($this->store, 'Tengo una duda importante');
        expect(fiText())->toContain('¿Sobre cuál de estas opciones quieres hablar?');

        fiJob($this->store, '2');

        expect(fiPrompt())->toContain('### CONSULTA DEL CLIENTE')->toContain('Tengo una duda importante')
            ->toContain('Respuesta gratuita 1 de 3')
            ->and(fiTurns($this->finance))->toBe(1)
            ->and(fiTurns($this->product))->toBeNull()
            ->and(Conversation::sole()->pending_state)->toBeNull();
    });

    it('answers the question asked when the customer picks a name', function () {
        fiJob($this->store, 'Tengo una duda importante');
        fiJob($this->store, 'asesoría');

        expect(fiPrompt())->toContain('Tengo una duda importante')->and(fiTurns($this->finance))->toBe(1);
    });

    it('answers a new question that names a product as that question', function () {
        fiJob($this->store, 'Tengo una duda importante');
        fiJob($this->store, '¿La Asesoría sirve para empezar?');

        expect(fiPrompt())->not->toContain('### CONSULTA DEL CLIENTE')->and(fiTurns($this->finance))->toBe(1);
    });
});
