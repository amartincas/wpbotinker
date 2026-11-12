<?php

/*
 * Choosing a plan: reply buttons on every plan list a customer without a
 * valid subscription gets, and the plan named by its duration or by its
 * number in the list just shown. WhatsApp and the model are simulated, so
 * these tests prove the requests the Core sends and what it does with the
 * replies, not how WhatsApp shows the buttons.
 */

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Conversation;
use App\Models\ConversationFunnelState;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Product;
use App\Models\SampleDelivery;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\Topic;
use App\Models\WhatsAppMessage;
use App\Platform\Commerce\CommerceResponder;
use App\Platform\Funnel\GuidedFunnel;
use App\Platform\Funnel\SalesFunnelVersions;
use App\Platform\Memory\CustomerMemory;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

const PB_PHONE = '573004445566';

beforeEach(function () {
    $this->freezeSecond();

    $this->modelCalls = 0;
    $this->replies = [];
    $this->interactive = 'ok';

    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'api.openai.com')) {
            $this->modelCalls++;

            // A guided funnel's commercial turn reads a request for content, which the free reply answers.
            if (str_contains($request->data()['messages'][0]['content'], 'Este es un turno comercial')) {
                return Http::response(['choices' => [['message' => ['content' => json_encode(['reply' => '', 'intent' => null, 'confidence' => 0, 'question' => 'contenido'])]]]]);
            }

            return Http::response(['choices' => [['message' => ['content' => array_shift($this->replies) ?? json_encode(['reply' => 'RESPUESTA-DEL-MODELO', 'intent' => null, 'confidence' => 0])]]]]);
        }

        if (($request->data()['type'] ?? null) === 'interactive') {
            return match ($this->interactive) {
                'refuse' => Http::response(['error' => ['code' => 131009, 'message' => 'Parameter value is not valid']], 400),
                'unknown' => Http::response(['error' => 'upstream'], 500),
                'timeout' => throw new ConnectionException('cURL error 28: Operation timed out'),
                default => Http::response(['messages' => [['id' => 'wamid.out.'.Str::random(8)]]]),
            };
        }

        return Http::response(['messages' => [['id' => 'wamid.out.'.Str::random(8)]]]);
    });

    $this->store = makeStore(['conversation_mode' => Store::MODE_KNOWLEDGE, 'payment_instructions' => 'INSTRUCCIONES-PAGO']);
    Topic::create(['store_id' => $this->store->id, 'key' => 'tema', 'label' => 'Tema', 'instructions' => 'INSTRUCCIONES-TEMA']);
    $this->product = Product::create([
        'store_id' => $this->store->id, 'domain_key' => 'tema', 'name' => 'Asesor', 'description' => 'Asesoría.', 'price' => 1, 'stock' => 1,
        'access_mode' => Product::ACCESS_SUBSCRIPTION, 'commercial_instructions' => 'Instrucciones comerciales.',
    ]);
    $this->weekly = Plan::create(['product_id' => $this->product->id, 'name' => 'Semanal', 'price' => 9900, 'duration_days' => 7, 'sort_order' => 1]);
    $this->monthly = Plan::create(['product_id' => $this->product->id, 'name' => 'Mensual', 'price' => 19900, 'duration_days' => 30, 'sort_order' => 2]);

    app(SalesFunnelVersions::class)->publish($this->product, GuidedFunnel::definition('INSTRUCCION-DEL-ADMINISTRADOR', 1));
});

function pbSay(string $body, ?string $choice = null, string $phone = PB_PHONE, ?Store $store = null, ?string $wamid = null): void
{
    ProcessWhatsAppMessage::dispatchSync(($store ?? test()->store)->fresh(), $phone, $body, $wamid ?? 'wamid.in.'.Str::random(10), $choice !== null ? 'interactive' : 'text', null, null, null, $choice);
    test()->travel(1)->seconds();
}

/** What every send to WhatsApp carried, in order, whatever Meta answered. */
function pbSends(): array
{
    return Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/messages'))->map(fn ($pair) => $pair[0]->data())->values()->all();
}

function pbLast(): array
{
    $sends = pbSends();

    return end($sends);
}

