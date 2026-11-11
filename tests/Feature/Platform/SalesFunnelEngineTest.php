<?php

/*
 * The sales funnel engine: the Core interpreting a funnel published as data,
 * through the same pipeline, access, free replies and commerce as everything
 * else. All products and texts here are test configuration, as an operator
 * would publish them; the model and WhatsApp are simulated, so these tests
 * prove what the model receives and what the Core does with its answers, not
 * how a real model words them.
 */

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Conversation;
use App\Models\ConversationFunnelState;
use App\Models\Customer;
use App\Models\KnowledgeSource;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Product;
use App\Models\SalesFunnel;
use App\Models\SampleDelivery;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\Topic;
use App\Models\WhatsAppMessage;
use App\Platform\Commerce\AccessGate;
use App\Platform\Commerce\CommercialClaims;
use App\Platform\Commerce\OpeningMessage;
use App\Platform\Commerce\PaymentReview;
use App\Platform\Commerce\SampleLedger;
use App\Platform\Funnel\FunnelDefinition;
use App\Platform\Funnel\FunnelStates;
use App\Platform\Funnel\SalesFunnelVersions;
use App\Platform\Knowledge\KnowledgeAccess;
use App\Platform\Knowledge\KnowledgeIngestor;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

const FE_PHONE = '573004445566';

beforeEach(function () {
    $this->freezeSecond();

    $this->replies = [];
    $this->defaultReply = 'RESPUESTA-DEL-MODELO';
    $this->sections = [];
    $this->modelDown = false;
    $this->whatsAppDown = false;

    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'api.openai.com')) {
            if (str_contains($request->data()['messages'][0]['content'], 'Eres un selector de conocimiento')) {
                return Http::response(['choices' => [['message' => ['content' => json_encode(['needs_knowledge' => true, 'sections' => $this->sections])]]]]);
            }

            if ($this->modelDown) {
                return Http::response(['error' => 'upstream'], 500);
            }

            return Http::response(['choices' => [['message' => ['content' => array_shift($this->replies) ?? $this->defaultReply]]]]);
        }

        return $this->whatsAppDown
            ? Http::response(['error' => 'down'], 500)
            : Http::response(['messages' => [['id' => 'wamid.out.'.Str::random(8)]]]);
    });

    $this->store = feStore();
    $this->product = feProduct($this->store);
    $this->versions = app(SalesFunnelVersions::class);
    $this->states = app(FunnelStates::class);
});

function feStore(array $overrides = []): Store
{
    return makeStore(array_merge([
        'conversation_mode' => Store::MODE_KNOWLEDGE,
        'payment_instructions' => 'INSTRUCCIONES-PAGO',
        'welcome_message' => 'BIENVENIDA-TIENDA',
        'system_prompt' => 'PERSONA-TIENDA',
    ], $overrides));
}

function feProduct(Store $store, array $overrides = [], string $topic = 'tema', array $plans = [['Mensual', 20000, 30]]): Product
{
    Topic::firstOrCreate(['store_id' => $store->id, 'key' => $topic], ['label' => 'Tema '.$topic, 'instructions' => 'INSTRUCCIONES-TEMA-'.$topic]);

    $product = Product::create(array_merge([
        'store_id' => $store->id, 'domain_key' => $topic, 'name' => 'Asesor', 'description' => 'DESCRIPCION-PRODUCTO', 'price' => 1, 'stock' => 1,
        'access_mode' => Product::ACCESS_SUBSCRIPTION,
        'ai_sales_strategy' => 'SERVICIO-RESERVADO',
        'faq_context' => 'FAQ-PRODUCTO',
        'commercial_instructions' => 'COMERCIAL-PRODUCTO',
    ], $overrides));

    foreach ($plans as [$name, $price, $days]) {
        Plan::create(['product_id' => $product->id, 'name' => $name, 'price' => $price, 'duration_days' => $days]);
    }

    return $product;
}

function feStage(string $key, array $overrides = []): array
{
    return array_merge([
        'key' => $key, 'type' => 'ai', 'goal' => "OBJETIVO-{$key}", 'instructions' => "INSTRUCCIONES-{$key}",
        'context' => ['description', 'faq', 'topic', 'commercial_resources'],
    ], $overrides);
}

function feDefinition(array $stages, array $overrides = []): array
{
    return array_merge(['name' => 'Embudo', 'goal' => 'OBJETIVO-EMBUDO', 'stages' => $stages], $overrides);
}

function feIntents(): array
{
    return [
        ['key' => 'comprar', 'description' => 'Quiere adquirir el servicio'],
        ['key' => 'objecion', 'description' => 'Duda del valor'],
    ];
}

function feJson(string $reply, ?string $intent = null, float $confidence = 0.0): string
{
    return json_encode(['reply' => $reply, 'intent' => $intent, 'confidence' => $confidence]);
}

function feJob(Store $store, string $body, string $phone = FE_PHONE): void
{
    ProcessWhatsAppMessage::dispatchSync($store->fresh(), $phone, $body, 'wamid.in.'.uniqid(), 'text', null);
    test()->travel(1)->seconds();
}

function feTexts(): array
{
    return array_map(fn ($request) => $request->data()['text']['body'], sentWhatsAppTexts());
}

function feLast(): string
{
    $texts = feTexts();

    return end($texts);
}

/** The calls that answer the customer, without the selector's. */
function feAnswers(): array
{
    return Http::recorded(fn (Request $request) => str_contains($request->url(), 'api.openai.com')
        && ! str_contains($request->data()['messages'][0]['content'], 'Eres un selector de conocimiento'))
        ->map(fn ($pair) => $pair[0])->values()->all();
}

function fePrompt(): string
{
    $answers = feAnswers();

    return end($answers)->data()['messages'][0]['content'];
}

function feSelectors(): array
{
    return Http::recorded(fn (Request $request) => str_contains($request->url(), 'api.openai.com')
        && str_contains($request->data()['messages'][0]['content'], 'Eres un selector de conocimiento'))
        ->map(fn ($pair) => collect($pair[0]->data()['messages'])->last()['content'])->values()->all();
}

function feConversation(Store $store, string $phone = FE_PHONE): Conversation
{
    return Conversation::where('store_id', $store->id)->where('customer_phone', $phone)->sole();
}

function feState(Store $store, Product $product, string $phone = FE_PHONE): ?ConversationFunnelState
{
    return app(FunnelStates::class)->find(feConversation($store, $phone), $product);
}

function feCustomer(Store $store, string $phone = FE_PHONE): Customer
{
    return Customer::firstOrCreate(['store_id' => $store->id, 'customer_phone' => $phone]);
}

function feTurns(Product $product): ?int
{
    return SampleDelivery::where('product_id', $product->id)->value('turns_used');
}

function feSource(Store $store, Product $product, string $title, string $marker, string $purpose, string $delivery = KnowledgeSource::DELIVERY_REFERENCE): void
{
    app(KnowledgeIngestor::class)->ingest($store, $product->domain_key, $product, 'Fuente', 'markdown', "# {$title}\n\n{$marker}", origin: 'origen-'.Str::random(6), purpose: $purpose, delivery: $delivery);
}

describe('validating a definition', function () {
    it('accepts a complete definition', function () {
        $definition = FunnelDefinition::fromArray(feDefinition([
            feStage('dudas', ['rules' => [
                ['when' => ['phrase' => ['Lo quiero']], 'then' => ['action' => 'start_payment']],
                ['when' => ['intent' => 'objecion', 'min_confidence' => 0.6], 'then' => ['action' => 'go_to', 'stage' => 'aviso']],
            ], 'uses_free_replies' => true, 'max_interactions' => 3, 'on_limit' => 'cierre']),
            feStage('aviso', ['type' => 'message', 'text' => 'Texto', 'next' => 'cierre']),
            feStage('cierre'),
        ], ['intents' => feIntents(), 'free_replies' => ['limit' => 2, 'on_exhausted' => 'cierre'], 'offer_cta' => 'Escribe X']));

        expect($definition->startStage)->toBe('dudas')
            ->and($definition->freeRepliesLimit)->toBe(2)
            ->and($definition->stage('dudas')->rules[1]['min_confidence'])->toBe(0.6);
    });

    it('rejects what the engine cannot execute', function (array $definition, string $message) {
        expect(fn () => FunnelDefinition::fromArray($definition))->toThrow(InvalidArgumentException::class, $message);
    })->with([
        'no name' => [['goal' => 'x', 'stages' => [feStage('a')]], 'needs a name'],
        'no goal' => [['name' => 'x', 'stages' => [feStage('a')]], 'needs a goal'],
        'no stages' => [feDefinition([]), 'list of stages'],
        'repeated stage' => [feDefinition([feStage('a'), feStage('a')]), 'different keys'],
        'unknown stage type' => [feDefinition([feStage('a', ['type' => 'magia'])]), 'unsupported type'],
        'stage without goal' => [feDefinition([feStage('a', ['goal' => ''])]), 'needs a goal'],
        'stage without instructions' => [feDefinition([feStage('a', ['instructions' => ' '])]), 'needs instructions'],
        'message without text' => [feDefinition([feStage('a', ['type' => 'message', 'next' => 'b']), feStage('b')]), 'needs its text'],
        'message without next' => [feDefinition([feStage('a', ['type' => 'message', 'text' => 'x'])]), 'stage that follows'],
        'message following itself' => [feDefinition([feStage('a', ['type' => 'message', 'text' => 'x', 'next' => 'a'])]), 'cannot follow itself'],
        'next to nowhere' => [feDefinition([feStage('a', ['next' => 'b'])]), 'does not exist'],
        'unknown start' => [feDefinition([feStage('a')], ['start_stage' => 'b']), 'start_stage'],
        'subscribers knowledge in context' => [feDefinition([feStage('a', ['context' => ['knowledge']])]), 'not allowed'],
        'service instructions in context' => [feDefinition([feStage('a', ['context' => ['sales_strategy']])]), 'not allowed'],
        'unsupported action' => [feDefinition([feStage('a', ['rules' => [['when' => ['any_reply' => true], 'then' => ['action' => 'grant_access']]]])]), 'unsupported action'],
        'go_to without target' => [feDefinition([feStage('a', ['rules' => [['when' => ['any_reply' => true], 'then' => ['action' => 'go_to']]]])]), 'target stage'],
        'target to nowhere' => [feDefinition([feStage('a', ['rules' => [['when' => ['any_reply' => true], 'then' => ['action' => 'go_to', 'stage' => 'b']]]])]), 'does not exist'],
        'unknown condition' => [feDefinition([feStage('a', ['rules' => [['when' => ['si_es_martes' => true], 'then' => ['action' => 'stay']]]])]), 'supported condition'],
        'two conditions' => [feDefinition([feStage('a', ['rules' => [['when' => ['any_reply' => true, 'plan_named' => true], 'then' => ['action' => 'stay']]]])]), 'exactly one'],
        'undeclared intent' => [feDefinition([feStage('a', ['rules' => [['when' => ['intent' => 'comprar'], 'then' => ['action' => 'stay']]]])]), 'does not declare'],
        'empty phrase' => [feDefinition([feStage('a', ['rules' => [['when' => ['phrase' => ['¡!']], 'then' => ['action' => 'stay']]]])]), 'says nothing'],
        'bad confidence' => [feDefinition([feStage('a', ['rules' => [['when' => ['intent' => 'comprar', 'min_confidence' => 2], 'then' => ['action' => 'stay']]]])], ['intents' => feIntents()]), 'between 0 and 1'],
        'free replies without a limit' => [feDefinition([feStage('a', ['uses_free_replies' => true])]), 'gives none'],
        'exhausted to nowhere' => [feDefinition([feStage('a')], ['free_replies' => ['limit' => 1, 'on_exhausted' => 'b']]), 'does not exist'],
        'negative limit' => [feDefinition([feStage('a')], ['free_replies' => ['limit' => -1]]), 'whole number'],
        'interactions without on_limit' => [feDefinition([feStage('a', ['max_interactions' => 2])]), 'needs on_limit'],
        'zero interactions' => [feDefinition([feStage('a', ['max_interactions' => 0, 'on_limit' => 'a'])]), 'positive'],
    ]);

    it('refuses to publish an invalid definition', function () {
        expect(fn () => $this->versions->publish($this->product, feDefinition([feStage('a', ['context' => ['knowledge']])])))->toThrow(InvalidArgumentException::class)
            ->and(SalesFunnel::count())->toBe(0);
    });
});

