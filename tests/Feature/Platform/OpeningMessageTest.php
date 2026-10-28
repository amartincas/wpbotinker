<?php

/*
 * A product's opening message: sent exactly as configured, instead of the
 * store's welcome, as the first of the 3 free replies when the customer's
 * first message is one of its triggers (an ad's pre-filled message). The
 * texts here are test configuration, as the operator writes them in
 * Filament for Secretos de la Seducción; the AI is simulated.
 */

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Plan;
use App\Models\Product;
use App\Models\SampleDelivery;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\WhatsAppMessage;
use App\Platform\Commerce\OpeningMessage;
use App\Platform\Memory\CustomerMemory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\Domains\FinanceTestDomain;
use Tests\Support\Domains\OracleTestDomain;

const OM_PHONE = '573009990000';

const OM_TRIGGER = 'Quiero conocer el coach Secretos de la Seducción';

const OM_OPENING = "¡Excelente! 🔥 Soy Max, tu coach de seducción.\n\n"
    ."Con *Secretos de la Seducción* aprenderás a iniciar conversaciones con confianza, generar atracción de forma auténtica y reconocer señales de interés. También trabajarás tu carisma y la conexión emocional.\n\n"
    .'Para orientarte mejor, dime: *¿qué te cuesta más: acercarte a una mujer, mantener una conversación interesante o saber si le gustas?*';

const OM_CLOSING = 'En el coach completo trabajamos técnicas como esta con mayor profundidad, junto con carisma, lenguaje corporal y conexión emocional.';

beforeEach(function () {
    config(['botinker.domains' => ['oracle' => OracleTestDomain::class, 'finance' => FinanceTestDomain::class]]);
    $this->freezeSecond();

    $this->store = makeStore([
        'conversation_mode' => Store::MODE_KNOWLEDGE,
        'payment_instructions' => 'INSTRUCCIONES-PAGO: Nequi 3001234567',
        'welcome_message' => 'BIENVENIDA-TIENDA',
    ]);
    $this->coach = Product::create([
        'store_id' => $this->store->id, 'domain_key' => 'oracle', 'name' => 'Secretos de la Seducción', 'description' => 'x', 'price' => 1, 'stock' => 1,
        'ai_sales_strategy' => 'SERVICIO-COMPLETO',
        'access_mode' => Product::ACCESS_SUBSCRIPTION,
        'sample_instructions' => 'INSTR-GRATIS: si elige una de las tres opciones, da un consejo práctico para esa opción.',
        'sample_closing_message' => OM_CLOSING,
        'opening_message' => OM_OPENING,
        'opening_triggers' => [OM_TRIGGER],
    ]);
    // The price is the plan's, as configured in Filament.
    Plan::create(['product_id' => $this->coach->id, 'name' => 'Mes', 'price' => 29900]);

    // One fake for the whole test; WhatsApp goes down while the flag is set.
    $this->whatsAppDown = false;
    Http::fake(fn (Request $request) => match (true) {
        str_contains($request->url(), 'api.openai.com') => Http::response(['choices' => [['message' => ['content' => 'CONSEJO-DEL-MODELO']]]]),
        $this->whatsAppDown => Http::response(['error' => 'down'], 500),
        default => Http::response(['messages' => [['id' => 'wamid.out.'.Str::random(8)]]]),
    });
});

function omJob(Store $store, string $body, string $phone = OM_PHONE): void
{
    ProcessWhatsAppMessage::dispatchSync($store->fresh(), $phone, $body, 'wamid.in.'.uniqid(), 'text', null);
    test()->travel(1)->seconds();
}

function omTexts(): array
{
    return array_map(fn ($request) => $request->data()['text']['body'], sentWhatsAppTexts());
}

function omLastText(): string
{
    $texts = omTexts();

    return end($texts);
}

function omLastPrompt(): string
{
    $requests = mainChatRequests();

    return end($requests)->data()['messages'][0]['content'];
}

function omTurns(Product $product): ?int
{
    return SampleDelivery::where('product_id', $product->id)->value('turns_used');
}