function pbText(array $send): string
{
    return $send['text']['body'] ?? $send['interactive']['body']['text'];
}

function pbButtons(array $send): array
{
    return array_map(fn (array $button) => [$button['reply']['id'], $button['reply']['title']], $send['interactive']['action']['buttons'] ?? []);
}

function pbCustomer(string $phone = PB_PHONE, ?Store $store = null)
{
    return app(CustomerMemory::class)->customerFor($store ?? test()->store, $phone);
}

function pbSubscribe(): void
{
    Subscription::create(['customer_id' => pbCustomer()->id, 'product_id' => test()->product->id, 'started_at' => now()->subDays(2), 'expires_at' => now()->addDays(20)]);
}

function pbButtonReply(Store $store, string $id, string $title, string $wamid = 'wamid.in.boton'): array
{
    return ['entry' => [['changes' => [['value' => [
        'metadata' => ['phone_number_id' => $store->wa_phone_number_id],
        'messages' => [['from' => PB_PHONE, 'id' => $wamid, 'type' => 'interactive', 'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => $id, 'title' => $title]]]],
    ]]]]]];
}

function pbPostWebhook($test, array $payload)
{
    $raw = json_encode($payload);

    return $test->call('POST', '/api/whatsapp/webhook/any-token', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, 'app-secret'),
    ], $raw);
}