describe('starting and running a published funnel', function () {
    it('starts at the first stage, with its goal and instructions, and keeps the state', function () {
        $funnel = $this->versions->publish($this->product, feDefinition([feStage('primera'), feStage('segunda')]));

        feJob($this->store, 'Hola, ¿de qué se trata?');

        expect(fePrompt())->toContain('OBJETIVO-EMBUDO')->toContain('OBJETIVO-primera')->toContain('INSTRUCCIONES-primera')->not->toContain('OBJETIVO-segunda')
            ->and(feLast())->toBe("BIENVENIDA-TIENDA\n\nRESPUESTA-DEL-MODELO")
            ->and(feState($this->store, $this->product))->stage->toBe('primera')->sales_funnel_id->toBe($funnel->id)->stage_interactions->toBe(1);
    });

    it('resumes each conversation where it was, per product', function () {
        $this->versions->publish($this->product, feDefinition([
            feStage('primera', ['rules' => [['when' => ['any_reply' => true], 'then' => ['action' => 'go_to', 'stage' => 'segunda']]]]),
            feStage('segunda'),
        ]));

        feJob($this->store, 'Primer mensaje');
        feJob($this->store, 'Segundo mensaje');
        feJob($this->store, 'Otro cliente', '573009990000');

        expect(feState($this->store, $this->product)->stage)->toBe('segunda')
            ->and(feState($this->store, $this->product)->stage_interactions)->toBe(1)
            ->and(feState($this->store, $this->product, '573009990000')->stage)->toBe('segunda')
            ->and(feState($this->store, $this->product, '573009990000')->stage_interactions)->toBe(0);
    });

    it('starts at the configured stage', function () {
        $this->versions->publish($this->product, feDefinition([feStage('primera'), feStage('segunda')], ['start_stage' => 'segunda']));

        feJob($this->store, 'Hola');

        expect(fePrompt())->toContain('OBJETIVO-segunda');
    });

    it('sends a single message per customer message, and nothing more when the customer stops', function () {
        $this->versions->publish($this->product, feDefinition([feStage('primera')]));

        feJob($this->store, 'Hola');
        $this->travel(3)->days();
        $this->artisan('schedule:run');

        expect(feTexts())->toHaveCount(1);
    });

    it('moves a conversation on a stage a new version no longer has to the start, keeping its counter', function () {
        $this->versions->publish($this->product, feDefinition([
            feStage('vieja', ['rules' => [['when' => ['any_reply' => true], 'then' => ['action' => 'stay']]]]),
        ], ['free_replies' => ['limit' => 2]]));
        feJob($this->store, 'Hola');

        $new = $this->versions->publish($this->product, feDefinition([feStage('nueva')], ['free_replies' => ['limit' => 2]]));
        feJob($this->store, 'Sigo aquí');

        expect(fePrompt())->toContain('OBJETIVO-nueva')
            ->and(feState($this->store, $this->product))->stage->toBe('nueva')->sales_funnel_id->toBe($new->id);
    });

    it('keeps the stage on a new version that still has it', function () {
        $this->versions->publish($this->product, feDefinition([feStage('a'), feStage('b')], ['start_stage' => 'b']));
        feJob($this->store, 'Hola');

        $this->versions->publish($this->product, feDefinition([feStage('a'), feStage('b', ['instructions' => 'NUEVAS-INSTRUCCIONES'])]));
        feJob($this->store, 'Sigo aquí');

        expect(fePrompt())->toContain('NUEVAS-INSTRUCCIONES')->and(feState($this->store, $this->product)->stage_interactions)->toBe(2);
    });
});

describe('the opening message', function () {
    beforeEach(function () {
        $this->product->update(['opening_message' => "TEXTO-INICIAL exacto\n\n{planes}\n\n*{producto}*", 'opening_triggers' => ['Quiero conocer el asesor']]);
        $this->opening = "TEXTO-INICIAL exacto\n\n1. Mensual: \$20.000 COP por 30 días\n\n*Asesor*";
    });

    it('goes out exactly as configured, without the model or the welcome, and fills the plans from the catalog', function () {
        $this->versions->publish($this->product, feDefinition([feStage('dudas')]));

        feJob($this->store, 'Quiero conocer el asesor');

        expect(feTexts())->toBe([$this->opening])
            ->and(feAnswers())->toHaveCount(0)
            ->and(feState($this->store, $this->product))->stage->toBe('dudas')->stage_interactions->toBe(0);
    });

    it('tolerates greetings and courtesies around a trigger', function (string $message) {
        $this->versions->publish($this->product, feDefinition([feStage('dudas')]));

        feJob($this->store, $message);

        expect(feLast())->toBe($this->opening);
    })->with(['Hola! Quiero conocer el asesor', 'Buenas tardes, quiero conocer el asesor por favor', '¡Quiero conocer el asesor, gracias!']);

    it('needs the whole message: another question is not the trigger', function () {
        $this->versions->publish($this->product, feDefinition([feStage('dudas')]));

        feJob($this->store, 'Quiero conocer el asesor, ¿qué incluye?');

        expect(feLast())->not->toContain('TEXTO-INICIAL')->and(feAnswers())->toHaveCount(1);
    });

    it('is not sent again once the funnel started; only an explicit reset does', function () {
        $this->versions->publish($this->product, feDefinition([feStage('dudas')]));

        feJob($this->store, 'Quiero conocer el asesor');
        feJob($this->store, 'Quiero conocer el asesor');
        expect(feLast())->toBe('RESPUESTA-DEL-MODELO');

        $this->states->reset(feConversation($this->store), $this->product);
        feJob($this->store, 'Quiero conocer el asesor');

        expect(feLast())->toBe($this->opening);
    });

    it('spends a free reply by default, and none when configured or when the funnel gives none', function (array $config, ?int $turns) {
        $this->versions->publish($this->product, feDefinition([feStage('dudas')], $config));

        feJob($this->store, 'Quiero conocer el asesor');

        expect(feLast())->toBe($this->opening)->and(feTurns($this->product))->toBe($turns);
    })->with([
        'by default' => [['free_replies' => ['limit' => 3]], 1],
        'configured not to' => [['free_replies' => ['limit' => 3], 'opening_consumes_free_reply' => false], null],
        'the funnel gives none' => [['free_replies' => ['limit' => 0]], null],
    ]);

    it('goes out without recovering anything for a customer who already spent the free replies', function () {
        $this->versions->publish($this->product, feDefinition([feStage('dudas', ['uses_free_replies' => true])], ['free_replies' => ['limit' => 2]]));
        SampleDelivery::create(['customer_id' => feCustomer($this->store)->id, 'product_id' => $this->product->id, 'turns_used' => 2]);

        feJob($this->store, 'Quiero conocer el asesor');

        expect(feLast())->toBe($this->opening)->and(feTurns($this->product))->toBe(2);
    });

    it('gives the free reply back when WhatsApp did not take it, and the state stays', function () {
        $this->versions->publish($this->product, feDefinition([feStage('dudas')], ['free_replies' => ['limit' => 3]]));
        $this->whatsAppDown = true;

        expect(fn () => ProcessWhatsAppMessage::dispatchSync($this->store->fresh(), FE_PHONE, 'Quiero conocer el asesor', 'wamid.in.x'))->toThrow(RuntimeException::class)
            ->and(feTurns($this->product))->toBeNull()
            ->and(ConversationFunnelState::count())->toBe(0);
    });

    it('is evaluated after identifying the product, asking when it is ambiguous', function () {
        $this->versions->publish($this->product, feDefinition([feStage('dudas')]));
        feProduct($this->store, ['name' => 'Otro'], 'otro');

        feJob($this->store, 'Quiero conocer el asesor');
        expect(feLast())->toBe($this->opening);

        feJob($this->store, 'Tengo una consulta general', '573001230000');
        expect(feLast())->toContain('¿Sobre cuál de estas opciones')->and(feAnswers())->toHaveCount(0);

        feJob($this->store, '1', '573001230000');
        expect(feAnswers())->toHaveCount(1);
    });

    it('answers the trigger behind a clarification', function () {
        $other = feProduct($this->store, ['name' => 'Otro', 'opening_message' => 'INICIAL-OTRO', 'opening_triggers' => ['Tengo una consulta general']], 'otro');
        $this->versions->publish($other, feDefinition([feStage('dudas')]));

        feJob($this->store, 'Tengo una consulta general');
        feJob($this->store, '2');

        expect(feLast())->toBe('INICIAL-OTRO');
    });

    it('normalizes greetings without erasing a text that is only one', function () {
        expect(OpeningMessage::normalize('¡Hola! Quiero algo, por favor'))->toBe('quiero algo')
            ->and(OpeningMessage::normalize('Hola'))->toBe('hola');
    });
});

