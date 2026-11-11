<?php

/*
 * The automatic reactivation of a prospect who stopped answering, in a guided
 * funnel. The model and WhatsApp are simulated; the queued job is captured and
 * then run through a real sync queue, middleware included. Concurrency between
 * processes is checked separately against MariaDB.
 */

use App\Filament\Resources\Products\Pages\EditGuidedFunnel;
use App\Jobs\ProcessWhatsAppMessage;
use App\Jobs\SendProspectReactivation;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Product;
use App\Models\SampleDelivery;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\Topic;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Platform\Funnel\FunnelDefinition;
use App\Platform\Funnel\GuidedFunnel;
use App\Platform\Funnel\SalesFunnelVersions;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;

const RX_PHONE = '573001112233';
const RX_MESSAGE = 'MENSAJE-DE-REACTIVACION';

beforeEach(function () {
    $this->freezeSecond();
    Queue::fake([SendProspectReactivation::class]);

    $this->modelCalls = 0;
    $this->whatsAppDown = false;

    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'api.openai.com')) {
            $this->modelCalls++;

            return Http::response(['choices' => [['message' => ['content' => json_encode(['reply' => 'RESPUESTA-DEL-MODELO', 'intent' => null, 'confidence' => 0.0])]]]]);
        }

        return $this->whatsAppDown
            ? Http::response(['error' => 'down'], 500)
            : Http::response(['messages' => [['id' => 'wamid.out.'.Str::random(8)]]]);
    });

    $this->store = makeStore(['conversation_mode' => Store::MODE_KNOWLEDGE, 'payment_instructions' => 'INSTRUCCIONES-PAGO']);
    $this->product = rxProduct($this->store, 'Asesor', 'tema');
    $this->versions = app(SalesFunnelVersions::class);
    rxPublish($this->product);
});

function rxProduct(Store $store, string $name, string $domain): Product
{
    Topic::firstOrCreate(['store_id' => $store->id, 'key' => $domain], ['label' => ucfirst($domain), 'instructions' => 'INSTRUCCIONES-TEMA']);
    $product = Product::create([
        'store_id' => $store->id, 'domain_key' => $domain, 'name' => $name, 'description' => 'DESCRIPCION', 'price' => 1, 'stock' => 1,
        'access_mode' => Product::ACCESS_SUBSCRIPTION,
    ]);
    Plan::create(['product_id' => $product->id, 'name' => 'Semanal', 'price' => 9900, 'duration_days' => 7, 'sort_order' => 1]);

    return $product;
}

function rxPublish(Product $product, ?string $message = RX_MESSAGE, int $hours = 2)
{
    return test()->versions->publish($product, GuidedFunnel::definition('INSTRUCCION', 3, $message, $hours));
}

/** What the webhook does (last_session_at on arrival), then the processing of the message. */
function rxSay(string $body, string $phone = RX_PHONE, ?int $productId = null): void
{
    Conversation::updateOrCreate(['store_id' => test()->store->id, 'customer_phone' => $phone], ['last_session_at' => now()]);
    ProcessWhatsAppMessage::dispatchSync(test()->store->fresh(), $phone, $body, 'wamid.in.'.Str::random(10), 'text', null, $productId);
    test()->travel(1)->seconds();
}

/** @return list<SendProspectReactivation> */
function rxJobs(): array
{
    return Queue::pushed(SendProspectReactivation::class)->values()->all();
}

function rxLastJob(): SendProspectReactivation
{
    $jobs = rxJobs();

    return end($jobs);
}

/** Through a real queue, with the job's middleware. */
function rxRun(SendProspectReactivation $job): void
{
    $queue = new SyncQueue;
    $queue->setContainer(app());
    $queue->setConnectionName('sync');
    $queue->push($job);
}

/** @return list<string> */
function rxSent(string $phone = RX_PHONE): array
{
    return collect(sentWhatsAppTexts())->filter(fn ($request) => $request->data()['to'] === $phone)
        ->map(fn ($request) => $request->data()['text']['body'])->values()->all();
}

function rxReactivations(string $phone = RX_PHONE): int
{
    return count(array_filter(rxSent($phone), fn ($body) => $body === RX_MESSAGE));
}