describe('the buttons that go out', function () {
    it('sends the plan list with a reply button per plan, in the format WhatsApp expects', function () {
        pbSay('Planes');

        $send = pbLast();

        expect($send['type'])->toBe('interactive')
            ->and($send['interactive']['type'])->toBe('button')
            ->and($send['interactive']['body']['text'])->toContain('1. Semanal: $9.900 COP por 7 días')->toContain('2. Mensual: $19.900 COP por 30 días')
            ->and(array_column($send['interactive']['action']['buttons'], 'type'))->toBe(['reply', 'reply'])
            ->and(pbButtons($send))->toBe([['plan:'.$this->weekly->id, 'Semanal'], ['plan:'.$this->monthly->id, 'Mensual']])
            ->and($this->modelCalls)->toBe(0);
    });

    it('tells to use the buttons, not to type, when the list goes with them', function (string $message, string $listed) {
        pbSay($message);

        $body = pbLast()['interactive']['body']['text'];

        expect($body)->toContain($listed)
            ->toEndWith('Selecciona el plan que prefieras usando uno de los botones de abajo. 👇')
            ->not->toContain('Escribe «pagar»')->not->toContain('Responde con el número.')
            ->and(pbButtons(pbLast()))->toHaveCount(2);
    })->with([
        'la oferta' => ['Planes', 'Planes de «Asesor»:'],
        'la elección de plan' => ['¿Cómo pago?', '¿Qué plan de «Asesor» quieres?'],
    ]);

    it('keeps an administrator\'s own words next to the buttons', function () {
        $this->product->update(['opening_message' => "Hola, mira nuestros planes:\n{planes}\n\nCuéntame cuál te interesa.", 'opening_triggers' => ['Quiero conocer el asesor']]);

        pbSay('Quiero conocer el asesor');

        expect(pbLast()['interactive']['body']['text'])->toEndWith('Cuéntame cuál te interesa.')->not->toContain(CommerceResponder::BUTTONS_INSTRUCTION)
            ->and(pbButtons(pbLast()))->toHaveCount(2);
    });

    it('keeps in the history what the customer read, and gives the model that', function () {
        pbSay('Planes');

        $stored = WhatsAppMessage::where('role', 'assistant')->latest('id')->value('content');

        expect($stored)->toBe(pbLast()['interactive']['body']['text'])->toEndWith(CommerceResponder::BUTTONS_INSTRUCTION)->not->toContain('Escribe «pagar»');

        pbSay('¿Cómo funciona?');

        $turn = Http::recorded(fn (Request $request) => str_contains($request->url(), 'api.openai.com') && str_contains($request->data()['messages'][0]['content'], 'Este es un turno comercial'))->last()[0]->data()['messages'];
        $history = collect($turn)->where('role', 'assistant')->pluck('content')->implode("\n");

        expect($history)->toContain(CommerceResponder::BUTTONS_INSTRUCTION)->not->toContain('Escribe «pagar» para suscribirte.');
    });

    it('keeps in the history the funnel\'s offer as it went out with its buttons', function () {
        pbSay('¿Cómo le hablo a una chica en un bar?');

        expect(pbLast()['type'])->toBe('interactive')
            ->and(WhatsAppMessage::where('role', 'assistant')->latest('id')->value('content'))->toBe(pbLast()['interactive']['body']['text'])->toEndWith(CommerceResponder::BUTTONS_INSTRUCTION);
    });

    it('keeps in the history the text that went out when Meta refused the buttons', function () {
        $this->interactive = 'refuse';

        pbSay('Planes');

        expect(WhatsAppMessage::where('role', 'assistant')->latest('id')->value('content'))->toBe(pbLast()['text']['body'])->toEndWith('Escribe «pagar» para suscribirte.');
    });

    it('keeps in the history an administrator\'s text as written, even with buttons', function () {
        $this->product->update(['opening_message' => "Hola, mira nuestros planes:\n{planes}\n\nCuéntame cuál te interesa.", 'opening_triggers' => ['Quiero conocer el asesor']]);

        pbSay('Quiero conocer el asesor');

        expect(WhatsAppMessage::where('role', 'assistant')->latest('id')->value('content'))->toBe(pbLast()['interactive']['body']['text'])->toEndWith('Cuéntame cuál te interesa.');
    });

    it('keeps the way to choose as text when the list goes without buttons', function () {
        collect(['A', 'B'])->each(fn ($name, $i) => Plan::create(['product_id' => $this->product->id, 'name' => "Plan {$name}", 'price' => 1000, 'duration_days' => 90 + $i, 'sort_order' => 5 + $i]));

        pbSay('Planes');

        expect(pbLast()['type'])->toBe('text')->and(pbLast()['text']['body'])->toEndWith('Escribe «pagar» para suscribirte.');
    });

    it('sends the text alone when the plans do not fit in buttons', function (Closure $change) {
        $change($this->product);

        pbSay('Planes');

        expect(pbLast()['type'])->toBe('text')->and(pbText(pbLast()))->toContain('Planes de «Asesor»');
    })->with([
        'más de tres planes' => [fn (Product $product) => collect(['A', 'B'])->each(fn ($name, $i) => Plan::create(['product_id' => $product->id, 'name' => "Plan {$name}", 'price' => 1000, 'duration_days' => 90 + $i, 'sort_order' => 5 + $i]))],
        'un título de más de veinte caracteres' => [fn (Product $product) => Plan::where('product_id', $product->id)->where('name', 'Mensual')->update(['name' => 'Mensual con acompañamiento'])],
        'dos planes con el mismo nombre' => [fn (Product $product) => Plan::where('product_id', $product->id)->where('name', 'Mensual')->update(['name' => 'semanal'])],
    ]);

    it('leaves the buttons out of a text WhatsApp would not take with them', function () {
        $responder = app(CommerceResponder::class);
        $block = $responder->plansBlock($this->product);

        expect($responder->planButtons($this->product, pbCustomer(), "Planes:\n".$block))->toHaveCount(2)
            ->and($responder->planButtons($this->product, pbCustomer(), str_repeat('a', 1024)."\n".$block))->toBeNull()
            ->and($responder->planButtons($this->product, pbCustomer(), 'Un texto sin la lista de planes.'))->toBeNull();
    });

    it('sends no buttons to a customer with a valid subscription', function () {
        pbSubscribe();

        pbSay('Planes');

        expect(pbLast()['type'])->toBe('text');
    });
});