describe('the sequence from the ad', function () {
    it('presents the coach, then advises, then offers the configured plans, one reply per customer message', function () {
        // 1. The ad's message: the opening, exactly as configured.
        omJob($this->store, OM_TRIGGER);

        expect(omTexts())->toBe([OM_OPENING])
            ->and(mainChatRequests())->toHaveCount(0)
            ->and(omTurns($this->coach))->toBe(1);

        // 2. The customer's need: a tip for it, with no price.
        omJob($this->store, 'Mantener una conversación interesante');

        expect(omTexts())->toHaveCount(2)
            ->and(omLastText())->toBe('CONSEJO-DEL-MODELO')
            ->and(omLastPrompt())->toContain('Respuesta gratuita 2 de 3')
            ->toContain(config('botinker.free_interactions.strategy.middle'))
            ->toContain('INSTR-GRATIS')
            ->and(omTurns($this->coach))->toBe(2);

        // The model sees the opening it "said" and the option chosen.
        $history = collect(mainChatRequests()[0]->data()['messages'])->pluck('content');
        expect($history)->toContain(OM_OPENING)->toContain('Mantener una conversación interesante');

        // 3. The next reply: the tip linked to the full coach, then the plans once.
        omJob($this->store, 'Gracias, lo voy a probar');

        expect(omTexts())->toHaveCount(3)
            ->and(omLastText())->toBe("CONSEJO-DEL-MODELO\n\n".OM_CLOSING."\n\nPlanes de «Secretos de la Seducción»:\n1. Mes: \$29.900 COP por 30 días\n\nEscribe «pagar» para suscribirte.")
            ->and(omLastPrompt())->toContain('Respuesta gratuita 3 de 3')->toContain(config('botinker.free_interactions.strategy.last'))
            ->and(omTurns($this->coach))->toBe(3);

        // No price before the third reply.
        expect(collect(omTexts())->take(2)->filter(fn ($text) => str_contains($text, 'Planes de') || str_contains($text, 'COP')))->toBeEmpty();
    });

    it('replaces the store welcome with the opening, and keeps the welcome otherwise', function () {
        omJob($this->store, OM_TRIGGER);
        expect(omLastText())->toBe(OM_OPENING)->not->toContain('BIENVENIDA-TIENDA');

        omJob($this->store, 'Hola, me bloqueo cuando la veo', '573009990001');
        expect(omLastText())->toStartWith('BIENVENIDA-TIENDA');
    });

    it('sends nothing more until the customer writes', function () {
        omJob($this->store, OM_TRIGGER);
        $this->travel(2)->days();
        $this->artisan('schedule:run');

        expect(omTexts())->toHaveCount(1)->and(WhatsAppMessage::where('role', 'assistant')->count())->toBe(1);
    });
});

describe('trigger matching', function () {
    it('ignores case, accents, punctuation and emoji', function (string $message) {
        omJob($this->store, $message);

        expect(omLastText())->toBe(OM_OPENING)->and(mainChatRequests())->toHaveCount(0);
    })->with([
        'as typed' => [OM_TRIGGER],
        'lowercase without accents' => ['quiero conocer el coach secretos de la seduccion'],
        'punctuation and emoji' => ['¡Quiero conocer el coach «Secretos de la Seducción»! 🔥'],
        'extra spaces' => ['  Quiero   conocer el coach Secretos de la Seducción  '],
    ]);

    it('answers any other first message with the model', function (string $message) {
        omJob($this->store, $message);

        expect(omLastText())->toContain('CONSEJO-DEL-MODELO')->not->toContain('Soy Max, tu coach')
            ->and(mainChatRequests())->toHaveCount(1)
            ->and(omTurns($this->coach))->toBe(1);
    })->with([
        'a question about the product' => ['Secretos de la Seducción: ¿cómo coqueteo sin parecer amigo?'],
        'the trigger plus a question' => [OM_TRIGGER.', ¿cómo me acerco?'],
    ]);

    it('normalizes the same way the operator and the customer write it', function () {
        expect(OpeningMessage::normalize('¡Quiero CONOCER el coach «Secretos de la Seducción»! 🔥'))
            ->toBe('quiero conocer el coach secretos de la seduccion');
    });
});