function rxConversation(string $phone = RX_PHONE): Conversation
{
    return Conversation::where('store_id', test()->store->id)->where('customer_phone', $phone)->first();
}

function rxCustomer(string $phone = RX_PHONE): Customer
{
    return Customer::where('store_id', test()->store->id)->where('customer_phone', $phone)->first();
}

/** A prospect who got a reply, then silence for the configured hours. */
function rxSilentProspect(string $body = 'Hola, quiero saber más', int $hours = 2): SendProspectReactivation
{
    rxSay($body);
    test()->travel($hours)->hours();

    return rxLastJob();
}

function rxPage(?Product $product = null)
{
    return Livewire::test(EditGuidedFunnel::class, ['record' => ($product ?? test()->product)->getRouteKey()]);
}

function rxSkipped(string $reason): void
{
    Log::shouldHaveReceived('info')->withArgs(fn ($event, $context = []) => $event === 'REACTIVATION_SKIPPED' && ($context['reason'] ?? null) === $reason)->atLeast()->once();
}

describe('sending', function () {
    it('queues one job for the episode, due the configured hours after the customer\'s last message', function () {
        rxSay('Hola, quiero saber más');

        $conversation = rxConversation();
        $job = rxLastJob();

        expect(rxJobs())->toHaveCount(1)
            ->and($job->episode)->toBe($conversation->last_session_at->format('Y-m-d H:i:s'))
            ->and($job->delay->equalTo($conversation->last_session_at->copy()->addHours(2)))->toBeTrue();
    });

    it('sends the literal message once, records it with its wamid and claims the episode, without the model', function () {
        $job = rxSilentProspect();
        $calls = $this->modelCalls;

        rxRun($job);

        $row = WhatsAppMessage::where('content', RX_MESSAGE)->first();

        expect(rxReactivations())->toBe(1)
            ->and($this->modelCalls)->toBe($calls)
            ->and($row->role)->toBe('assistant')
            ->and($row->wamid)->toStartWith('wamid.out.')
            ->and($row->domain_key)->toBe('tema')
            ->and(rxConversation()->reactivation_episode_at->equalTo(rxConversation()->last_session_at))->toBeTrue();
    });

    it('sends nothing before the hours have passed, and asks again once at the new time when they were raised', function () {
        rxSay('Hola');
        $job = rxLastJob();
        rxPublish($this->product, RX_MESSAGE, 5);
        $this->travel(2)->hours();

        rxRun($job);

        $again = rxLastJob();

        expect(rxReactivations())->toBe(0)
            ->and(rxJobs())->toHaveCount(2)
            ->and($again->rescheduled)->toBeTrue()
            ->and($again->delay->equalTo(rxConversation()->last_session_at->copy()->addHours(5)))->toBeTrue();

        rxRun($again);
        expect(rxReactivations())->toBe(0)->and(rxJobs())->toHaveCount(2);

        $this->travel(3)->hours();
        rxRun($again);
        expect(rxReactivations())->toBe(1);
    });

    it('uses the configured hours', function () {
        rxPublish($this->product, RX_MESSAGE, 7);
        $job = rxSilentProspect(hours: 7);

        expect($job->delay->equalTo(rxConversation()->last_session_at->copy()->addHours(7)))->toBeTrue();

        rxRun($job);

        expect(rxReactivations())->toBe(1);
    });
});