describe('conversation', function () {
    it('keeps answering questions and objections without forcing a transition', function () {
        $this->versions->publish($this->product, feDefinition([
            feStage('dudas', ['rules' => [['when' => ['intent' => 'comprar'], 'then' => ['action' => 'go_to', 'stage' => 'cierre']]]]),
            feStage('cierre'),
        ], ['intents' => feIntents()]));
        $this->replies = [feJson('Te explico cómo funciona.'), feJson('Entiendo tu duda sobre el valor.', 'objecion', 0.95), feJson('Volviendo a lo tuyo.')];

        feJob($this->store, '¿Cómo funciona?');
        feJob($this->store, 'Me parece caro');
        feJob($this->store, 'Por cierto, ¿atienden los domingos?');

        expect(feState($this->store, $this->product))->stage->toBe('dudas')->stage_interactions->toBe(3)
            ->and(feTexts())->toHaveCount(3)
            ->and(feLast())->toBe('Volviendo a lo tuyo.');
    });

    it('gives the model the whole conversation and the rules for conversing', function () {
        $this->versions->publish($this->product, feDefinition([feStage('dudas')]));
        $this->replies = ['¿Dónde vives?'];

        feJob($this->store, 'Hola');
        feJob($this->store, 'Vivo en Cali');

        $answers = feAnswers();
        $messages = collect(end($answers)->data()['messages']);

        expect($messages->pluck('content')->implode('|'))->toContain('Hola')->toContain('¿Dónde vives?')->toContain('Vivo en Cali')
            ->and(fePrompt())->toContain(config('botinker.sales_funnels.conversation_rules'));
    });

    it('moves only when a rule says so, with the reply already given', function () {
        $this->versions->publish($this->product, feDefinition([
            feStage('dudas', ['rules' => [['when' => ['any_reply' => true], 'then' => ['action' => 'go_to', 'stage' => 'cierre']]]]),
            feStage('cierre'),
        ]));

        feJob($this->store, 'Hola');

        expect(feAnswers())->toHaveCount(1)->and(fePrompt())->toContain('OBJETIVO-dudas')
            ->and(feState($this->store, $this->product))->stage->toBe('cierre')->stage_interactions->toBe(0);
    });

    it('moves on when a stage reaches its limit of interactions', function () {
        $this->versions->publish($this->product, feDefinition([
            feStage('dudas', ['max_interactions' => 2, 'on_limit' => 'cierre']),
            feStage('cierre'),
        ]));

        feJob($this->store, 'Uno');
        expect(feState($this->store, $this->product)->stage)->toBe('dudas');

        feJob($this->store, 'Dos');
        expect(feState($this->store, $this->product))->stage->toBe('cierre')->stage_interactions->toBe(0);
    });

    it('sends a message stage in the same reply, never as a second message', function () {
        $this->versions->publish($this->product, feDefinition([
            feStage('dudas', ['rules' => [['when' => ['any_reply' => true], 'then' => ['action' => 'go_to', 'stage' => 'aviso']]]]),
            feStage('aviso', ['type' => 'message', 'text' => 'TEXTO-LITERAL', 'next' => 'cierre']),
            feStage('cierre'),
        ]));
        $this->store->update(['welcome_message' => null]);

        feJob($this->store, 'Hola');

        expect(feTexts())->toBe(["RESPUESTA-DEL-MODELO\n\nTEXTO-LITERAL"])
            ->and(feState($this->store, $this->product)->stage)->toBe('cierre');
    });

    it('sends a message stage it starts on, then waits', function () {
        $this->versions->publish($this->product, feDefinition([
            feStage('pregunta', ['type' => 'message', 'text' => '¿Para qué lo necesitas?', 'next' => 'dudas']),
            feStage('dudas'),
        ]));

        feJob($this->store, 'Hola');

        expect(feTexts())->toBe(['¿Para qué lo necesitas?'])->and(feAnswers())->toHaveCount(0)
            ->and(feState($this->store, $this->product)->stage)->toBe('dudas');
    });

    it('never loops on rules going in circles', function () {
        $this->versions->publish($this->product, feDefinition([
            feStage('a', ['rules' => [['when' => ['phrase' => ['vuelta']], 'then' => ['action' => 'go_to', 'stage' => 'b']]]]),
            feStage('b', ['rules' => [['when' => ['phrase' => ['vuelta']], 'then' => ['action' => 'go_to', 'stage' => 'a']]]]),
        ]));

        feJob($this->store, 'vuelta');

        expect(feAnswers())->toHaveCount(1)->and(feTexts())->toHaveCount(1);
    });
});

describe('reading intents', function () {
    beforeEach(function () {
        $this->store->update(['welcome_message' => null]);
        $this->versions->publish($this->product, feDefinition([
            feStage('dudas', ['rules' => [
                ['when' => ['phrase' => ['Avanzar ahora']], 'then' => ['action' => 'go_to', 'stage' => 'cierre']],
                ['when' => ['intent' => 'objecion', 'min_confidence' => 0.7], 'then' => ['action' => 'go_to', 'stage' => 'objeciones']],
            ]]),
            feStage('objeciones'),
            feStage('cierre'),
        ], ['intents' => feIntents()]));
    });

    it('uses a single call for the reply and the reading, with the configured intents', function () {
        $this->replies = [feJson('Entiendo.', 'objecion', 0.9)];

        feJob($this->store, 'Me parece caro');

        expect(feAnswers())->toHaveCount(1)
            ->and(fePrompt())->toContain('FORMATO DE RESPUESTA')->toContain('objecion: Duda del valor')->toContain('comprar: Quiere adquirir el servicio')
            ->and(feLast())->toBe('Entiendo.')
            ->and(feState($this->store, $this->product)->stage)->toBe('objeciones');
    });

    it('stays when the reading is uncertain', function (string $answer, string $sent) {
        $this->replies = [$answer];

        feJob($this->store, 'Mmm no sé');

        expect(feLast())->toBe($sent)->and(feState($this->store, $this->product)->stage)->toBe('dudas');
    })->with([
        'low confidence' => [feJson('Cuéntame más.', 'objecion', 0.5), 'Cuéntame más.'],
        'no intent' => [feJson('Cuéntame más.'), 'Cuéntame más.'],
        'unknown intent' => [feJson('Cuéntame más.', 'inventada', 1.0), 'Cuéntame más.'],
        'plain text' => ['Cuéntame más.', 'Cuéntame más.'],
    ]);

    it('never sends broken JSON to the customer, and moves nothing', function () {
        $this->replies = ['{"reply": "a medias'];

        feJob($this->store, 'Hola');

        expect(feLast())->not->toContain('reply')
            ->and(feState($this->store, $this->product))->toBeNull();
    });

    it('evaluates rules that need no model first, without calling it', function () {
        feJob($this->store, 'Avanzar ahora, por favor');

        expect(fePrompt())->toContain('OBJETIVO-cierre')->not->toContain('OBJETIVO-dudas')
            ->and(feAnswers())->toHaveCount(1);
    });

    it('asks for the structured format only when the stage reads intents', function () {
        $this->versions->publish($this->product, feDefinition([feStage('simple')], ['intents' => feIntents()]));

        feJob($this->store, 'Hola');

        expect(fePrompt())->not->toContain('FORMATO DE RESPUESTA')->and(feLast())->toBe('RESPUESTA-DEL-MODELO');
    });
});