describe('the webhook', function () {
    beforeEach(fn () => Queue::fake());

    it('takes a tapped button as its title and its id, and counts it as the customer writing', function () {
        $this->travel(-3)->hours();
        $conversation = Conversation::create(['store_id' => $this->store->id, 'customer_phone' => PB_PHONE, 'last_session_at' => now()]);
        $this->travel(3)->hours();

        pbPostWebhook($this, pbButtonReply($this->store, 'plan:'.$this->weekly->id, 'Semanal'))->assertOk();

        Queue::assertPushed(ProcessWhatsAppMessage::class, fn ($job) => $job->messageBody === 'Semanal' && $job->choiceId === 'plan:'.$this->weekly->id && $job->messageType === 'interactive');
        expect($conversation->fresh()->last_session_at->equalTo(now()))->toBeTrue();
    });

    it('queues a retried button once', function () {
        pbPostWebhook($this, pbButtonReply($this->store, 'plan:'.$this->weekly->id, 'Semanal', 'wamid.repetido'))->assertOk();
        pbPostWebhook($this, pbButtonReply($this->store, 'plan:'.$this->weekly->id, 'Semanal', 'wamid.repetido'))->assertOk();

        Queue::assertPushed(ProcessWhatsAppMessage::class, 1);
    });

    it('keeps ignoring buttons in a store of the sales flow, and any other interactive reply', function () {
        $sales = makeStore();

        pbPostWebhook($this, pbButtonReply($sales, 'plan:1', 'Semanal'))->assertOk();
        pbPostWebhook($this, ['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => $this->store->wa_phone_number_id],
            'messages' => [['from' => PB_PHONE, 'id' => 'wamid.lista', 'type' => 'interactive', 'interactive' => ['type' => 'list_reply', 'list_reply' => ['id' => 'x', 'title' => 'X']]]],
        ]]]]]])->assertOk();

        Queue::assertNothingPushed();
    });
});

describe('a tapped plan', function () {
    beforeEach(fn () => pbSay('Planes'));

    it('starts its pending payment without the model, a free reply or a read', function () {
        $calls = $this->modelCalls;

        pbSay('Semanal', 'plan:'.$this->weekly->id);

        expect(Payment::sole())->plan_id->toBe($this->weekly->id)->status->toBe(Payment::STATUS_PENDING)
            ->and(pbText(pbLast()))->toContain('plan Semanal')->toContain('INSTRUCCIONES-PAGO')
            ->and($this->modelCalls)->toBe($calls)
            ->and(SampleDelivery::count())->toBe(0)
            ->and((int) (ConversationFunnelState::first()?->context['limit_reads'] ?? 0))->toBe(0)
            ->and(Subscription::count())->toBe(0);
    });

    it('starts one payment however many times it is tapped, and changes it to another plan tapped next', function () {
        pbSay('Semanal', 'plan:'.$this->weekly->id);
        pbSay('Semanal', 'plan:'.$this->weekly->id);

        expect(Payment::count())->toBe(1)->and(Payment::sole()->plan_id)->toBe($this->weekly->id);

        pbSay('Mensual', 'plan:'.$this->monthly->id);

        expect(Payment::count())->toBe(2)
            ->and(Payment::whereIn('status', Payment::OPEN_STATUSES)->sole()->plan_id)->toBe($this->monthly->id)
            ->and(Payment::where('plan_id', $this->weekly->id)->sole()->status)->toBe(Payment::STATUS_EXPIRED)
            ->and(pbText(pbLast()))->toContain('plan Mensual');
    });

    it('starts no payment for a plan that no longer fits: it asks again, with the current plans', function (Closure $stale) {
        $choice = $stale($this);

        pbSay('Semanal', $choice);

        expect(Payment::count())->toBe(0)
            ->and(pbText(pbLast()))->toContain('¿Qué plan de «Asesor» quieres?')
            ->and(array_column(pbButtons(pbLast()), 0))->each->toStartWith('plan:');
    })->with([
        'desactivado' => [function ($test) {
            $test->weekly->update(['is_active' => false]);

            return 'plan:'.$test->weekly->id;
        }],
        'de otro producto de la tienda' => [function ($test) {
            $other = Product::create(['store_id' => $test->store->id, 'domain_key' => 'tema', 'name' => 'Otro', 'description' => 'Otro.', 'price' => 1, 'stock' => 1, 'access_mode' => Product::ACCESS_SUBSCRIPTION]);

            return 'plan:'.Plan::create(['product_id' => $other->id, 'name' => 'Semanal', 'price' => 500, 'duration_days' => 7])->id;
        }],
        'de otra tienda' => [function ($test) {
            $store = makeStore(['conversation_mode' => Store::MODE_KNOWLEDGE]);
            $product = Product::create(['store_id' => $store->id, 'domain_key' => 'tema', 'name' => 'Ajeno', 'description' => 'Ajeno.', 'price' => 1, 'stock' => 1, 'access_mode' => Product::ACCESS_SUBSCRIPTION]);

            return 'plan:'.Plan::create(['product_id' => $product->id, 'name' => 'Semanal', 'price' => 500, 'duration_days' => 7])->id;
        }],
        'mal formado' => [fn () => 'plan:uno'],
    ]);

    it('charges the plan\'s current price, not the one shown with the button', function () {
        $this->weekly->update(['price' => 12900]);

        pbSay('Semanal', 'plan:'.$this->weekly->id);

        expect((float) Payment::sole()->amount)->toBe(12900.0)->and(pbText(pbLast()))->toContain('$12.900 COP');
    });

    it('is only its title for a customer who subscribed meanwhile: no payment', function () {
        pbSubscribe();

        pbSay('Semanal', 'plan:'.$this->weekly->id);

        expect(Payment::count())->toBe(0);
    });
});