describe('who is a prospect', function () {
    it('excludes everyone who is not a prospect without a subscription', function (Closure $arrange, string $reason) {
        $job = rxSilentProspect();
        Log::spy();

        $arrange(rxCustomer(), rxConversation());
        rxRun($job);

        expect(rxReactivations())->toBe(0)
            ->and(rxConversation()->reactivation_episode_at)->toBeNull();
        rxSkipped($reason);
    })->with([
        'suscriptor' => [fn (Customer $customer) => Subscription::create(['customer_id' => $customer->id, 'product_id' => test()->product->id, 'started_at' => now(), 'expires_at' => now()->addDays(7)]), 'subscribed'],
        'vencido' => [fn (Customer $customer) => Subscription::create(['customer_id' => $customer->id, 'product_id' => test()->product->id, 'started_at' => now()->subDays(10), 'expires_at' => now()->subDays(3)]), 'expired'],
        'revocado' => [fn (Customer $customer) => Subscription::create(['customer_id' => $customer->id, 'product_id' => test()->product->id, 'started_at' => now()->subDay(), 'expires_at' => now()->addDays(6), 'status' => Subscription::STATUS_REVOKED]), 'revoked'],
        'producto abierto' => [fn () => test()->product->update(['access_mode' => Product::ACCESS_OPEN]), 'open'],
        'pago abierto' => [fn (Customer $customer) => Payment::create([
            'customer_id' => $customer->id, 'product_id' => test()->product->id, 'plan_id' => Plan::where('product_id', test()->product->id)->value('id'),
            'plan_name' => 'Semanal', 'amount' => 9900, 'currency' => 'COP', 'duration_days' => 7,
        ]), 'open_payment'],
        'atención humana' => [fn () => Lead::create(['store_id' => test()->store->id, 'customer_phone' => RX_PHONE, 'customer_name' => 'Cliente', 'summary' => 'Control', 'bot_active' => false]), 'human'],
        'pregunta pendiente' => [fn ($customer, Conversation $conversation) => $conversation->update(['pending_state' => ['type' => 'plan_choice']]), 'pending_state'],
        'sin respuesta del bot' => [fn () => WhatsAppMessage::create(['store_id' => test()->store->id, 'customer_phone' => RX_PHONE, 'role' => 'user', 'content' => 'Sin responder']), 'not_answered'],
        // The message may invite to a free question: never to who has none left.
        'sin respuestas gratuitas' => [fn (Customer $customer) => SampleDelivery::create(['store_id' => test()->store->id, 'customer_id' => $customer->id, 'product_id' => test()->product->id, 'turns_used' => 3]), 'no_free_reply'],
    ]);

    it('still reactivates who has free replies left, whatever they used', function () {
        $job = rxSilentProspect();
        SampleDelivery::create(['store_id' => $this->store->id, 'customer_id' => rxCustomer()->id, 'product_id' => $this->product->id, 'turns_used' => 2]);

        rxRun($job);

        expect(rxReactivations())->toBe(1);
    });

    it('lets who answers the reactivation go on in the normal flow', function () {
        rxRun(rxSilentProspect());
        $calls = $this->modelCalls;

        rxSay('Cuéntame más');

        expect(rxReactivations())->toBe(1)
            ->and($this->modelCalls)->toBeGreaterThan($calls)
            ->and(rxConversation()->reactivation_episode_at)->not->toBeNull()
            ->and(rxJobs())->toHaveCount(2);
    });

    it('leaves payments, subscriptions and leads untouched', function () {
        $job = rxSilentProspect();
        $before = [Payment::count(), Subscription::count(), Lead::count(), Customer::count()];

        rxRun($job);

        expect([Payment::count(), Subscription::count(), Lead::count(), Customer::count()])->toBe($before);
    });
});