describe('what each stage may read', function () {
    beforeEach(function () {
        feSource($this->store, $this->product, 'Qué incluye', 'CONTENIDO-COMERCIAL', KnowledgeSource::PURPOSE_COMMERCIAL);
        feSource($this->store, $this->product, 'Consejo de muestra', 'CONTENIDO-MUESTRA', KnowledgeSource::PURPOSE_SAMPLE);
        feSource($this->store, $this->product, 'Lección completa', 'CONTENIDO-EXCLUSIVO', KnowledgeSource::PURPOSE_KNOWLEDGE);
        $this->sections = ['Qué incluye', 'Consejo de muestra', 'Lección completa'];
    });

    it('gives a commercial stage only the commercial content', function () {
        $this->versions->publish($this->product, feDefinition([feStage('dudas', ['context' => ['commercial_resources']])]));

        feJob($this->store, '¿Qué incluye?');

        expect(fePrompt())->toContain('CONTENIDO-COMERCIAL')->not->toContain('CONTENIDO-MUESTRA')->not->toContain('CONTENIDO-EXCLUSIVO')
            ->and(implode(' ', feSelectors()))->toContain('Qué incluye')->not->toContain('Lección completa');
    });

    it('gives a stage authorized for samples the sample, whether or not it spends free replies', function (array $stage, array $config) {
        $this->versions->publish($this->product, feDefinition([feStage('demo', ['context' => ['sample_content']] + $stage)], $config));

        feJob($this->store, 'Dame un consejo');

        expect(fePrompt())->toContain('CONTENIDO-MUESTRA')->not->toContain('CONTENIDO-COMERCIAL')->not->toContain('CONTENIDO-EXCLUSIVO');
    })->with([
        'spending' => [['uses_free_replies' => true], ['free_replies' => ['limit' => 2]]],
        'not spending' => [[], []],
    ]);

    it('gives a free reply without that context no content, even while spending', function () {
        $this->versions->publish($this->product, feDefinition([feStage('demo', ['context' => [], 'uses_free_replies' => true])], ['free_replies' => ['limit' => 2]]));

        feJob($this->store, 'Dame un consejo');

        expect(fePrompt())->not->toContain('CONTENIDO-')
            ->and(feSelectors())->toHaveCount(0)
            ->and(feTurns($this->product))->toBe(1);
    });

    it('never gives a customer without access the exclusive content, nor the service instructions', function () {
        $this->versions->publish($this->product, feDefinition([feStage('todo', ['context' => FunnelDefinition::CONTEXTS])]));

        feJob($this->store, 'Dame la lección completa');

        expect(fePrompt())->not->toContain('CONTENIDO-EXCLUSIVO')->not->toContain('SERVICIO-RESERVADO')
            ->toContain('CONTENIDO-COMERCIAL')->toContain('CONTENIDO-MUESTRA')
            ->toContain('DESCRIPCION-PRODUCTO')->toContain('FAQ-PRODUCTO')->toContain('COMERCIAL-PRODUCTO')->toContain('INSTRUCCIONES-TEMA-tema');
    });

    it('leaves out of the prompt what the stage does not ask for', function () {
        $this->versions->publish($this->product, feDefinition([feStage('minima', ['context' => []])]));

        feJob($this->store, 'Hola');

        expect(fePrompt())->not->toContain('DESCRIPCION-PRODUCTO')->not->toContain('FAQ-PRODUCTO')->not->toContain('INSTRUCCIONES-TEMA')
            ->toContain('OBJETIVO-minima');
    });

    it('gives a subscriber the service and its knowledge, outside the funnel', function () {
        $this->versions->publish($this->product, feDefinition([feStage('dudas')]));
        Subscription::create(['customer_id' => feCustomer($this->store)->id, 'product_id' => $this->product->id, 'started_at' => now()->subDay(), 'expires_at' => now()->addDays(20)]);

        feJob($this->store, 'Lección completa');

        expect(fePrompt())->toContain('SERVICIO-RESERVADO')->toContain('CONTENIDO-EXCLUSIVO')->not->toContain('PROCESO COMERCIAL')
            ->and(ConversationFunnelState::count())->toBe(0);
    });

    it('sends a literal resource as configured, after the reply', function () {
        feSource($this->store, $this->product, 'Testimonio', 'TESTIMONIO-LITERAL', KnowledgeSource::PURPOSE_COMMERCIAL, KnowledgeSource::DELIVERY_VERBATIM);
        $this->sections = ['Testimonio'];
        $this->store->update(['welcome_message' => null]);
        $this->versions->publish($this->product, feDefinition([feStage('dudas', ['context' => ['commercial_resources']])]));

        feJob($this->store, '¿Tienen testimonios?');

        expect(feLast())->toEndWith('TESTIMONIO-LITERAL')->and(fePrompt())->not->toContain('TESTIMONIO-LITERAL');
    });

    it('answers without material when the selection fails', function () {
        $this->sections = [];
        $this->versions->publish($this->product, feDefinition([feStage('dudas', ['context' => ['commercial_resources']])]));

        feJob($this->store, 'Algo que no está');

        expect(fePrompt())->not->toContain('MATERIAL AUTORIZADO')->and(feTexts())->toHaveCount(1);
    });

    it('keeps the sample source out of products without a funnel', function () {
        config(['botinker.free_interactions.max' => 2]);
        $this->product->update(['sample_instructions' => 'INSTR-MUESTRA']);

        feJob($this->store, 'Lección completa');

        expect(fePrompt())->toContain('CONTENIDO-EXCLUSIVO')->not->toContain('CONTENIDO-MUESTRA');
    });
});

describe('free replies', function () {
    it('spends only in the stages configured to, up to the funnel\'s limit', function () {
        config(['botinker.free_interactions.max' => 9]);
        $this->versions->publish($this->product, feDefinition([
            feStage('gratis', ['uses_free_replies' => true]),
            feStage('venta'),
        ], ['free_replies' => ['limit' => 2, 'on_exhausted' => 'venta']]));

        feJob($this->store, 'Uno');
        expect(feTurns($this->product))->toBe(1)->and(fePrompt())->toContain('respuesta gratuita 1 de 2');

        feJob($this->store, 'Dos');
        expect(feTurns($this->product))->toBe(2)->and(fePrompt())->toContain('respuesta gratuita 2 de 2');

        feJob($this->store, 'Tres');
        expect(feTurns($this->product))->toBe(2)
            ->and(fePrompt())->toContain('OBJETIVO-venta')->not->toContain('respuesta gratuita')
            ->and(feState($this->store, $this->product)->stage)->toBe('venta');
    });

    it('does not spend in a stage that does not use them', function () {
        $this->versions->publish($this->product, feDefinition([feStage('venta')], ['free_replies' => ['limit' => 2]]));

        feJob($this->store, 'Uno');

        expect(feTurns($this->product))->toBeNull();
    });

    it('falls to the Core\'s commercial reply when they run out with nowhere to go', function () {
        $this->versions->publish($this->product, feDefinition([feStage('gratis', ['uses_free_replies' => true])], ['free_replies' => ['limit' => 1]]));

        feJob($this->store, 'Uno');
        $calls = count(feAnswers());
        feJob($this->store, 'Dos');

        expect(count(feAnswers()))->toBe($calls)->and(feLast())->toContain('Planes de «Asesor»')->and(feTurns($this->product))->toBe(1);
    });

    it('lets a rule act on running out', function () {
        $this->versions->publish($this->product, feDefinition([
            feStage('gratis', ['uses_free_replies' => true, 'rules' => [['when' => ['free_replies_exhausted' => true], 'then' => ['action' => 'show_offer']]]]),
        ], ['free_replies' => ['limit' => 1], 'offer_cta' => 'LLAMADO-CONFIGURADO']));

        feJob($this->store, 'Uno');
        feJob($this->store, 'Dos');

        expect(feLast())->toContain('1. Mensual: $20.000 COP por 30 días')->toEndWith('LLAMADO-CONFIGURADO');
    });

    it('continues an existing counter, and never moves it by publishing, deactivating or resetting', function () {
        $sample = SampleDelivery::create(['customer_id' => feCustomer($this->store)->id, 'product_id' => $this->product->id, 'turns_used' => 2]);
        $funnel = $this->versions->publish($this->product, feDefinition([feStage('gratis', ['uses_free_replies' => true])], ['free_replies' => ['limit' => 3]]));

        feJob($this->store, 'Uno');
        expect(feTurns($this->product))->toBe(3)->and(fePrompt())->toContain('respuesta gratuita 3 de 3');

        $this->states->reset(feConversation($this->store), $this->product);
        $this->versions->restore($funnel);
        $this->versions->deactivate($this->product);

        expect($sample->fresh()->turns_used)->toBe(3);
    });

    it('gives the reply back when the model fails, and the state stays', function () {
        $this->versions->publish($this->product, feDefinition([feStage('gratis', ['uses_free_replies' => true])], ['free_replies' => ['limit' => 2]]));
        $this->modelDown = true;

        expect(fn () => feJob($this->store, 'Uno'))->toThrow(Exception::class)
            ->and(feTurns($this->product))->toBeNull()
            ->and(ConversationFunnelState::count())->toBe(0);
    });

    it('gives the reply back when WhatsApp did not take it, and the retry delivers it', function () {
        $this->versions->publish($this->product, feDefinition([feStage('gratis', ['uses_free_replies' => true])], ['free_replies' => ['limit' => 2]]));
        $this->whatsAppDown = true;

        expect(fn () => ProcessWhatsAppMessage::dispatchSync($this->store->fresh(), FE_PHONE, 'Uno', 'wamid.in.y'))->toThrow(RuntimeException::class)
            ->and(feTurns($this->product))->toBeNull()
            ->and(ConversationFunnelState::count())->toBe(0)
            ->and(WhatsAppMessage::count())->toBe(0);

        $this->whatsAppDown = false;
        ProcessWhatsAppMessage::dispatchSync($this->store->fresh(), FE_PHONE, 'Uno', 'wamid.in.y');

        expect(feTurns($this->product))->toBe(1)
            ->and(feState($this->store, $this->product)->stage_interactions)->toBe(1)
            ->and(WhatsAppMessage::where('role', 'assistant')->count())->toBe(1);
    });

    it('gives the reply back when the reply is discarded', function () {
        $this->versions->publish($this->product, feDefinition([feStage('gratis', ['uses_free_replies' => true])], ['free_replies' => ['limit' => 2]]));
        $this->defaultReply = 'Te lo dejo en $5.000 COP.';

        feJob($this->store, 'Uno');

        expect(feTurns($this->product))->toBeNull()->and(feLast())->not->toContain('5.000');
    });

    it('does not count a purchase as a free reply', function () {
        $this->versions->publish($this->product, feDefinition([
            feStage('gratis', ['uses_free_replies' => true, 'rules' => [['when' => ['intent' => 'comprar'], 'then' => ['action' => 'start_payment']]]]),
        ], ['free_replies' => ['limit' => 2], 'intents' => feIntents()]));
        $this->replies = [feJson('Claro.', 'comprar', 0.95)];

        feJob($this->store, 'Me convenciste, lo tomo');

        expect(feTurns($this->product))->toBeNull();
    });
});

