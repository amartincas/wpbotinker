<?php

/*
 * Customer experience (knowledge mode), all configured in Filament:
 * the store's welcome, the free sample grounded in the topic's guide and
 * in the customer's original query, the product's sample closing and
 * activation message, and the reliability of the admin's decision. Meta and the AI providers are simulated
 * (Http::fake): these are not tests against the real APIs.
 */

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Conversation;
use App\Models\CustomerFact;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Models\Plan;
use App\Models\Product;
use App\Models\SampleDelivery;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\Topic;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppTemplate;
use App\Platform\Knowledge\KnowledgeIngestor;
use App\Platform\Memory\CustomerMemory;
use App\Platform\Memory\FactDefinition;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\Domains\OracleTestDomain;

const CX_CUSTOMER = '573007773333';

const CX_ADMIN = '573110000009';

beforeEach(function () {
    $this->freezeSecond();

    $this->fake = new ArrayObject([
        'plan' => '{"needs_knowledge": true, "sections": ["Piscis"]}',
        'plan_nutri' => '{"needs_knowledge": true, "sections": ["Ganar masa muscular"]}',
        'ocr' => json_encode(['amount' => 20000, 'date' => now()->format('d/m/Y'), 'time' => '10:00', 'reference' => 'AB-123', 'entity' => 'Nequi', 'payer_name' => 'Ana', 'uncertain' => false]),
        'down_for' => null, // a phone whose sends fail
    ]);
    cxFakeHttp($this->fake);

    $this->logged = [];
    Event::listen(MessageLogged::class, fn (MessageLogged $event) => $this->logged[] = $event->message);
});

function cxFakeHttp(ArrayObject $fake): void
{
    Http::fake(function (Request $request) use ($fake) {
        $url = $request->url();

        if (str_starts_with($url, 'https://api.openai.com/')) {
            if (str_contains($request->body(), 'image_url')) {
                return Http::response(['choices' => [['message' => ['content' => $fake['ocr']]]]]);
            }

            // The simulated selector follows each topic's guide, as a real model would.
            $input = collect($request->data()['messages'])->last()['content'] ?? '';
            $plan = str_contains($input, 'GUIA-NUTRI') ? $fake['plan_nutri'] : $fake['plan'];

            return Http::response(['choices' => [['message' => ['content' => cxIsPlanner($request) ? $plan : 'Respuesta generada.']]]]);
        }

        return match (true) {
            str_starts_with($url, 'https://graph.facebook.com/v20.0/media-') => Http::response(['url' => 'https://lookaside.fbsbx.com/'.basename($url)]),
            str_starts_with($url, 'https://lookaside.fbsbx.com/') => Http::response('IMAGE-'.basename($url), 200, ['Content-Type' => 'image/jpeg']),
            str_ends_with($url, '/messages') && ($request->data()['to'] ?? null) === $fake['down_for'] => Http::response(['error' => 'down'], 500),
            str_ends_with($url, '/messages') => Http::response(['messages' => [['id' => 'wamid.out.'.Str::random(8)]]]),
            default => Http::response([], 404),
        };
    });
}

function cxIsPlanner(Request $request): bool
{
    return str_contains($request->data()['messages'][0]['content'] ?? '', 'Eres un selector de conocimiento');
}

/** @return list<string> the user turns sent to the knowledge selector */
function cxPlannerInputs(): array
{
    return Http::recorded(fn (Request $r) => str_starts_with($r->url(), 'https://api.openai.com/') && cxIsPlanner($r))
        ->map(fn ($p) => collect($p[0]->data()['messages'])->last()['content'])->values()->all();
}

/** System prompts of the answering calls (not the selector, not the receipt reader). */
function cxAnswerPrompts(): array
{
    return Http::recorded(fn (Request $r) => str_starts_with($r->url(), 'https://api.openai.com/') && ! cxIsPlanner($r) && ! str_contains($r->body(), 'image_url'))
        ->map(fn ($p) => $p[0]->data()['messages'][0]['content'])->values()->all();
}