describe('objections, refusals and stop requests', function () {
    it('still reactivates after an objection', function (string $objection) {
        rxRun(rxSilentProspect($objection));

        expect(rxReactivations())->toBe(1);
    })->with(['Lo voy a pensar', 'Está caro', 'Ahora no puedo', 'No sé si me sirva', 'No por ahora, lo pienso']);

    it('cancels the episode after a whole-message refusal, and reactivates a later one', function (string $refusal) {
        $job = rxSilentProspect($refusal);
        Log::spy();

        rxRun($job);

        expect(rxReactivations())->toBe(0)->and(rxConversation()->reactivation_opted_out_at)->toBeNull();
        rxSkipped('declined');

        rxRun(rxSilentProspect('Bueno, cuéntame otra vez'));

        expect(rxReactivations())->toBe(1);
    })->with(['No gracias', 'No, gracias.', 'NO ME INTERESA', 'Ya no quiero']);

    it('does not take a sentence that merely contains a refusal as one', function (string $message) {
        expect(GuidedFunnel::declines($message))->toBeFalse()->and(GuidedFunnel::asksToStop($message))->toBeFalse();
    })->with(['No me interesa el semanal, prefiero el mensual', 'No quiero pagar tanto, ¿hay otro plan?', 'Lo voy a pensar', '¿Por qué no me escribes más seguido?']);

    it('records a stop request for good, even when another message follows it', function (string $request) {
        rxSay($request);
        $job = rxSilentProspect('ok');
        Log::spy();

        rxRun($job);

        expect(rxReactivations())->toBe(0)->and(rxConversation()->reactivation_opted_out_at)->not->toBeNull();
        rxSkipped('opted_out');

        $jobs = count(rxJobs());
        $replies = count(rxSent());
        rxSay('Hola de nuevo, una consulta');

        expect(rxJobs())->toHaveCount($jobs)
            ->and(rxSent())->toHaveCount($replies + 1);
    })->with(['Por favor no me escribas más', 'Por favor, no me escribas más', 'Dejen de escribirme, gracias', 'STOP', 'Hola, dame de baja por favor']);

    it('takes as a stop request only the whole message, greetings and courtesies aside', function (string $message, bool $stops) {
        expect(GuidedFunnel::asksToStop($message))->toBe($stops);
    })->with([
        'STOP' => ['STOP', true],
        'con cortesía al inicio' => ['Por favor, no me escribas más', true],
        'con saludo y cortesía' => ['Hola, no me escribas más, gracias', true],
        'una cita' => ['Mi ex me dijo: no me escribas más', false],
        'otra cita' => ['Ella me escribió "deja de escribirme", ¿qué hago?', false],
        'stop loss' => ['¿Cómo pongo un stop loss?', false],
        'stop motion' => ['el stop motion', false],
        'baja de otro servicio' => ['¿Cómo me doy de baja del gimnasio?', false],
        'darme de baja de otra app' => ['quiero darme de baja de otra app', false],
        'dentro de una frase' => ['Quiero que me den de baja, dame de baja', false],
    ]);

    it('keeps the conversation reactivable after a phrase quoted inside a longer message', function () {
        rxSay('Mi ex me dijo: no me escribas más');

        rxRun(rxSilentProspect('¿Qué le respondo?'));

        expect(rxReactivations())->toBe(1)->and(rxConversation()->reactivation_opted_out_at)->toBeNull();
    });

    it('reactivates each new episode once', function () {
        rxRun(rxSilentProspect());
        rxRun(rxSilentProspect('Gracias'));

        expect(rxReactivations())->toBe(2)->and(rxConversation()->reactivation_opted_out_at)->toBeNull();
    });
});

describe('a newer message', function () {
    it('invalidates the earlier job: only the job of the last message sends', function () {
        rxSay('Primero');
        $first = rxLastJob();
        $this->travel(90)->minutes();
        rxSay('Segundo');
        $second = rxLastJob();
        $this->travel(2)->hours();
        Log::spy();

        rxRun($first);
        expect(rxReactivations())->toBe(0);
        rxSkipped('newer_message');

        rxRun($second);
        expect(rxReactivations())->toBe(1);
    });

    it('invalidates the job when it arrives on the webhook, before any processing', function () {
        $job = rxSilentProspect();
        rxConversation()->update(['last_session_at' => now()]);

        rxRun($job);

        expect(rxReactivations())->toBe(0);
    });

    it('drops the job while a message of the same customer is being processed, sharing its lock', function () {
        $job = rxSilentProspect();
        $processing = new ProcessWhatsAppMessage($this->store, RX_PHONE, 'x', 'wamid.x');

        expect($job->middleware()[0]->getLockKey($job))->toBe($processing->middleware()[0]->getLockKey($processing));

        $lock = Cache::lock($processing->middleware()[0]->getLockKey($processing), 120);
        $lock->get();

        rxRun($job);
        expect(rxReactivations())->toBe(0)->and(rxConversation()->reactivation_episode_at)->toBeNull();

        $lock->release();
    });
});

describe('one reactivation per episode', function () {
    it('sends once when several jobs of the same episode run', function () {
        $job = rxSilentProspect();

        rxRun($job);
        rxRun(clone $job);
        rxRun(new SendProspectReactivation($this->store->id, RX_PHONE, $job->episode));

        expect(rxReactivations())->toBe(1);
    });

    it('does not reactivate again after its own message', function () {
        $job = rxSilentProspect();

        rxRun($job);
        $this->travel(5)->hours();
        rxRun($job);

        expect(rxReactivations())->toBe(1);
    });
});