describe('commerce', function () {
    beforeEach(function () {
        $this->store->update(['welcome_message' => null]);
        $this->versions->publish($this->product, feDefinition([
            feStage('dudas', ['rules' => [
                ['when' => ['phrase' => ['Quiero mi acceso']], 'then' => ['action' => 'start_payment']],
                ['when' => ['plan_named' => true], 'then' => ['action' => 'start_payment']],
                ['when' => ['intent' => 'comprar', 'min_confidence' => 0.8], 'then' => ['action' => 'start_payment']],
                ['when' => ['intent' => 'objecion', 'min_confidence' => 0.8], 'then' => ['action' => 'show_offer']],
            ]]),
        ], ['intents' => feIntents(), 'offer_cta' => 'Escribe QUIERO MI ACCESO']));
        $this->product->update(['sample_closing_message' => 'CIERRE-CONFIGURADO']);
    });

    it('answers a question about plans with the Core, starting nothing and calling no model', function () {
        feJob($this->store, '¿Cuánto cuesta el plan mensual?');

        expect(Payment::count())->toBe(0)->and(feAnswers())->toHaveCount(0)->and(feLast())->toContain('Planes de «Asesor»');
    });

    it('keeps the Core\'s own payment requests, in any stage', function () {
        feJob($this->store, 'pagar');

        expect(Payment::sole()->plan_name)->toBe('Mensual')->and(feLast())->toContain('INSTRUCCIONES-PAGO')->and(feAnswers())->toHaveCount(0);
    });

    it('starts the purchase on a configured phrase, by the same flow', function () {
        feJob($this->store, 'Hola, quiero mi acceso');

        expect(Payment::sole()->plan_name)->toBe('Mensual')->and(feLast())->toContain('INSTRUCCIONES-PAGO')->and(feAnswers())->toHaveCount(0)
            ->and(feState($this->store, $this->product)->stage)->toBe('dudas');
    });

    it('asks which plan on a phrase when several fit', function () {
        Plan::create(['product_id' => $this->product->id, 'name' => 'Semanal', 'price' => 8000, 'duration_days' => 7]);

        feJob($this->store, 'Quiero mi acceso');
        expect(Payment::count())->toBe(0)->and(feLast())->toContain('¿Qué plan de «Asesor» quieres?');

        feJob($this->store, 'Semanal');
        expect(Payment::sole()->plan_name)->toBe('Semanal')->and((float) Payment::sole()->amount)->toBe(8000.0);
    });

    it('takes a plan named alone, by its stored name', function () {
        Plan::create(['product_id' => $this->product->id, 'name' => 'Semanal', 'price' => 8000, 'duration_days' => 7]);

        feJob($this->store, 'El semanal');

        expect(Payment::sole()->plan_name)->toBe('Semanal')->and(feAnswers())->toHaveCount(0);
    });

    it('does not take a question about a plan for choosing it', function () {
        Plan::create(['product_id' => $this->product->id, 'name' => 'Semanal', 'price' => 8000, 'duration_days' => 7]);

        feJob($this->store, '¿El semanal incluye ejercicios?');

        expect(Payment::count())->toBe(0)->and(feAnswers())->toHaveCount(1);
    });

    it('never starts a payment on a purchase read by the model without the plan: the customer confirms it', function () {
        $this->replies = [feJson('¡Perfecto!', 'comprar', 0.95)];

        feJob($this->store, 'Me convenciste');

        expect(Payment::count())->toBe(0)
            ->and(feLast())->toContain('¿Qué plan de «Asesor» quieres?')->toContain('1. Mensual: $20.000 COP por 30 días')->not->toContain('¡Perfecto!');

        feJob($this->store, '1');

        expect(Payment::sole()->plan_name)->toBe('Mensual');
    });

    it('does not act on an uncertain purchase', function () {
        $this->replies = [feJson('Cuéntame más.', 'comprar', 0.5)];

        feJob($this->store, 'Puede ser');

        expect(Payment::count())->toBe(0)->and(feLast())->toBe('Cuéntame más.');
    });

    it('shows the configured offer after the reply, with the catalog\'s plans', function () {
        $this->replies = [feJson('Te entiendo.', 'objecion', 0.9)];

        feJob($this->store, 'Me parece caro');

        expect(feLast())->toBe("Te entiendo.\n\nCIERRE-CONFIGURADO\n\nPlanes de «Asesor»:\n1. Mensual: \$20.000 COP por 30 días\n\nEscribe QUIERO MI ACCESO");
    });

    it('never duplicates a payment: an open one is the Core\'s answer, without the model', function () {
        feJob($this->store, 'Quiero mi acceso');
        $calls = count(feAnswers());

        feJob($this->store, 'Quiero mi acceso');
        feJob($this->store, '¿Y ahora qué hago?');

        expect(Payment::count())->toBe(1)->and(count(feAnswers()))->toBe($calls)->and(feLast())->toContain('INSTRUCCIONES-PAGO');
    });

    it('starts nothing without available plans or payment instructions', function (Closure $setup) {
        $setup->call($this);

        feJob($this->store, 'Quiero mi acceso');

        expect(Payment::count())->toBe(0)->and(feLast())->toContain('no es posible pagar');
    })->with([
        'no active plans' => [fn () => Plan::query()->update(['is_active' => false])],
        'no payment instructions' => [fn () => $this->store->update(['payment_instructions' => null])],
    ]);

    it('discards a reply that invents a price or grants access', function (string $invented) {
        $this->replies = [feJson($invented)];

        feJob($this->store, '¿Tienen descuento?');

        expect(feLast())->not->toContain($invented)->toContain('Planes de «Asesor»')
            ->and(feState($this->store, $this->product))->toBeNull()
            ->and(Subscription::count())->toBe(0);
    })->with([
        'Te lo dejo en $5.000 COP.',
        'Tienes un descuento del 50%.',
        'Tu suscripción ya está activa.',
    ]);

    it('uses the store\'s text for a discarded reply when it has one', function () {
        $this->store->update(['topic_unavailable_message' => 'TEXTO-NO-DISPONIBLE']);
        $this->replies = [feJson('Te lo dejo en $5.000 COP.')];

        feJob($this->store, '¿Tienen descuento?');

        expect(feLast())->toBe('TEXTO-NO-DISPONIBLE');
    });

    it('never grants access through the funnel', function () {
        $this->replies = [feJson('Listo.', 'comprar', 1.0)];

        feJob($this->store, 'Ya pagué, dame el acceso');

        expect(app(AccessGate::class)->decide(feCustomer($this->store), $this->product)->allowed)->toBeFalse()
            ->and(Subscription::count())->toBe(0);
    });
});

describe('products without a funnel', function () {
    it('keep their flow: no state, the old prompt and the offer on the last free reply', function () {
        config(['botinker.free_interactions.max' => 1]);
        $this->store->update(['welcome_message' => null]);
        $this->product->update(['sample_instructions' => 'INSTR-MUESTRA']);

        feJob($this->store, 'Hola');

        expect(fePrompt())->toContain("### MUESTRA GRATUITA\n")->toContain('INSTR-MUESTRA')->not->toContain('PROCESO COMERCIAL')
            ->and(feLast())->toBe("RESPUESTA-DEL-MODELO\n\nPlanes de «Asesor»:\n1. Mensual: \$20.000 COP por 30 días\n\nSelecciona el plan que prefieras usando uno de los botones de abajo. 👇")
            ->and(feTurns($this->product))->toBe(1)
            ->and(ConversationFunnelState::count())->toBe(0);
    });

    it('use the global limit, whatever other products configure', function () {
        config(['botinker.free_interactions.max' => 2]);
        $other = feProduct($this->store, ['name' => 'Otro'], 'otro');
        $this->versions->publish($other, feDefinition([feStage('a')], ['free_replies' => ['limit' => 9]]));

        expect(app(AccessGate::class)->decide(feCustomer($this->store), $this->product)->sampleAvailable)->toBeTrue()
            ->and(SampleLedger::limit($this->product))->toBe(2)
            ->and(SampleLedger::limit($other->fresh()))->toBe(9);
    });

    it('go back to their flow when the funnel is deactivated, keeping the counter', function () {
        config(['botinker.free_interactions.max' => 3]);
        $this->versions->publish($this->product, feDefinition([feStage('gratis', ['uses_free_replies' => true])], ['free_replies' => ['limit' => 2]]));
        feJob($this->store, 'Uno');

        $this->versions->deactivate($this->product);
        feJob($this->store, 'Dos');

        expect(fePrompt())->not->toContain('PROCESO COMERCIAL')->toContain("### MUESTRA GRATUITA\n")
            ->and(feTurns($this->product))->toBe(2);
    });

    it('offer no free replies with a funnel that gives none', function () {
        $this->versions->publish($this->product, feDefinition([feStage('a')]));

        expect($this->product->fresh()->offersSample())->toBeFalse()
            ->and(app(AccessGate::class)->decide(feCustomer($this->store), $this->product->fresh())->sampleAvailable)->toBeFalse();
    });
});