function cxJob(Store $store, string $body, string $from = CX_CUSTOMER, ?string $wamid = null): void
{
    ProcessWhatsAppMessage::dispatchSync($store->fresh(), $from, $body, $wamid ?? 'wamid.in.'.uniqid());
}

/** Messages sent to $phone that Meta accepted, as their request data. */
function cxSentTo(string $phone): array
{
    return Http::recorded(fn (Request $request, $response) => str_ends_with($request->url(), '/messages') && ($request->data()['to'] ?? null) === $phone && $response->successful())
        ->map(fn ($pair) => $pair[0]->data())->values()->all();
}

function cxTextsTo(string $phone): array
{
    return array_values(array_map(fn ($data) => $data['text']['body'], array_filter(cxSentTo($phone), fn ($data) => ($data['type'] ?? null) === 'text')));
}

function cxLastTo(string $phone): ?string
{
    $texts = cxTextsTo($phone);

    return $texts === [] ? null : $texts[array_key_last($texts)];
}

// ---------------------------------------------------------------------------
// Two configured topics: "Astrología" (person fact birth_date) and
// "Nutrición" (its own fact). Guides, sources and sample texts are test
// configuration, as an operator would write them in Filament.
// ---------------------------------------------------------------------------

function cxAstrology(Store $store, array $product = []): Product
{
    Topic::create([
        'store_id' => $store->id, 'key' => 'astrologia', 'label' => 'Astrología', 'instructions' => 'INSTRUCCION-ASTRO',
        'retrieval_instructions' => 'GUIA-ASTRO: relaciona la fecha del cliente con su sección.', 'facts' => [['scope' => 'person', 'key' => 'birth_date']],
    ]);
    app(KnowledgeIngestor::class)->ingest($store, 'astrologia', null, 'Signos', 'markdown',
        "# Aries\n\nARIES-TEXTO: impulsivo.\n\n# Piscis\n\nPISCIS-TEXTO: sensible.");

    $created = Product::create(array_merge([
        'store_id' => $store->id, 'domain_key' => 'astrologia', 'name' => 'Lectura Astral', 'description' => 'x', 'price' => 1, 'stock' => 1,
        'required_facts' => ['birth_date'], 'access_mode' => Product::ACCESS_SUBSCRIPTION,
        'ai_sales_strategy' => 'SERVICIO-ASTRO', 'sample_instructions' => 'MUESTRA-ASTRO',
    ], $product));
    Plan::create(['product_id' => $created->id, 'name' => 'Mensual', 'price' => 20000]);

    return $created;
}

function cxNutrition(Store $store): Product
{
    Topic::create([
        'store_id' => $store->id, 'key' => 'nutricion', 'label' => 'Nutrición', 'instructions' => 'INSTRUCCION-NUTRI',
        'retrieval_instructions' => 'GUIA-NUTRI: relaciona el objetivo del cliente con su sección.',
        'facts' => [['scope' => 'domain', 'key' => 'objetivo', 'type' => 'text', 'question' => '¿Cuál es tu objetivo?']],
    ]);
    app(KnowledgeIngestor::class)->ingest($store, 'nutricion', null, 'Objetivos', 'markdown',
        "# Ganar masa muscular\n\nMASA-TEXTO: proteína.\n\n# Perder grasa\n\nGRASA-TEXTO: déficit.");

    $created = Product::create([
        'store_id' => $store->id, 'domain_key' => 'nutricion', 'name' => 'Asesoría Nutricional', 'description' => 'x', 'price' => 1, 'stock' => 1,
        'required_facts' => ['objetivo'], 'access_mode' => Product::ACCESS_SUBSCRIPTION,
        'ai_sales_strategy' => 'SERVICIO-NUTRI', 'sample_instructions' => 'MUESTRA-NUTRI',
    ]);
    Plan::create(['product_id' => $created->id, 'name' => 'Mensual', 'price' => 30000]);

    return $created;
}

function cxBirthDate(Store $store, string $phone = CX_CUSTOMER): void
{
    $customer = app(CustomerMemory::class)->customerFor($store, $phone);
    app(CustomerMemory::class)->record($customer, 'astrologia', FactDefinition::person('birth_date'), '1990-03-15', CustomerFact::SOURCE_CUSTOMER_ANSWER);
}