describe('the free reply counter', function () {
    it('never repeats the opening once a free reply was used', function () {
        omJob($this->store, OM_TRIGGER);
        omJob($this->store, OM_TRIGGER);

        expect(omTexts())->toHaveCount(2)
            ->and(omLastText())->toBe('CONSEJO-DEL-MODELO')
            ->and(omTurns($this->coach))->toBe(2);
    });

    it('continues from the second reply for a customer who already used the first', function () {
        $customer = app(CustomerMemory::class)->customerFor($this->store, OM_PHONE);
        SampleDelivery::create(['store_id' => $this->store->id, 'customer_id' => $customer->id, 'product_id' => $this->coach->id]);

        omJob($this->store, OM_TRIGGER);

        expect(omLastText())->not->toContain('Soy Max, tu coach')
            ->and(omLastPrompt())->toContain('Respuesta gratuita 2 de 3')
            ->and(omTurns($this->coach))->toBe(2);
    });

    it('gives the first reply back when WhatsApp did not take the opening, and the retry sends it once', function () {
        $this->whatsAppDown = true;

        expect(fn () => ProcessWhatsAppMessage::dispatchSync($this->store->fresh(), OM_PHONE, OM_TRIGGER, 'wamid.in.opening'))->toThrow(RuntimeException::class)
            ->and(omTurns($this->coach))->toBeNull();

        $this->whatsAppDown = false;
        ProcessWhatsAppMessage::dispatchSync($this->store->fresh(), OM_PHONE, OM_TRIGGER, 'wamid.in.opening');

        expect(omTurns($this->coach))->toBe(1)
            ->and(WhatsAppMessage::where('role', 'assistant')->where('content', OM_OPENING)->count())->toBe(1);
    });
});

describe('commercial requests and access', function () {
    it('answers prices or payment before the offer, and the opening still comes after', function () {
        omJob($this->store, '¿Cuánto cuesta Secretos de la Seducción?');
        expect(omLastText())->toContain('1. Mes: $29.900 COP por 30 días')->and(omTurns($this->coach))->toBeNull();

        omJob($this->store, OM_TRIGGER);
        expect(omLastText())->toBe(OM_OPENING)->and(omTurns($this->coach))->toBe(1);

        omJob($this->store, 'pagar');
        expect(omLastText())->toContain('INSTRUCCIONES-PAGO')->and(omTurns($this->coach))->toBe(1);
    });

    it('gives a subscriber the full service, not the opening', function () {
        $customer = app(CustomerMemory::class)->customerFor($this->store, OM_PHONE);
        Subscription::create(['customer_id' => $customer->id, 'product_id' => $this->coach->id, 'started_at' => now()->subDay(), 'expires_at' => now()->addDays(20)]);

        omJob($this->store, OM_TRIGGER);

        expect(omLastText())->toContain('CONSEJO-DEL-MODELO')->not->toContain('Soy Max, tu coach')
            ->and(omLastPrompt())->toContain('SERVICIO-COMPLETO')->not->toContain('MUESTRA GRATUITA')
            ->and(SampleDelivery::count())->toBe(0);
    });

    it('ignores an opening configured on an open product', function () {
        $this->coach->update(['access_mode' => Product::ACCESS_OPEN]);
        Plan::query()->delete();

        omJob($this->store, OM_TRIGGER);

        expect(omLastText())->toContain('CONSEJO-DEL-MODELO')->not->toContain('Soy Max, tu coach')
            ->and(SampleDelivery::count())->toBe(0);
    });

    it('leaves products without an opening unchanged', function () {
        $other = Product::create([
            'store_id' => $this->store->id, 'domain_key' => 'finance', 'name' => 'Asesoría', 'description' => 'x', 'price' => 1, 'stock' => 1,
            'ai_sales_strategy' => 'SERVICIO-ASESORIA', 'access_mode' => Product::ACCESS_SUBSCRIPTION,
        ]);
        Plan::create(['product_id' => $other->id, 'name' => 'Mes', 'price' => 10000]);

        omJob($this->store, 'Quiero conocer la Asesoría');

        expect(omLastText())->toStartWith('BIENVENIDA-TIENDA')->toContain('CONSEJO-DEL-MODELO')
            ->and(mainChatRequests())->toHaveCount(1)
            ->and(omTurns($other))->toBe(1);
    });
});