describe('when WhatsApp does not take the buttons', function () {
    it('sends the list as text only when Meta refused the buttons, with the way to choose it as text', function () {
        $this->interactive = 'refuse';

        pbSay('Planes');

        $sends = pbSends();
        $withButtons = $sends[0]['interactive']['body']['text'];
        $asText = $sends[1]['text']['body'];

        expect($sends)->toHaveCount(2)
            ->and($sends[0]['type'])->toBe('interactive')
            ->and($sends[1]['type'])->toBe('text')
            ->and($asText)->toEndWith('Escribe «pagar» para suscribirte.')->not->toContain(CommerceResponder::BUTTONS_INSTRUCTION)
            ->and($asText)->toBe(str_replace(CommerceResponder::BUTTONS_INSTRUCTION, 'Escribe «pagar» para suscribirte.', $withButtons));
    });

    it('sends nothing more when the outcome is unknown, so the customer never gets it twice', function (string $mode) {
        $this->interactive = $mode;

        pbSay('Planes');

        // A timeout leaves no recorded request; a 5xx leaves the attempt. Neither is sent again as text.
        expect(collect(pbSends())->where('type', 'text'))->toHaveCount(0)
            ->and(collect(pbSends())->where('type', 'interactive')->count())->toBeLessThanOrEqual(1);
    })->with(['error del servidor' => 'unknown', 'tiempo agotado' => 'timeout']);

    it('fails a funnel turn with an unknown outcome, so the queue retries it, and gives back its free reply', function () {
        $this->interactive = 'unknown';

        expect(fn () => ProcessWhatsAppMessage::dispatchSync($this->store->fresh(), PB_PHONE, '¿Cómo funciona?', 'wamid.reintento', 'text', null))->toThrow(RuntimeException::class);

        // The only text is the job's apology once given up (failed(), right away on the sync queue): never the turn itself.
        expect(SampleDelivery::count())->toBe(0)
            ->and(collect(pbSends())->where('type', 'text')->filter(fn ($send) => str_contains($send['text']['body'], 'Planes de «Asesor»')))->toHaveCount(0);

        $this->interactive = 'ok';
        $calls = $this->modelCalls;

        ProcessWhatsAppMessage::dispatchSync($this->store->fresh(), PB_PHONE, '¿Cómo funciona?', 'wamid.reintento', 'text', null);

        expect(pbLast()['type'])->toBe('interactive')
            ->and(pbText(pbLast()))->toStartWith('RESPUESTA-DEL-MODELO')->toContain('Planes de «Asesor»')
            ->and($this->modelCalls)->toBe($calls)
            ->and((int) SampleDelivery::value('turns_used'))->toBe(1);
    });
});