describe('welcome message', function () {
    beforeEach(function () {
        $this->store = makeStore(['conversation_mode' => Store::MODE_KNOWLEDGE, 'payment_instructions' => 'Nequi 300', 'welcome_message' => "BIENVENIDA-TIENDA\nSoy el asistente."]);
        $this->product = cxAstrology($this->store);
    });

    it('is sent once, with the first reply, to a first-time customer', function () {
        cxJob($this->store, 'Hola');

        expect(cxLastTo(CX_CUSTOMER))->toBe("BIENVENIDA-TIENDA\nSoy el asistente.\n\n¿Cuál es tu fecha de nacimiento? (día/mes/año)");

        cxJob($this->store, '15/03/1990');
        cxJob($this->store, 'Gracias');

        $texts = cxTextsTo(CX_CUSTOMER);
        expect($texts)->toHaveCount(3)
            ->and(collect($texts)->filter(fn ($text) => str_contains($text, 'BIENVENIDA-TIENDA')))->toHaveCount(1)
            ->and($texts[1])->toStartWith('Respuesta generada.');
    });

    it('changes nothing when the store has no welcome configured', function () {
        $this->store->update(['welcome_message' => '   ']);

        cxJob($this->store, 'Hola');

        expect(cxLastTo(CX_CUSTOMER))->toBe('¿Cuál es tu fecha de nacimiento? (día/mes/año)');
    });

    it('is not sent to a customer who already wrote to the store', function () {
        WhatsAppMessage::create(['store_id' => $this->store->id, 'customer_phone' => CX_CUSTOMER, 'role' => 'user', 'content' => 'mensaje anterior']);

        cxJob($this->store, 'Hola');

        expect(cxLastTo(CX_CUSTOMER))->not->toContain('BIENVENIDA-TIENDA');
    });

    it('belongs to each store: another store greets with its own, or not at all', function () {
        cxJob($this->store, 'Hola');

        $other = makeStore(['conversation_mode' => Store::MODE_KNOWLEDGE, 'payment_instructions' => 'Nequi 300', 'welcome_message' => 'BIENVENIDA-OTRA']);
        cxAstrology($other);
        cxJob($other, 'Hola');

        $silent = makeStore(['conversation_mode' => Store::MODE_KNOWLEDGE, 'payment_instructions' => 'Nequi 300']);
        cxAstrology($silent);
        cxJob($silent, 'Hola');

        $texts = cxTextsTo(CX_CUSTOMER);
        expect($texts[0])->toStartWith('BIENVENIDA-TIENDA')
            ->and($texts[1])->toStartWith('BIENVENIDA-OTRA')->not->toContain('BIENVENIDA-TIENDA')
            ->and($texts[2])->toBe('¿Cuál es tu fecha de nacimiento? (día/mes/año)');
    });

    it('precedes a free sample given on first contact, also when its first send failed', function () {
        cxBirthDate($this->store);
        $this->fake['down_for'] = CX_CUSTOMER;

        expect(fn () => cxJob($this->store, 'Quiero mi Lectura Astral', wamid: 'wamid.in.first'))->toThrow(RuntimeException::class);
        expect(SampleDelivery::count())->toBe(0);

        $this->fake['down_for'] = null;
        cxJob($this->store, 'Quiero mi Lectura Astral', wamid: 'wamid.in.first'); // the queue's retry

        $delivered = WhatsAppMessage::where('role', 'assistant')->where('customer_phone', CX_CUSTOMER)->sole()->content;
        expect($delivered)->toStartWith("BIENVENIDA-TIENDA\nSoy el asistente.\n\nRespuesta generada.")
            ->and(SampleDelivery::count())->toBe(1);
    });
});