describe('two verticals, with no code of their own', function () {
    it('runs a recipe book and an English course, each by its own configuration', function () {
        $store = feStore(['welcome_message' => null]);

        $recipes = feProduct($store, [
            'name' => 'Recetario', 'description' => 'RECETAS-DESC', 'faq_context' => null, 'commercial_instructions' => 'RECETAS-COM', 'ai_sales_strategy' => 'RECETAS-SERVICIO',
            'opening_message' => 'Bienvenido al {producto}.', 'opening_triggers' => ['Quiero el recetario'], 'sample_closing_message' => 'Más de 200 recetas.',
        ], 'cocina', [['Mensual', 15000, 30]]);
        feSource($store, $recipes, 'Sopa rápida', 'RECETA-MUESTRA', KnowledgeSource::PURPOSE_SAMPLE);
        feSource($store, $recipes, 'Banquete completo', 'RECETA-EXCLUSIVA', KnowledgeSource::PURPOSE_KNOWLEDGE);
        $this->versions->publish($recipes, feDefinition([
            feStage('probar', ['goal' => 'Mostrar una receta', 'instructions' => 'Da una receta sencilla.', 'context' => ['sample_content'], 'uses_free_replies' => true]),
            feStage('vender', ['goal' => 'Ofrecer el recetario', 'instructions' => 'Invita a comprar.', 'context' => ['description'], 'rules' => [
                ['when' => ['any_reply' => true], 'then' => ['action' => 'show_offer']],
            ]]),
        ], ['goal' => 'Vender recetas', 'free_replies' => ['limit' => 2, 'on_exhausted' => 'vender'], 'offer_cta' => 'Escribe pagar para cocinar']));

        $course = feProduct($store, [
            'name' => 'Curso de inglés', 'description' => 'INGLES-DESC', 'faq_context' => 'INGLES-FAQ', 'commercial_instructions' => 'INGLES-COM', 'ai_sales_strategy' => 'INGLES-SERVICIO',
        ], 'idiomas', [['Trimestral', 90000, 90], ['Anual', 300000, 365]]);
        feSource($store, $course, 'Metodología', 'INGLES-RESOURCE', KnowledgeSource::PURPOSE_COMMERCIAL);
        $this->versions->publish($course, feDefinition([
            feStage('nivel', ['type' => 'message', 'text' => '¿Cuál es tu nivel de inglés?', 'next' => 'asesoria']),
            feStage('asesoria', ['goal' => 'Recomendar', 'instructions' => 'Relaciona su nivel con el curso.', 'context' => ['description', 'faq', 'commercial_resources'], 'rules' => [
                ['when' => ['phrase' => ['Me inscribo']], 'then' => ['action' => 'start_payment']],
                ['when' => ['intent' => 'dudar', 'min_confidence' => 0.7], 'then' => ['action' => 'go_to', 'stage' => 'objeciones']],
            ]]),
            feStage('objeciones', ['goal' => 'Resolver la objeción', 'instructions' => 'Responde sin presionar.', 'context' => ['commercial_resources'], 'max_interactions' => 1, 'on_limit' => 'asesoria']),
        ], ['goal' => 'Inscribir alumnos', 'intents' => [['key' => 'dudar', 'description' => 'Duda de inscribirse']]]));

        // The recipe book: opening, two free replies, then the offer.
        $this->sections = ['Sopa rápida', 'Banquete completo'];
        feJob($store, 'Hola, quiero el recetario', '573001000001');
        expect(feLast())->toBe('Bienvenido al Recetario.')->and(feTurns($recipes))->toBe(1);

        feJob($store, 'Dame una receta', '573001000001');
        expect(fePrompt())->toContain('Mostrar una receta')->toContain('RECETA-MUESTRA')->not->toContain('RECETA-EXCLUSIVA')->not->toContain('RECETAS-SERVICIO')
            ->and(feTurns($recipes))->toBe(2);

        $this->defaultReply = 'Mira lo que incluye.';
        feJob($store, 'Otra receta', '573001000001');
        expect(fePrompt())->toContain('Ofrecer el recetario')->toContain('RECETAS-DESC')
            ->and(feLast())->toBe("Mira lo que incluye.\n\nMás de 200 recetas.\n\nPlanes de «Recetario»:\n1. Mensual: \$15.000 COP por 30 días\n\nEscribe pagar para cocinar")
            ->and(feTurns($recipes))->toBe(2);

        // The course: a question first, no free replies, objections, two plans.
        $this->defaultReply = 'RESPUESTA-DEL-MODELO';
        feJob($store, 'Información del Curso de inglés', '573001000002');
        expect(feLast())->toBe('¿Cuál es tu nivel de inglés?');

        $this->sections = ['Metodología'];
        $this->replies = [feJson('Tenemos un método para ti.', 'dudar', 0.9)];
        feJob($store, 'Básico, pero no sé si tengo tiempo', '573001000002');
        expect(fePrompt())->toContain('Recomendar')->toContain('INGLES-FAQ')->toContain('INGLES-RESOURCE')->not->toContain('INGLES-SERVICIO')
            ->and(feState($store, $course, '573001000002')->stage)->toBe('objeciones');

        feJob($store, '¿Y si me atraso?', '573001000002');
        expect(fePrompt())->toContain('Resolver la objeción')
            ->and(feState($store, $course, '573001000002')->stage)->toBe('asesoria');

        feJob($store, 'Me inscribo', '573001000002');
        expect(Payment::count())->toBe(0)->and(feLast())->toContain('1. Trimestral')->toContain('2. Anual');

        feJob($store, 'Anual', '573001000002');
        expect(Payment::sole())->plan_name->toBe('Anual')->product_id->toBe($course->id)
            ->and((float) Payment::sole()->amount)->toBe(300000.0)
            ->and(feTurns($course))->toBeNull();
    });

    it('keeps the Core free of any product of these tests', function () {
        $core = collect(['app/Platform/Funnel', 'app/Platform/Knowledge/KnowledgeAccess.php'])
            ->flatMap(fn (string $path) => is_dir(base_path($path)) ? glob(base_path($path).'/*.php') : [base_path($path)])
            ->map(fn (string $file) => file_get_contents($file))->implode("\n");

        expect($core)->not->toContain('Recetario')->not->toContain('Curso de inglés')->not->toContain('Seducci')
            ->not->toMatch('/product_id\s*===?\s*\d|->id\s*===?\s*\d/');
    });
});

describe('only what WhatsApp took moves anything', function () {
    beforeEach(function () {
        $this->store->update(['welcome_message' => null]);
    });

    it('does not move the state nor record the offer when a commercial action did not go out', function () {
        $this->versions->publish($this->product, feDefinition([
            feStage('dudas', ['rules' => [['when' => ['phrase' => ['Ver opciones']], 'then' => ['action' => 'show_offer', 'stage' => 'cierre']]]]),
            feStage('cierre'),
        ]));
        $this->whatsAppDown = true;

        expect(fn () => ProcessWhatsAppMessage::dispatchSync($this->store->fresh(), FE_PHONE, 'Ver opciones', 'wamid.in.a'))->toThrow(RuntimeException::class)
            ->and(ConversationFunnelState::count())->toBe(0)
            ->and(WhatsAppMessage::count())->toBe(0)
            ->and(feConversation($this->store)->offers_shown)->toBeNull();

        $this->whatsAppDown = false;
        ProcessWhatsAppMessage::dispatchSync($this->store->fresh(), FE_PHONE, 'Ver opciones', 'wamid.in.a');

        expect(feState($this->store, $this->product)->stage)->toBe('cierre')
            ->and(feConversation($this->store)->offers_shown)->toHaveKey((string) $this->product->id)
            ->and(WhatsAppMessage::where('role', 'assistant')->count())->toBe(1);
    });

    it('starts a single payment across retries of a purchase that did not go out', function () {
        $this->versions->publish($this->product, feDefinition([
            feStage('dudas', ['rules' => [['when' => ['phrase' => ['Quiero mi acceso']], 'then' => ['action' => 'start_payment']]]]),
        ]));
        $this->whatsAppDown = true;

        expect(fn () => ProcessWhatsAppMessage::dispatchSync($this->store->fresh(), FE_PHONE, 'Quiero mi acceso', 'wamid.in.b'))->toThrow(RuntimeException::class)
            ->and(Payment::count())->toBe(1)
            ->and(ConversationFunnelState::count())->toBe(0);

        $this->whatsAppDown = false;
        ProcessWhatsAppMessage::dispatchSync($this->store->fresh(), FE_PHONE, 'Quiero mi acceso', 'wamid.in.b');

        expect(Payment::count())->toBe(1)
            ->and(feLast())->toContain('INSTRUCCIONES-PAGO')
            ->and(WhatsAppMessage::where('role', 'assistant')->count())->toBe(1);
    });

    it('fails the job when the answer to an open payment did not go out', function () {
        $this->versions->publish($this->product, feDefinition([feStage('dudas')]));
        Payment::start(feCustomer($this->store), Plan::where('product_id', $this->product->id)->sole(), now()->addHours(48));
        $this->whatsAppDown = true;

        expect(fn () => ProcessWhatsAppMessage::dispatchSync($this->store->fresh(), FE_PHONE, 'Ya casi', 'wamid.in.c'))->toThrow(RuntimeException::class)
            ->and(WhatsAppMessage::count())->toBe(0);
    });

    it('reuses the answer already generated when a message is retried, spending one reply', function () {
        $this->versions->publish($this->product, feDefinition([feStage('gratis', ['uses_free_replies' => true])], ['free_replies' => ['limit' => 3]]));
        $this->whatsAppDown = true;
        $this->replies = ['PRIMERA-RESPUESTA', 'NO-DEBE-USARSE'];

        expect(fn () => ProcessWhatsAppMessage::dispatchSync($this->store->fresh(), FE_PHONE, 'Uno', 'wamid.in.d'))->toThrow(RuntimeException::class)
            ->and(feTurns($this->product))->toBeNull();

        $this->whatsAppDown = false;
        ProcessWhatsAppMessage::dispatchSync($this->store->fresh(), FE_PHONE, 'Uno', 'wamid.in.d');

        expect(feAnswers())->toHaveCount(1)
            ->and(feLast())->toBe('PRIMERA-RESPUESTA')
            ->and(feTurns($this->product))->toBe(1)
            ->and(WhatsAppMessage::where('role', 'assistant')->count())->toBe(1);
    });

    it('gives a reservation back only once', function () {
        $this->versions->publish($this->product, feDefinition([
            feStage('gratis', ['uses_free_replies' => true, 'rules' => [['when' => ['intent' => 'comprar'], 'then' => ['action' => 'start_payment']]]]),
        ], ['free_replies' => ['limit' => 3], 'intents' => feIntents()]));
        SampleDelivery::create(['customer_id' => feCustomer($this->store)->id, 'product_id' => $this->product->id, 'turns_used' => 2]);
        $this->replies = [feJson('Claro.', 'comprar', 0.95)];
        $this->whatsAppDown = true;

        expect(fn () => ProcessWhatsAppMessage::dispatchSync($this->store->fresh(), FE_PHONE, 'Lo tomo', 'wamid.in.e'))->toThrow(RuntimeException::class)
            ->and(feTurns($this->product))->toBe(2);
    });

    it('moves on normally once it went out', function () {
        $this->versions->publish($this->product, feDefinition([feStage('dudas')]));

        feJob($this->store, 'Hola');

        expect(feState($this->store, $this->product)->stage_interactions)->toBe(1)
            ->and(WhatsAppMessage::where('role', 'assistant')->sole()->wamid)->not->toBeNull();
    });
});

describe('who already was a customer', function () {
    beforeEach(function () {
        $this->store->update(['welcome_message' => null]);
        $this->product->update(['opening_message' => 'TEXTO-INICIAL', 'opening_triggers' => ['Quiero conocer el asesor']]);
        $this->versions->publish($this->product, feDefinition([feStage('gratis', ['uses_free_replies' => true])], ['free_replies' => ['limit' => 3]]));
        $this->customer = feCustomer($this->store);
    });

    it('lets a customer who never had it into the funnel', function () {
        feJob($this->store, 'Quiero conocer el asesor');

        expect(feLast())->toBe('TEXTO-INICIAL')->and(feState($this->store, $this->product))->not->toBeNull();
    });

    it('gives an active subscription the service', function () {
        Subscription::create(['customer_id' => $this->customer->id, 'product_id' => $this->product->id, 'started_at' => now()->subDay(), 'expires_at' => now()->addDays(20)]);

        feJob($this->store, 'Quiero conocer el asesor');

        expect(fePrompt())->toContain('SERVICIO-RESERVADO')->not->toContain('PROCESO COMERCIAL')
            ->and(ConversationFunnelState::count())->toBe(0)
            ->and(feTurns($this->product))->toBeNull();
    });

    it('treats an expired subscription as a renewal, without opening, model or free replies', function () {
        Subscription::create(['customer_id' => $this->customer->id, 'product_id' => $this->product->id, 'started_at' => now()->subDays(40), 'expires_at' => now()->subDays(10)]);

        feJob($this->store, 'Quiero conocer el asesor');

        expect(feLast())->toContain('venció el')->toContain('renuévala')->toContain('Planes de «Asesor»')->not->toContain('TEXTO-INICIAL')
            ->and(feAnswers())->toHaveCount(0)
            ->and(ConversationFunnelState::count())->toBe(0)
            ->and(feTurns($this->product))->toBeNull();
    });

    it('lets an expired subscription renew by the same payment flow', function () {
        Subscription::create(['customer_id' => $this->customer->id, 'product_id' => $this->product->id, 'started_at' => now()->subDays(40), 'expires_at' => now()->subDays(10)]);

        feJob($this->store, 'Quiero renovar mi suscripción');

        expect(Payment::sole()->plan_name)->toBe('Mensual')->and(feLast())->toContain('INSTRUCCIONES-PAGO');
    });

    it('gives a revoked access no onboarding and no service', function () {
        Subscription::create(['customer_id' => $this->customer->id, 'product_id' => $this->product->id, 'started_at' => now()->subDay(), 'expires_at' => now()->addDays(20), 'status' => Subscription::STATUS_REVOKED]);

        feJob($this->store, 'Quiero conocer el asesor');

        expect(feLast())->toContain('no está activo')->not->toContain('TEXTO-INICIAL')
            ->and(feAnswers())->toHaveCount(0)
            ->and(ConversationFunnelState::count())->toBe(0)
            ->and(feTurns($this->product))->toBeNull()
            ->and(app(AccessGate::class)->decide($this->customer, $this->product->fresh())->allowed)->toBeFalse();
    });

    it('takes a state from before the subscription lapsed out of the funnel too', function () {
        feJob($this->store, 'Hola');
        Subscription::create(['customer_id' => $this->customer->id, 'product_id' => $this->product->id, 'started_at' => now()->subDays(40), 'expires_at' => now()->subDays(1)]);
        $calls = count(feAnswers());

        feJob($this->store, 'Sigo aquí');

        expect(count(feAnswers()))->toBe($calls)->and(feLast())->toContain('venció el');
    });
});