describe('a plan named in the customer\'s words', function () {
    it('takes the real conversation as one payment of the weekly plan, never asking for its number', function () {
        pbSay('Planes');

        pbSay('1 semana');
        pbSay('Por 7 días');
        pbSay('Pagar');

        expect(Payment::count())->toBe(1)
            ->and(Payment::sole()->plan_id)->toBe($this->weekly->id)
            ->and(collect(pbSends())->map(fn ($send) => pbText($send))->implode("\n"))->not->toContain('Responde con el número');
    });

    it('takes a duration only with its quantity', function (string $message) {
        pbSay('Planes');

        pbSay($message);

        expect(Payment::count())->toBe(0);
    })->with(['esta semana' => 'Esta semana', 'semana' => 'Semana', 'mes' => 'Mes', 'el mes que viene' => 'El mes que viene', 'te aviso en 7 días' => 'Te aviso en 7 días']);

    it('reads each duration from the catalog: one week, seven days, one month', function (string $message, string $plan) {
        pbSay($message);

        expect(Payment::sole()->plan_name)->toBe($plan);
    })->with([
        'una semana' => ['Una semana', 'Semanal'],
        '7 días' => ['7 días', 'Semanal'],
        'un mes' => ['Un mes', 'Mensual'],
        '30 días' => ['El de 30 días', 'Mensual'],
    ]);

    it('asks which one when the duration fits several plans, with their buttons', function () {
        Plan::create(['product_id' => $this->product->id, 'name' => 'Premium', 'price' => 29900, 'duration_days' => 30, 'sort_order' => 3]);

        pbSay('Un mes');

        expect(Payment::count())->toBe(0)
            ->and(pbText(pbLast()))->toContain('¿Qué plan de «Asesor» quieres?')
            ->and(pbButtons(pbLast()))->toHaveCount(3);
    });

    it('keeps what already worked', function (string $message, ?string $plan) {
        pbSay($message);

        expect(Payment::first()?->plan_name)->toBe($plan);

        if ($plan === null) {
            expect(pbText(pbLast()))->toContain('¿Qué plan de «Asesor» quieres?')->and(pbButtons(pbLast()))->toHaveCount(2);
        }
    })->with([
        'quiero el semanal' => ['Quiero el semanal', 'Semanal'],
        'quiero contratar el mensual' => ['Quiero contratar el mensual', 'Mensual'],
        'cómo pago, sin plan' => ['¿Cómo pago?', null],
    ]);
});

describe('a plan by its number in the list', function () {
    it('takes the number right after the list was shown', function () {
        pbSay('Planes');

        pbSay('2');

        expect(Payment::sole()->plan_id)->toBe($this->monthly->id);
    });

    it('does not take a number once the list is no longer recent, nor from a subscriber', function (bool $subscribed) {
        pbSay('Planes');
        $subscribed ? pbSubscribe() : $this->travel(25)->hours();

        pbSay('2');

        expect(Payment::count())->toBe(0);
    })->with(['oferta antigua' => false, 'suscriptor' => true]);

    it('does not take a number outside the list', function () {
        pbSay('Planes');

        pbSay('7');

        expect(Payment::count())->toBe(0);
    });
});

describe('a product of another topic', function () {
    it('gets a button for its single plan, and the payment from it', function () {
        $store = makeStore(['conversation_mode' => Store::MODE_KNOWLEDGE, 'payment_instructions' => 'PAGO-COCINA']);
        Topic::create(['store_id' => $store->id, 'key' => 'cocina', 'label' => 'Cocina', 'instructions' => 'Recetas.']);
        $product = Product::create(['store_id' => $store->id, 'domain_key' => 'cocina', 'name' => 'Recetario', 'description' => 'Recetas.', 'price' => 1, 'stock' => 1, 'access_mode' => Product::ACCESS_SUBSCRIPTION]);
        $plan = Plan::create(['product_id' => $product->id, 'name' => 'Anual', 'price' => 50000, 'duration_days' => 365]);

        pbSay('Planes', store: $store);

        expect(pbButtons(pbLast()))->toBe([['plan:'.$plan->id, 'Anual']]);

        pbSay('Anual', 'plan:'.$plan->id, store: $store);

        expect(Payment::sole()->plan_id)->toBe($plan->id)->and(pbText(pbLast()))->toContain('PAGO-COCINA');
    });
});