describe('free sample grounded in the topic guide', function () {
    beforeEach(function () {
        $this->store = makeStore(['conversation_mode' => Store::MODE_KNOWLEDGE, 'payment_instructions' => 'Nequi 300']);
        $this->astro = cxAstrology($this->store);
    });

    it('lets the guide choose the sections even when words of the message match another title', function () {
        cxBirthDate($this->store);

        cxJob($this->store, 'Quiero mi muestra de la Lectura Astral, ¿soy Aries?');

        expect(cxPlannerInputs())->toHaveCount(1)
            ->and(cxAnswerPrompts()[0])->toContain('PISCIS-TEXTO')->toContain('MUESTRA-ASTRO')->not->toContain('ARIES-TEXTO')
            ->and(SampleDelivery::count())->toBe(1);
    });

    it('lets the guide choose the sections of the paid service too', function () {
        cxBirthDate($this->store);
        Subscription::create(['customer_id' => app(CustomerMemory::class)->customerFor($this->store, CX_CUSTOMER)->id, 'product_id' => $this->astro->id, 'started_at' => now(), 'expires_at' => now()->addDays(30)]);
        // A guide that honours an express question about another entity.
        $this->fake['plan'] = '{"needs_knowledge": true, "sections": ["Aries"]}';

        cxJob($this->store, '¿Cómo es Aries?');

        expect(cxPlannerInputs())->toHaveCount(1)
            ->and(cxAnswerPrompts()[0])->toContain('ARIES-TEXTO')->toContain('SERVICIO-ASTRO')->not->toContain('PISCIS-TEXTO');
    });

    it('grounds each topic\'s sample in its own sources, guide and customer data', function () {
        $nutrition = cxNutrition($this->store);
        cxBirthDate($this->store);

        cxJob($this->store, 'Quiero la Asesoría Nutricional para perder grasa');
        expect(cxLastTo(CX_CUSTOMER))->toBe('¿Cuál es tu objetivo?');

        cxJob($this->store, 'Ganar masa muscular');
        cxJob($this->store, 'Quiero mi muestra de la Lectura Astral');

        [$nutriInput, $astroInput] = cxPlannerInputs();
        [$nutriPrompt, $astroPrompt] = cxAnswerPrompts();

        expect($nutriInput)->toContain('GUIA-NUTRI')->toContain('Ganar masa muscular')->not->toContain('GUIA-ASTRO')->not->toContain('Piscis')
            ->and($nutriPrompt)->toContain('MASA-TEXTO')->toContain('MUESTRA-NUTRI')
            ->not->toContain('GRASA-TEXTO')->not->toContain('PISCIS-TEXTO')->not->toContain('ARIES-TEXTO')
            ->and($astroInput)->toContain('GUIA-ASTRO')->not->toContain('GUIA-NUTRI')->not->toContain('Ganar masa muscular')
            ->and($astroPrompt)->toContain('PISCIS-TEXTO')->not->toContain('MASA-TEXTO')->not->toContain('INSTRUCCION-NUTRI')
            ->and(SampleDelivery::where('product_id', $nutrition->id)->count())->toBe(1)
            ->and(SampleDelivery::where('product_id', $this->astro->id)->count())->toBe(1);
    });

    it('answers the original query once the customer gives the data it needed', function () {
        cxJob($this->store, '¿Qué me depara el amor este mes?');

        expect(Conversation::sole()->pending_state['origin_message'])->toBe('¿Qué me depara el amor este mes?');

        cxJob($this->store, 'no sé'); // not a date: asked again, the query is kept
        expect(cxLastTo(CX_CUSTOMER))->toContain('¿Cuál es tu fecha de nacimiento?')
            ->and(Conversation::sole()->pending_state['origin_message'])->toBe('¿Qué me depara el amor este mes?');

        cxJob($this->store, '15/03/1990');

        expect(cxPlannerInputs()[0])->toContain('¿Qué me depara el amor este mes?')->toContain('- fecha de nacimiento: 1990-03-15')
            ->and(cxAnswerPrompts()[0])->toContain("### CONSULTA DEL CLIENTE\n")->toContain('¿Qué me depara el amor este mes?')->toContain('PISCIS-TEXTO')
            ->and(Conversation::sole()->pending_state)->toBeNull()
            ->and(SampleDelivery::count())->toBe(1);
    });

    it('adds no original query when the message itself is the query', function () {
        cxBirthDate($this->store);

        cxJob($this->store, 'Quiero mi Lectura Astral');

        expect(cxAnswerPrompts()[0])->not->toContain('CONSULTA DEL CLIENTE');
    });

    it('withholds the sample, without consuming it, when the guide finds no section', function () {
        cxBirthDate($this->store);
        $this->fake['plan'] = '{"needs_knowledge": true, "sections": []}';

        cxJob($this->store, 'Quiero mi Lectura Astral');

        expect(cxAnswerPrompts())->toBe([])
            ->and(SampleDelivery::count())->toBe(0)
            ->and(cxLastTo(CX_CUSTOMER))->toContain('Intenta de nuevo en unos minutos');
    });

    it('closes the sample with the product\'s configured text, followed by its plans', function () {
        $this->astro->update(['sample_closing_message' => 'CIERRE-CONFIGURADO: ¿quieres la lectura completa?']);
        cxBirthDate($this->store);

        cxJob($this->store, 'Quiero mi Lectura Astral');

        expect(cxLastTo(CX_CUSTOMER))->toBe("Respuesta generada.\n\nCIERRE-CONFIGURADO: ¿quieres la lectura completa?\n\nPlanes de «Lectura Astral»:\n1. Mensual: \$20.000 COP por 30 días\n\nEscribe «pagar» para suscribirte.");
    });

    it('keeps the standard closing when none is configured', function () {
        cxBirthDate($this->store);

        cxJob($this->store, 'Quiero mi Lectura Astral');

        expect(cxLastTo(CX_CUSTOMER))->toContain("Respuesta generada.\n\nEsta fue tu muestra gratuita de «Lectura Astral».\n\nPlanes de «Lectura Astral»:");
    });
});