describe('sample and knowledge stay apart', function () {
    it('reads only the sample of a title that also exists in the knowledge', function () {
        feSource($this->store, $this->product, 'Lección', 'VERSION-MUESTRA', KnowledgeSource::PURPOSE_SAMPLE);
        feSource($this->store, $this->product, 'Lección', 'VERSION-EXCLUSIVA', KnowledgeSource::PURPOSE_KNOWLEDGE);
        $this->sections = ['Lección'];
        $this->versions->publish($this->product, feDefinition([feStage('demo', ['context' => ['sample_content']])]));

        feJob($this->store, 'Lección');

        expect(fePrompt())->toContain('VERSION-MUESTRA')->not->toContain('VERSION-EXCLUSIVA');
    });

    it('reads the sample of the topic, not of another product nor a retired one', function () {
        $other = feProduct($this->store, ['name' => 'Otro']);
        $ingestor = app(KnowledgeIngestor::class);
        $ingestor->ingest($this->store, 'tema', null, 'Fuente', 'markdown', "# Del tema\n\nSAMPLE-TEMA", origin: 'o1', purpose: KnowledgeSource::PURPOSE_SAMPLE);
        $ingestor->ingest($this->store, 'tema', $other, 'Fuente', 'markdown', "# Del otro\n\nSAMPLE-OTRO", origin: 'o2', purpose: KnowledgeSource::PURPOSE_SAMPLE);
        $retired = $ingestor->ingest($this->store, 'tema', $this->product, 'Fuente', 'markdown', "# Retirada\n\nSAMPLE-RETIRADO", origin: 'o3', purpose: KnowledgeSource::PURPOSE_SAMPLE);
        $ingestor->retire($retired);
        $this->sections = ['Del tema', 'Del otro', 'Retirada'];
        $this->versions->publish($this->product, feDefinition([feStage('demo', ['context' => ['sample_content']])]));

        feJob($this->store, 'Asesor: dame algo');

        expect(fePrompt())->toContain('SAMPLE-TEMA')->not->toContain('SAMPLE-OTRO')->not->toContain('SAMPLE-RETIRADO');
    });

    it('authorizes the knowledge only with access, and never reads it in a stage', function () {
        $stage = FunnelDefinition::fromArray(feDefinition([feStage('demo', ['context' => FunnelDefinition::CONTEXTS])]))->stage('demo');
        $access = app(KnowledgeAccess::class);
        $customer = feCustomer($this->store);

        expect($access->allowedPurposes(app(AccessGate::class)->decide($customer, $this->product), $stage))->toBe([KnowledgeSource::PURPOSE_COMMERCIAL, KnowledgeSource::PURPOSE_SAMPLE]);

        Subscription::create(['customer_id' => $customer->id, 'product_id' => $this->product->id, 'started_at' => now()->subDay(), 'expires_at' => now()->addDays(20)]);

        expect($access->allowedPurposes(app(AccessGate::class)->decide($customer, $this->product), $stage))->toBe([KnowledgeSource::PURPOSE_KNOWLEDGE]);
    });
});

describe('greetings around a trigger', function () {
    it('does not erase a text made only of greetings or courtesies', function (string $text, string $normalized) {
        expect(OpeningMessage::normalize($text))->toBe($normalized);
    })->with([
        ['Buenas tardes', 'buenas tardes'],
        ['Buenas noches', 'buenas noches'],
        ['¡Hola, buenas!', 'hola buenas'],
        ['Muchas gracias', 'muchas gracias'],
        ['Hola, gracias', 'hola gracias'],
        ['Por favor', 'por favor'],
        ['Hola, quiero algo', 'quiero algo'],
        ['Quiero algo, muchas gracias', 'quiero algo'],
    ]);

    it('fires only on the whole trigger in a product without a funnel', function (string $message, bool $fires) {
        config(['botinker.free_interactions.max' => 3]);
        $this->product->update(['opening_message' => 'TEXTO-INICIAL', 'opening_triggers' => ['Quiero conocer el asesor', 'tardes']]);

        feJob($this->store, $message);

        expect(feLast() === 'TEXTO-INICIAL')->toBe($fires);
    })->with([
        'with greeting' => ['Hola, quiero conocer el asesor', true],
        'only a greeting' => ['Hola', false],
        'a greeting that ends like a trigger' => ['Buenas tardes', false],
        'greeting and courtesy' => ['Hola, gracias', false],
        'more than the trigger' => ['Hola, quiero conocer el asesor y sus planes', false],
    ]);
});

describe('what the reply of a funnel may say', function () {
    it('lets through figures and gratis that state no commercial condition', function (string $reply) {
        expect(app(CommercialClaims::class)->funnelViolation($reply, 'Secretos del Recetario'))->toBeNull();
    })->with([
        'Prepara 2.000 calorías al día.',
        'Agrega 1500 gramos de harina.',
        'Tiene 10% de grasa.',
        'Según el Decreto 1072 de 2015, debes documentarlo.',
        'Haz 3 x 10 repeticiones.',
        'Levanta pesos dos veces por semana.',
        'Te doy un consejo gratis.',
        'Esta respuesta es gratuita, y puedes preguntar más.',
        'La promoción de la salud es clave.',
        'Vale, empecemos con 3 pasos.',
        'En 2024 cambió la norma. El plan de trabajo tiene 5 pasos.',
        'Tu pago de servicios no tiene que ver con esto.',
    ]);

    it('stops prices, discounts and conditions only the Core may state', function (string $reply, string $reason) {
        expect(app(CommercialClaims::class)->funnelViolation($reply, 'Secretos del Recetario'))->toBe($reason);
    })->with([
        ['Te lo dejo en $5.000.', 'currency'],
        ['Son 5000 pesos.', 'currency'],
        ['Cuesta 20 mil al mes.', 'price'],
        ['El precio es 15.', 'price'],
        ['Vale 30 al mes.', 'price'],
        ['Tienes 50% de descuento.', 'discount'],
        ['Tenemos una promoción esta semana.', 'discount'],
        ['La suscripción es gratis.', 'free'],
        ['El Recetario es gratuito.', 'free'],
        ['Tu pago fue confirmado.', 'payment'],
        ['Tu suscripción ya está activa.', 'access'],
        ['Ya tienes acceso.', 'access'],
    ]);

    it('sends a legitimate reply with figures through the funnel', function () {
        $this->store->update(['welcome_message' => null]);
        $this->versions->publish($this->product, feDefinition([feStage('dudas')]));
        $this->replies = ['Según el Decreto 1072 de 2015, son 2.000 horas.'];

        feJob($this->store, '¿Qué dice la norma?');

        expect(feLast())->toBe('Según el Decreto 1072 de 2015, son 2.000 horas.')
            ->and(feState($this->store, $this->product)->stage_interactions)->toBe(1);
    });

    it('leaves the stricter check of commercial replies as it was', function () {
        $claims = app(CommercialClaims::class);

        expect($claims->violation('Prepara 2.000 calorías al día.'))->not->toBeNull()
            ->and($claims->violation('Te doy un consejo gratis.'))->toBe('free');
    });
});

describe('existing flows this phase must not break', function () {
    it('keeps asking the required facts in a product without a funnel', function () {
        config(['botinker.free_interactions.max' => 2]);
        Topic::where('store_id', $this->store->id)->sole()->update(['facts' => [['scope' => 'domain', 'key' => 'ciudad', 'type' => 'text', 'question' => '¿En qué ciudad estás?', 'label' => 'ciudad']]]);
        $this->product->update(['required_facts' => ['ciudad']]);

        feJob($this->store, 'Hola');

        expect(feLast())->toContain('¿En qué ciudad estás?')->and(feAnswers())->toHaveCount(0);
    });

    it('does not ask for facts inside a funnel, as agreed for this phase', function () {
        Topic::where('store_id', $this->store->id)->sole()->update(['facts' => [['scope' => 'domain', 'key' => 'ciudad', 'type' => 'text', 'question' => '¿En qué ciudad estás?', 'label' => 'ciudad']]]);
        $this->product->update(['required_facts' => ['ciudad']]);
        $this->versions->publish($this->product, feDefinition([feStage('dudas')]));

        feJob($this->store, 'Hola');

        expect(feLast())->not->toContain('¿En qué ciudad estás?')->and(feAnswers())->toHaveCount(1);
    });
});