describe('WhatsApp\'s window', function () {
    it('sends nothing, neither text nor template, when the window is closed', function () {
        rxPublish($this->product, RX_MESSAGE, 23);
        rxSay('Hola');
        $job = rxLastJob();
        $this->travel(23)->hours();
        $this->travel(31)->minutes();
        $requests = Http::recorded()->count();
        Log::spy();

        rxRun($job);

        expect(Http::recorded()->count())->toBe($requests)
            ->and(WhatsAppMessage::where('content', RX_MESSAGE)->exists())->toBeFalse()
            ->and(rxConversation()->reactivation_episode_at)->toBeNull();
        rxSkipped('window_closed');
    });

    it('sends nothing when the job runs too late for the window', function () {
        $job = rxSilentProspect();
        $this->travel(22)->hours();

        rxRun($job);

        expect(rxReactivations())->toBe(0);
    });
});

describe('a failed send', function () {
    it('records no message and no success, and is never retried', function () {
        $job = rxSilentProspect();
        $this->whatsAppDown = true;
        Log::spy();

        rxRun($job);

        expect(WhatsAppMessage::where('content', RX_MESSAGE)->exists())->toBeFalse()
            ->and(rxConversation()->reactivation_episode_at)->not->toBeNull();
        Log::shouldHaveReceived('info')->withArgs(fn ($event, $context = []) => $event === 'REACTIVATION_FAILED' && $context['wamid'] === null)->once();
        Log::shouldNotHaveReceived('info', fn ($event) => $event === 'REACTIVATION_SENT');

        $this->whatsAppDown = false;
        rxRun($job);

        // The one attempt Meta refused, and no other.
        expect(rxReactivations())->toBe(1)
            ->and(WhatsAppMessage::where('content', RX_MESSAGE)->exists())->toBeFalse();
    });
});

describe('turning it off', function () {
    it('stops pending jobs when the message is emptied, the funnel deactivated or no longer guided', function (Closure $turnOff) {
        $job = rxSilentProspect();

        $turnOff();
        rxRun($job);

        expect(rxReactivations())->toBe(0);
    })->with([
        'mensaje vacío' => [fn () => rxPublish(test()->product, null)],
        'mensaje en blanco' => [fn () => rxPublish(test()->product, '   ')],
        'embudo desactivado' => [fn () => test()->versions->deactivate(test()->product->fresh())],
        'embudo por etapas' => [fn () => test()->versions->publish(test()->product, ['name' => 'N', 'goal' => 'G', 'stages' => [['key' => 'a', 'type' => 'ai', 'goal' => 'g', 'instructions' => 'i']]])],
    ]);

    it('queues nothing without a message', function () {
        rxPublish($this->product, null);

        rxSay('Hola');

        expect(rxJobs())->toBe([]);
    });
});

describe('the definition', function () {
    it('keeps the message and hours, and no key without a message', function () {
        expect(GuidedFunnel::definition('I', 3, '  Hola  ', 4)['reactivation'])->toBe(['message' => 'Hola', 'after_hours' => 4])
            ->and(GuidedFunnel::definition('I', 3))->not->toHaveKey('reactivation')
            ->and(GuidedFunnel::definition('I', 3, ''))->not->toHaveKey('reactivation')
            ->and(GuidedFunnel::definition('I', 3, 'Hola')['reactivation']['after_hours'])->toBe(2);
    });

    it('rejects hours outside 1 to 23, a long message, and a reactivation outside a guided funnel', function (Closure $definition) {
        expect(fn () => FunnelDefinition::fromArray($definition()))->toThrow(InvalidArgumentException::class);
    })->with([
        'cero horas' => [fn () => GuidedFunnel::definition('I', 3, 'Hola', 0)],
        '24 horas' => [fn () => GuidedFunnel::definition('I', 3, 'Hola', 24)],
        'muy largo' => [fn () => GuidedFunnel::definition('I', 3, str_repeat('x', GuidedFunnel::MAX_REACTIVATION_LENGTH + 1))],
        'no guiado' => [fn () => ['name' => 'N', 'goal' => 'G', 'stages' => [['key' => 'a', 'type' => 'ai', 'goal' => 'g', 'instructions' => 'i']], 'reactivation' => ['message' => 'Hola', 'after_hours' => 2]]],
    ]);

    it('accepts 1 and 23 hours', function (int $hours) {
        expect(FunnelDefinition::fromArray(GuidedFunnel::definition('I', 3, 'Hola', $hours))->guided)->toBeTrue();
    })->with([1, 23]);
});