describe('payment confirmation', function () {
    beforeEach(function () {
        config(['botinker.domains' => ['oracle' => OracleTestDomain::class]]);
        Storage::fake('local');
        Storage::fake(PaymentReceipt::DISK);

        $this->store = makeStore([
            'conversation_mode' => Store::MODE_KNOWLEDGE, 'payment_instructions' => 'Nequi 300', 'alert_phone' => CX_ADMIN,
        ]);
        $this->product = Product::create([
            'store_id' => $this->store->id, 'domain_key' => 'oracle', 'name' => 'Lectura', 'description' => 'x', 'price' => 1, 'stock' => 1,
            'access_mode' => Product::ACCESS_SUBSCRIPTION, 'ai_sales_strategy' => 'SERVICIO',
        ]);
        $this->plan = Plan::create(['product_id' => $this->product->id, 'name' => 'Mensual', 'price' => 20000]);
        $this->customer = app(CustomerMemory::class)->customerFor($this->store, CX_CUSTOMER);
        Conversation::create(['store_id' => $this->store->id, 'customer_phone' => CX_CUSTOMER, 'last_session_at' => now()]);

        $this->payment = Payment::start($this->customer, $this->plan, now()->addHours(48));
        ProcessWhatsAppMessage::dispatchSync($this->store->fresh(), CX_CUSTOMER, null, 'wamid.in.receipt', 'image', 'media-1');
        $this->payment->refresh();
    });

    it('adds the product\'s activation message to the confirmation sent as text', function () {
        $this->product->update(['activation_message' => "ACTIVACION: pregúntame por tu carta.\nEjemplo: «¿Qué dice mi semana?»"]);

        cxJob($this->store, "Sí P-{$this->payment->id}", CX_ADMIN);

        $expires = Subscription::sole()->expires_at->format('d/m/Y');
        expect(cxLastTo(CX_CUSTOMER))->toBe("✅ Tu pago de «Lectura» fue confirmado. Tu acceso está activo hasta el {$expires}.\n\nACTIVACION: pregúntame por tu carta.\nEjemplo: «¿Qué dice mi semana?»");
    });

    it('keeps the confirmation unchanged without an activation message', function () {
        cxJob($this->store, "Sí P-{$this->payment->id}", CX_ADMIN);

        expect(cxLastTo(CX_CUSTOMER))->toBe('✅ Tu pago de «Lectura» fue confirmado. Tu acceso está activo hasta el '.Subscription::sole()->expires_at->format('d/m/Y').'.');
    });

    it('never puts the activation message into a Meta template', function () {
        $this->product->update(['activation_message' => 'ACTIVACION']);
        Conversation::where('customer_phone', CX_CUSTOMER)->update(['last_session_at' => now()->subDays(2)]);
        WhatsAppTemplate::create([
            'store_id' => $this->store->id, 'name' => 'resultado_pago', 'body_preview' => 'x', 'language' => 'es_CO', 'type' => 'utility',
            'parameters_map' => ['1' => 'product_name', '2' => 'result', '3' => 'expires_at'], 'event_key' => WhatsAppTemplate::EVENT_PAYMENT_RESULT,
        ]);

        cxJob($this->store, "Sí P-{$this->payment->id}", CX_ADMIN);

        $template = collect(cxSentTo(CX_CUSTOMER))->firstWhere('type', 'template');
        expect($template['template']['name'])->toBe('resultado_pago')
            ->and(json_encode($template, JSON_UNESCAPED_UNICODE))->not->toContain('ACTIVACION');
    });

    it('tells the customer even when the reply to the admin fails, and the retry changes nothing', function () {
        $failAdmin = true;
        WhatsAppMessage::creating(function (WhatsAppMessage $message) use (&$failAdmin) {
            if ($failAdmin && $message->customer_phone === CX_ADMIN && $message->role === 'assistant') {
                throw new RuntimeException('history unavailable');
            }
        });

        expect(fn () => cxJob($this->store, "Sí P-{$this->payment->id}", CX_ADMIN))->toThrow(RuntimeException::class);

        expect($this->payment->fresh()->status)->toBe(Payment::STATUS_CONFIRMED)
            ->and(cxLastTo(CX_CUSTOMER))->toStartWith('✅ Tu pago de «Lectura» fue confirmado.');

        $failAdmin = false;
        $customerTexts = count(cxTextsTo(CX_CUSTOMER));
        cxJob($this->store, "Sí P-{$this->payment->id}", CX_ADMIN); // the admin (or the queue) tries again

        expect(count(cxTextsTo(CX_CUSTOMER)))->toBe($customerTexts)
            ->and(cxLastTo(CX_ADMIN))->toContain('ya estaba confirmado')
            ->and(Subscription::count())->toBe(1);
    });

    it('keeps the confirmation and the admin reply when the customer cannot be reached', function () {
        $this->fake['down_for'] = CX_CUSTOMER;

        cxJob($this->store, "Sí P-{$this->payment->id}", CX_ADMIN);

        expect($this->payment->fresh()->status)->toBe(Payment::STATUS_CONFIRMED)
            ->and($this->logged)->toContain('PAYMENT_RESULT_NOT_DELIVERED')
            ->and(cxLastTo(CX_ADMIN))->toStartWith("✅ P-{$this->payment->id} confirmado.");
    });

    it('rejects through the template without sending an empty parameter', function () {
        Conversation::where('customer_phone', CX_CUSTOMER)->update(['last_session_at' => now()->subDays(2)]);
        WhatsAppTemplate::create([
            'store_id' => $this->store->id, 'name' => 'resultado_pago', 'body_preview' => 'x', 'language' => 'es_CO', 'type' => 'utility',
            'parameters_map' => ['1' => 'product_name', '2' => 'result', '3' => 'expires_at'], 'event_key' => WhatsAppTemplate::EVENT_PAYMENT_RESULT,
        ]);

        cxJob($this->store, "No P-{$this->payment->id}", CX_ADMIN);

        $template = collect(cxSentTo(CX_CUSTOMER))->firstWhere('type', 'template');
        expect(collect($template['template']['components'][0]['parameters'])->pluck('text')->all())->toBe(['Lectura', 'no confirmado', '-'])
            ->and($this->payment->fresh()->status)->toBe(Payment::STATUS_REJECTED);
    });
});