describe('a purchase whose message did not go out', function () {
    beforeEach(function () {
        $this->store->update(['welcome_message' => null]);
        $this->versions->publish($this->product, feDefinition([
            feStage('dudas', ['rules' => [
                ['when' => ['phrase' => ['Quiero mi acceso']], 'then' => ['action' => 'start_payment', 'stage' => 'cierre']],
                ['when' => ['intent' => 'comprar', 'min_confidence' => 0.8], 'then' => ['action' => 'start_payment', 'stage' => 'cierre']],
            ]]),
            feStage('cierre'),
        ], ['intents' => feIntents()]));
        $this->whatsAppDown = true;
    });

    it('recovers the open payment on the retry and reaches the destination stage', function () {
        expect(fn () => ProcessWhatsAppMessage::dispatchSync($this->store->fresh(), FE_PHONE, 'Quiero mi acceso', 'wamid.in.p1'))->toThrow(RuntimeException::class)
            ->and(Payment::count())->toBe(1)
            ->and(ConversationFunnelState::count())->toBe(0);

        $this->whatsAppDown = false;
        ProcessWhatsAppMessage::dispatchSync($this->store->fresh(), FE_PHONE, 'Quiero mi acceso', 'wamid.in.p1');

        expect(Payment::count())->toBe(1)
            ->and(Payment::sole()->status)->toBe(Payment::STATUS_PENDING)
            ->and(feLast())->toContain('INSTRUCCIONES-PAGO')
            ->and(feState($this->store, $this->product)->stage)->toBe('cierre')
            ->and(WhatsAppMessage::where('role', 'assistant')->count())->toBe(1);
    });

    it('does the same for a purchase the model read, without asking it again', function () {
        $this->replies = [feJson('Claro.', 'comprar', 0.95)];

        expect(fn () => ProcessWhatsAppMessage::dispatchSync($this->store->fresh(), FE_PHONE, 'Me animo con el Mensual', 'wamid.in.p2'))->toThrow(RuntimeException::class);

        $this->whatsAppDown = false;
        ProcessWhatsAppMessage::dispatchSync($this->store->fresh(), FE_PHONE, 'Me animo con el Mensual', 'wamid.in.p2');

        expect(Payment::count())->toBe(1)
            ->and(feAnswers())->toHaveCount(1)
            ->and(feLast())->toContain('INSTRUCCIONES-PAGO')
            ->and(feState($this->store, $this->product)->stage)->toBe('cierre')
            ->and(WhatsAppMessage::where('role', 'assistant')->count())->toBe(1);
    });

    it('stays where it was until a retry actually goes out', function () {
        foreach ([1, 2] as $attempt) {
            expect(fn () => ProcessWhatsAppMessage::dispatchSync($this->store->fresh(), FE_PHONE, 'Quiero mi acceso', 'wamid.in.p3'))->toThrow(RuntimeException::class);
        }

        expect(Payment::count())->toBe(1)
            ->and(ConversationFunnelState::count())->toBe(0)
            ->and(WhatsAppMessage::count())->toBe(0);

        $this->whatsAppDown = false;
        ProcessWhatsAppMessage::dispatchSync($this->store->fresh(), FE_PHONE, 'Quiero mi acceso', 'wamid.in.p3');

        expect(feState($this->store, $this->product)->stage)->toBe('cierre');
    });

    it('reaches the destination with the customer\'s next message once the retries ran out', function () {
        expect(fn () => ProcessWhatsAppMessage::dispatchSync($this->store->fresh(), FE_PHONE, 'Quiero mi acceso', 'wamid.in.p4'))->toThrow(RuntimeException::class);

        $this->whatsAppDown = false;
        feJob($this->store, '¿Sigues ahí?');

        expect(Payment::count())->toBe(1)
            ->and(feLast())->toContain('INSTRUCCIONES-PAGO')
            ->and(feState($this->store, $this->product)->stage)->toBe('cierre');
    });

    it('moves the funnel only once', function () {
        expect(fn () => ProcessWhatsAppMessage::dispatchSync($this->store->fresh(), FE_PHONE, 'Quiero mi acceso', 'wamid.in.p5'))->toThrow(RuntimeException::class);

        $this->whatsAppDown = false;
        ProcessWhatsAppMessage::dispatchSync($this->store->fresh(), FE_PHONE, 'Quiero mi acceso', 'wamid.in.p5');
        $this->states->save(feConversation($this->store), $this->product, $this->product->activeFunnel(), 'dudas', 0);

        feJob($this->store, '¿Sigues ahí?');

        expect(feState($this->store, $this->product)->stage)->toBe('dudas');
    });

    it('moves nothing for an open payment no failed purchase left', function () {
        $this->whatsAppDown = false;
        Payment::start(feCustomer($this->store), Plan::where('product_id', $this->product->id)->sole(), now()->addHours(48));

        feJob($this->store, 'Quiero mi acceso');

        expect(Payment::count())->toBe(1)
            ->and(feLast())->toContain('INSTRUCCIONES-PAGO')
            ->and(ConversationFunnelState::count())->toBe(0);
    });

    it('sends no payment again when it was confirmed before the retry', function () {
        expect(fn () => ProcessWhatsAppMessage::dispatchSync($this->store->fresh(), FE_PHONE, 'Quiero mi acceso', 'wamid.in.p6'))->toThrow(RuntimeException::class);

        Payment::sole()->update(['status' => Payment::STATUS_UNDER_REVIEW]);
        app(PaymentReview::class)->confirm(Payment::sole());

        $this->whatsAppDown = false;
        ProcessWhatsAppMessage::dispatchSync($this->store->fresh(), FE_PHONE, 'Quiero mi acceso', 'wamid.in.p6');

        expect(Payment::count())->toBe(1)
            ->and(Payment::sole()->status)->toBe(Payment::STATUS_CONFIRMED)
            ->and(collect(feTexts())->filter(fn ($text) => str_contains($text, 'INSTRUCCIONES-PAGO')))->toHaveCount(1) // the attempt that failed
            ->and(WhatsAppMessage::where('content', 'like', '%INSTRUCCIONES-PAGO%')->count())->toBe(0)
            ->and(ConversationFunnelState::count())->toBe(0);
    });

    it('does not move to a stage of a version replaced in between', function () {
        expect(fn () => ProcessWhatsAppMessage::dispatchSync($this->store->fresh(), FE_PHONE, 'Quiero mi acceso', 'wamid.in.p7'))->toThrow(RuntimeException::class);

        $this->versions->publish($this->product->fresh(), feDefinition([feStage('nueva')]));

        $this->whatsAppDown = false;
        ProcessWhatsAppMessage::dispatchSync($this->store->fresh(), FE_PHONE, 'Quiero mi acceso', 'wamid.in.p7');

        expect(Payment::count())->toBe(1)
            ->and(feLast())->toContain('INSTRUCCIONES-PAGO')
            ->and(ConversationFunnelState::count())->toBe(0);
    });

    it('asks for the plan on the retry when none was named, and starts nothing', function () {
        Plan::create(['product_id' => $this->product->id, 'name' => 'Anual', 'price' => 90000, 'duration_days' => 365]);
        $this->replies = [feJson('Claro.', 'comprar', 0.95)];

        expect(fn () => ProcessWhatsAppMessage::dispatchSync($this->store->fresh(), FE_PHONE, 'Me animo', 'wamid.in.p8'))->toThrow(RuntimeException::class)
            ->and(Payment::count())->toBe(0);

        $this->whatsAppDown = false;
        ProcessWhatsAppMessage::dispatchSync($this->store->fresh(), FE_PHONE, 'Me animo', 'wamid.in.p8');

        expect(Payment::count())->toBe(0)
            ->and(feLast())->toContain('¿Qué plan')
            ->and(WhatsAppMessage::where('role', 'assistant')->count())->toBe(1);
    });
});

describe('the offer to whoever lost access, when it did not go out', function () {
    beforeEach(function () {
        $this->store->update(['welcome_message' => null]);
        $this->customer = feCustomer($this->store);
        // Without a funnel, the free replies come first: these are spent.
        SampleDelivery::create(['customer_id' => $this->customer->id, 'product_id' => $this->product->id, 'turns_used' => 3]);
    });

    $lapse = [
        'expired' => [['started_at' => -40, 'expires_at' => -10], 'venció el'],
        'revoked' => [['started_at' => -1, 'expires_at' => 20, 'status' => Subscription::STATUS_REVOKED], 'no está activo'],
    ];

    foreach ([true, false] as $withFunnel) {
        foreach ($lapse as $name => [$subscription, $line]) {
            $case = $name.($withFunnel ? ' with a funnel' : ' without a funnel');

            it("shows it whole on the retry: {$case}", function () use ($withFunnel, $subscription, $line) {
                if ($withFunnel) {
                    $this->versions->publish($this->product, feDefinition([feStage('dudas')]));
                }

                Subscription::create(['customer_id' => $this->customer->id, 'product_id' => $this->product->id, 'status' => $subscription['status'] ?? Subscription::STATUS_ACTIVE, 'started_at' => now()->addDays($subscription['started_at']), 'expires_at' => now()->addDays($subscription['expires_at'])]);
                $this->whatsAppDown = true;

                expect(fn () => ProcessWhatsAppMessage::dispatchSync($this->store->fresh(), FE_PHONE, 'Quiero seguir', 'wamid.in.o1'))->toThrow(RuntimeException::class)
                    ->and(feConversation($this->store)->offers_shown)->toBeNull()
                    ->and(WhatsAppMessage::count())->toBe(0);

                $this->whatsAppDown = false;
                ProcessWhatsAppMessage::dispatchSync($this->store->fresh(), FE_PHONE, 'Quiero seguir', 'wamid.in.o1');

                expect(feLast())->toContain($line)->toContain('Planes de «Asesor»')
                    ->and(feConversation($this->store)->offers_shown)->toHaveKey((string) $this->product->id)
                    ->and(WhatsAppMessage::where('role', 'assistant')->count())->toBe(1);

                feJob($this->store, 'Quiero seguir');

                expect(feLast())->toContain($line)->not->toContain('Planes de «Asesor»')->toContain('Escribe «planes»');
            });
        }
    }

    it('does not restart the interval with the reminder', function () {
        Subscription::create(['customer_id' => $this->customer->id, 'product_id' => $this->product->id, 'started_at' => now()->subDays(40), 'expires_at' => now()->subDays(10)]);

        feJob($this->store, 'Quiero seguir');
        $shownAt = feConversation($this->store)->offers_shown[(string) $this->product->id];

        $this->travel(1)->hours();
        feJob($this->store, 'Quiero seguir');

        expect(feConversation($this->store)->offers_shown[(string) $this->product->id])->toBe($shownAt);
    });
});