describe('the panel', function () {
    beforeEach(function () {
        $this->actingAs(User::factory()->create(['store_id' => $this->store->id, 'is_super_admin' => false]));
    });

    it('opens with the active message and hours, and publishes them', function () {
        rxPage()->assertFormSet(['reactivation_message' => RX_MESSAGE, 'reactivation_hours' => 2])
            ->fillForm(['reactivation_message' => 'OTRO-MENSAJE', 'reactivation_hours' => 6])
            ->callAction('publish', data: ['confirmed' => true])
            ->assertHasNoActionErrors();

        expect(GuidedFunnel::reactivation($this->product->fresh()->activeFunnel()->definition))->toBe(['message' => 'OTRO-MENSAJE', 'after_hours' => 6]);
    });

    it('defaults to 2 hours and publishes without a reactivation when the message is empty', function () {
        $other = rxProduct($this->store, 'Otro', 'tema');

        rxPage($other)->assertFormSet(['reactivation_message' => null, 'reactivation_hours' => 2])
            ->fillForm(['instructions' => 'Vende.', 'free_replies' => 3, 'reactivation_message' => ''])
            ->callAction('publish', data: ['confirmed' => true])
            ->assertHasNoActionErrors();

        expect($other->fresh()->activeFunnel()->definition)->not->toHaveKey('reactivation');
    });

    it('rejects hours outside 1 to 23 and a message that is too long', function (array $data, array $errors) {
        rxPage()->fillForm($data)->callAction('checkDefinition')->assertHasFormErrors($errors);
    })->with([
        [['reactivation_hours' => 0], ['reactivation_hours' => 'min']],
        [['reactivation_hours' => 24], ['reactivation_hours' => 'max']],
        [['reactivation_hours' => null], ['reactivation_hours' => 'required']],
        [['reactivation_message' => str_repeat('x', GuidedFunnel::MAX_REACTIVATION_LENGTH + 1)], ['reactivation_message' => 'max']],
    ]);
});

describe('any product', function () {
    it('sends each product its own message, with nothing of a sector in the Core', function () {
        $idiomas = rxProduct($this->store, 'Curso de inglés', 'idiomas');
        $nutricion = rxProduct($this->store, 'Asesoría Nutricional', 'nutricion');
        rxPublish($idiomas, 'MENSAJE-IDIOMAS');
        rxPublish($nutricion, 'MENSAJE-NUTRICION', 3);

        rxSay('Hola', '573000000001', $idiomas->id);
        $first = rxLastJob();
        rxSay('Hola', '573000000002', $nutricion->id);
        $second = rxLastJob();
        $this->travel(3)->hours();

        rxRun($first);
        rxRun($second);

        expect(rxSent('573000000001'))->toContain('MENSAJE-IDIOMAS')->not->toContain('MENSAJE-NUTRICION')
            ->and(rxSent('573000000002'))->toContain('MENSAJE-NUTRICION')->not->toContain('MENSAJE-IDIOMAS');

        $global = collect(config('botinker.sales_funnels.guided'))->flatten()->implode(' ');
        $code = file_get_contents(app_path('Jobs/SendProspectReactivation.php'));

        foreach (['coach', 'seducci', 'mujer', 'inglés', 'nutri', 'tarot', 'Asesor', 'MAX'] as $term) {
            expect(str_contains($global, $term))->toBeFalse("La configuración global menciona «{$term}».")
                ->and(str_contains($code, $term))->toBeFalse("El job menciona «{$term}».");
        }
    });
});
