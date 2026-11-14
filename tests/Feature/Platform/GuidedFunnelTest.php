<?php

/*
 * A funnel written as one instruction: the administrator's panel and the
 * conversation that results, through the same engine, access, free replies and
 * commerce as any other funnel. The model and WhatsApp are simulated, so these
 * tests prove what the model receives and what the Core does with its answers,
 * not how a real model decides or words them.
 */

use App\Filament\Resources\Products\Pages\EditGuidedFunnel;
use App\Filament\Resources\Products\Pages\ManageProductFunnel;
use App\Filament\Resources\Products\Pages\ViewFunnelVersion;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Products\Schemas\FunnelPublicationChecks;
use App\Filament\Resources\Products\Schemas\FunnelPublicationPreview;
use App\Filament\Resources\Products\Schemas\FunnelRestorePreview;
use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Conversation;
use App\Models\ConversationFunnelState;
use App\Models\KnowledgeSource;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Product;
use App\Models\SalesFunnel;
use App\Models\SampleDelivery;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\Topic;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Platform\Commerce\CommercialAdvisor;
use App\Platform\Commerce\CommercialClaims;
use App\Platform\Commerce\OpeningMessage;
use App\Platform\Commerce\SampleLedger;
use App\Platform\Conversation\PromptAssembler;
use App\Platform\Funnel\FunnelDefinition;
use App\Platform\Funnel\FunnelStates;
use App\Platform\Funnel\GuidedFunnel;
use App\Platform\Funnel\SalesFunnelVersions;
use App\Platform\Funnel\StageReply;
use App\Platform\Knowledge\KnowledgeIngestor;
use App\Platform\Knowledge\KnowledgePlanner;
use App\Platform\Knowledge\KnowledgeRetriever;
use App\Platform\Memory\CustomerMemory;
use App\Platform\Memory\PersonFacts;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Livewire;

const GF_PHONE = '573007778899';

beforeEach(function () {
    $this->freezeSecond();

    $this->replies = [];
    $this->prompts = [];
    $this->turns = [];
    $this->turnPrompts = [];
    $this->selectorCalls = 0;
    $this->chats = [];
    $this->sections = [];
    $this->needsKnowledge = true;
    $this->verdicts = [];
    $this->verifierPrompts = [];
    $this->verifierDown = false;
    $this->modelDown = false;
    $this->selectorDown = false;
    $this->whatsAppDown = false;
    $this->whatsAppAmbiguous = false;
    $this->schemaRefused = false;
    $this->transcriptions = [];
    $this->transcriberDown = false;

    Http::fake(function (Request $request) {
        // A voice note: its media, its file and its transcription.
        if (str_contains($request->url(), 'api.openai.com/v1/audio/transcriptions')) {
            if ($this->transcriberDown) {
                return Http::response(['error' => 'upstream'], 500);
            }

            return Http::response(['text' => array_shift($this->transcriptions) ?? 'AUDIO-SIN-TEXTO']);
        }

        if (str_starts_with($request->url(), 'https://graph.facebook.com/v20.0/media-')) {
            return Http::response(['url' => 'https://lookaside.fbsbx.com/'.basename($request->url())]);
        }

        if (str_starts_with($request->url(), 'https://lookaside.fbsbx.com/')) {
            return Http::response('OggS-audio', 200, ['Content-Type' => 'audio/ogg']);
        }

        if (str_contains($request->url(), 'api.openai.com')) {
            // A model without Structured Outputs refuses the request that carries a schema.
            if ($this->schemaRefused && isset($request->data()['response_format'])) {
                return Http::response(['error' => ['code' => 'invalid_request_error']], 400);
            }

            $system = $request->data()['messages'][0]['content'];
            $this->chats[] = $request->data()['messages'];

            if (str_contains($system, 'Eres un selector de conocimiento')) {
                $this->selectorCalls++;

                if ($this->selectorDown) {
                    return Http::response(['error' => 'upstream'], 500);
                }

                return Http::response(['choices' => [['message' => ['content' => json_encode(['needs_knowledge' => $this->needsKnowledge, 'sections' => $this->sections])]]]]);
            }

            if (str_contains($system, 'Eres un verificador')) {
                $this->verifierPrompts[] = $system;

                return $this->verifierDown
                    ? Http::response(['error' => 'upstream'], 500)
                    : Http::response(['choices' => [['message' => ['content' => array_shift($this->verdicts) ?? json_encode(['claims' => []])]]]]);
            }

            if ($this->modelDown) {
                return Http::response(['error' => 'upstream'], 500);
            }

            // Every message of the stage is first a commercial turn, with its own answers: by
            // default it reads a request for content, which the free reply answers while there is one.
            if (str_contains($system, 'Este es un turno comercial')) {
                $this->turnPrompts[] = $system;

                return Http::response(['choices' => [['message' => ['content' => array_shift($this->turns) ?? gfJson('', question: 'contenido')]]]]);
            }

            $this->prompts[] = $system;

            return Http::response(['choices' => [['message' => ['content' => array_shift($this->replies) ?? gfJson('RESPUESTA-DEL-MODELO')]]]]);
        }

        // Ambiguous: Meta answers 200 but with no message id, so the Core cannot tell the message went out.
        if ($this->whatsAppAmbiguous) {
            return Http::response(['messages' => []]);
        }

        return $this->whatsAppDown
            ? Http::response(['error' => 'down'], 500)
            : Http::response(['messages' => [['id' => 'wamid.out.'.Str::random(8)]]]);
    });

    $this->store = makeStore(['conversation_mode' => Store::MODE_KNOWLEDGE, 'payment_instructions' => 'INSTRUCCIONES-PAGO', 'system_prompt' => 'PERSONA-TIENDA']);
    Topic::create(['store_id' => $this->store->id, 'key' => 'tema', 'label' => 'Tema', 'instructions' => 'INSTRUCCIONES-TEMA']);
    $this->product = Product::create([
        'store_id' => $this->store->id, 'domain_key' => 'tema', 'name' => 'Asesor', 'description' => 'DESCRIPCION-PRODUCTO', 'price' => 1, 'stock' => 1,
        'access_mode' => Product::ACCESS_SUBSCRIPTION,
        'ai_sales_strategy' => 'SERVICIO-RESERVADO',
        'faq_context' => 'FAQ-PRODUCTO',
        'commercial_instructions' => 'COMERCIAL-ANTIGUA: no invites a comprar',
    ]);
    Plan::create(['product_id' => $this->product->id, 'name' => 'Semanal', 'price' => 9900, 'duration_days' => 7, 'sort_order' => 1]);
    Plan::create(['product_id' => $this->product->id, 'name' => 'Mensual', 'price' => 19900, 'duration_days' => 30, 'sort_order' => 2]);

    $this->versions = app(SalesFunnelVersions::class);
});

function gfJson(string $reply, ?string $intent = null, float $confidence = 0.0, ?string $question = null, ?string $fallback = null): string
{
    return json_encode(['reply' => $reply, 'intent' => $intent, 'confidence' => $confidence]
        + ($question !== null ? ['question' => $question] : [])
        + ($fallback !== null ? ['fallback_reply' => $fallback] : []), JSON_UNESCAPED_UNICODE);
}

function gfPublish(string $instructions = 'INSTRUCCION-DEL-ADMINISTRADOR', int $free = 3): SalesFunnel
{
    return test()->versions->publish(test()->product, GuidedFunnel::definition($instructions, $free));
}

function gfSay(string $body, string $phone = GF_PHONE): void
{
    ProcessWhatsAppMessage::dispatchSync(test()->store->fresh(), $phone, $body, 'wamid.in.'.Str::random(10), 'text', null);
    test()->travel(1)->seconds();
}

function gfLast(): string
{
    $sent = sentWhatsAppTexts();

    return end($sent)->data()['text']['body'];
}

function gfLastPrompt(): string
{
    $prompts = test()->prompts;

    return end($prompts);
}

function gfLastTurnPrompt(): string
{
    $prompts = test()->turnPrompts;

    return end($prompts);
}

function gfGraceReads(): int
{
    return (int) (ConversationFunnelState::where('product_id', test()->product->id)->first()?->context['grace_reads'] ?? 0);
}

/** The conversation's five commercial turns, already spent. */
function gfSpendReads(): void
{
    $conversation = Conversation::firstOrCreate(['store_id' => test()->store->id, 'customer_phone' => GF_PHONE]);

    foreach (range(1, 5) as $read) {
        app(FunnelStates::class)->takeLimitRead($conversation, test()->product, test()->product->activeFunnel(), GuidedFunnel::STAGE, "wamid.gastado.{$read}", 5);
    }
}

function gfReads(): int
{
    return (int) (ConversationFunnelState::where('product_id', test()->product->id)->first()?->context['limit_reads'] ?? 0);
}

function gfTurns(): int
{
    return (int) SampleDelivery::where('product_id', test()->product->id)->value('turns_used');
}

function gfSource(string $title, string $marker, string $purpose, string $delivery = KnowledgeSource::DELIVERY_REFERENCE): void
{
    app(KnowledgeIngestor::class)->ingest(test()->store, 'tema', test()->product, 'Fuente', 'markdown', "# {$title}\n\n{$marker}", origin: 'origen-'.Str::random(6), purpose: $purpose, delivery: $delivery);
}

function gfSeedTurn(?string $domain, string $question, string $answer): void
{
    foreach (['user' => $question, 'assistant' => $answer] as $role => $content) {
        WhatsAppMessage::create(['store_id' => test()->store->id, 'customer_phone' => GF_PHONE, 'role' => $role, 'domain_key' => $domain, 'content' => $content]);
    }
}

/** Two turns saved within the same second, as a delivered turn is. */
function gfSeedTurns(?string $domain): void
{
    gfSeedTurn($domain, 'M1', 'R1');
    gfSeedTurn($domain, 'M2', 'R2');
}

/** @return list<string> role:content of the first model call carrying that message, without its system prompt */
function gfHistorySentWith(string $content): array
{
    $chat = collect(test()->chats)->first(fn (array $messages) => collect($messages)->contains('content', $content));

    return collect($chat)->reject(fn (array $message) => $message['role'] === 'system')->map(fn (array $message) => "{$message['role']}:{$message['content']}")->values()->all();
}

function gfPage(?int $from = null)
{
    return Livewire::withQueryParams($from !== null ? ['from' => $from] : [])
        ->test(EditGuidedFunnel::class, ['record' => test()->product->getRouteKey()]);
}

describe('the definition', function () {
    it('is one AI stage the engine accepts, with fixed rules and every context', function () {
        $definition = FunnelDefinition::fromArray(GuidedFunnel::definition('  Vende con calma.  ', 5));
        $stage = $definition->start();

        expect($definition->guided)->toBeTrue()
            ->and($definition->stages)->toHaveCount(1)
            ->and($definition->freeRepliesLimit)->toBe(5)
            ->and($definition->openingConsumesFreeReply)->toBeFalse()
            ->and($stage->instructions)->toBe('Vende con calma.')
            ->and($stage->usesFreeReplies)->toBeTrue()
            ->and($stage->context)->toBe(FunnelDefinition::CONTEXTS)
            ->and(array_column($stage->rules, 'action'))->toBe(['start_payment', 'start_payment', 'show_offer']);
    });

    it('rejects a guided definition that the free replies could not limit', function () {
        $definition = GuidedFunnel::definition('Vende.', 3);

        foreach ([
            array_replace_recursive($definition, ['free_replies' => ['limit' => 0]]),
            array_replace_recursive($definition, ['stages' => [['uses_free_replies' => false]]]),
            array_merge($definition, ['stages' => [...$definition['stages'], ['key' => 'otra', 'type' => 'ai', 'goal' => 'g', 'instructions' => 'i']]]),
            GuidedFunnel::definition(str_repeat('x', GuidedFunnel::MAX_INSTRUCTIONS_LENGTH + 1), 3),
        ] as $invalid) {
            expect(fn () => FunnelDefinition::fromArray($invalid))->toThrow(InvalidArgumentException::class);
        }
    });

    it('leaves the contract of a funnel made of stages as it was', function () {
        $definition = FunnelDefinition::fromArray(['name' => 'N', 'goal' => 'G', 'stages' => [['key' => 'a', 'type' => 'ai', 'goal' => 'g', 'instructions' => 'i']]]);

        expect($definition->guided)->toBeFalse();
    });
});

describe('the panel', function () {
    beforeEach(function () {
        $this->actingAs(User::factory()->create(['store_id' => $this->store->id, 'is_super_admin' => false]));
    });

    it('asks only for the instruction and the free replies, with no stages, rules or intents', function () {
        $response = $this->get(ProductResource::getUrl('funnel-guided', ['record' => $this->product]));

        $response->assertOk()
            ->assertSee('Instrucciones para la IA')
            ->assertSee('Cantidad de respuestas gratuitas')
            ->assertSee('Sin embudo activo');

        foreach (['Agregar etapa', 'Agregar regla', 'Agregar intención', 'Etapa inicial', 'Clave', 'Intenciones'] as $hidden) {
            $response->assertDontSee($hidden);
        }
    });

    it('publishes an instruction as a new active version, after confirming', function () {
        gfPage()
            ->fillForm(['instructions' => 'Vende con calma.', 'free_replies' => 4])
            ->callAction('publish', data: ['confirmed' => true])
            ->assertHasNoActionErrors()
            ->assertNotified('Se publicó y activó la versión v1.');

        $active = $this->product->fresh()->activeFunnel();

        expect($active->version)->toBe(1)
            ->and(GuidedFunnel::instructions($active->definition))->toBe('Vende con calma.')
            ->and($active->funnelDefinition()->freeRepliesLimit)->toBe(4);
    });

    it('checks the definition without storing anything', function () {
        gfPage()
            ->fillForm(['instructions' => 'Vende con calma.', 'free_replies' => 4])
            ->callAction('checkDefinition')
            ->assertNotified('La definición es válida.');

        expect(SalesFunnel::where('product_id', $this->product->id)->count())->toBe(0);
    });

    it('refuses an empty instruction and a limit that would not limit the model', function () {
        gfPage()->fillForm(['instructions' => '', 'free_replies' => 3])->callAction('checkDefinition')->assertHasFormErrors(['instructions' => 'required']);
        gfPage()->fillForm(['instructions' => 'Vende.', 'free_replies' => 0])->callAction('checkDefinition')->assertHasFormErrors(['free_replies' => 'min']);
        gfPage()->fillForm(['instructions' => 'Vende.', 'free_replies' => 2.5])->callAction('checkDefinition')->assertHasFormErrors(['free_replies' => 'integer']);

        expect(SalesFunnel::where('product_id', $this->product->id)->count())->toBe(0);
    });

    it('edits from the active version or from any other one, as a new version', function () {
        gfPublish('PRIMERA', 3);
        gfPublish('SEGUNDA', 5);

        gfPage()->assertFormSet(['instructions' => 'SEGUNDA', 'free_replies' => 5]);

        gfPage(from: 1)
            ->assertFormSet(['instructions' => 'PRIMERA', 'free_replies' => 3])
            ->fillForm(['instructions' => 'TERCERA'])
            ->callAction('publish', data: ['confirmed' => true]);

        $active = $this->product->fresh()->activeFunnel();

        expect($active->version)->toBe(3)
            ->and(GuidedFunnel::instructions($active->definition))->toBe('TERCERA')
            ->and(SalesFunnel::where('product_id', $this->product->id)->where('version', 1)->first()->definition['stages'][0]['instructions'])->toBe('PRIMERA');
    });

    it('refuses to publish over a version someone else published meanwhile', function () {
        gfPublish('PRIMERA');

        $page = gfPage()->fillForm(['instructions' => 'MIA']);

        gfPublish('AJENA');

        $page->callAction('publish', data: ['confirmed' => true]);

        expect(GuidedFunnel::instructions($this->product->fresh()->activeFunnel()->definition))->toBe('AJENA');
    });

    it('restores a version from the history, as a new active copy, and the conversation follows it', function () {
        $first = gfPublish('PRIMERA');
        gfPublish('SEGUNDA');

        Livewire::test(ManageProductFunnel::class, ['record' => $this->product->getRouteKey()])
            ->callAction(TestAction::make('restore')->table($first));

        $active = $this->product->fresh()->activeFunnel();

        expect($active->version)->toBe(3)
            ->and($active->restored_from_id)->toBe($first->id)
            ->and(GuidedFunnel::instructions($active->definition))->toBe('PRIMERA');

        gfSay('Hola, ¿de qué se trata?');

        expect(gfLastPrompt())->toContain('PRIMERA')->not->toContain('SEGUNDA');
    });

    it('shows versions and differences in terms of the instruction', function () {
        gfPublish('PRIMERA', 3);
        $second = gfPublish('SEGUNDA', 5);

        $this->get(ProductResource::getUrl('funnel', ['record' => $this->product]))
            ->assertOk()->assertSee('SEGUNDA')->assertDontSee('Etapa inicial')->assertDontSee('conversacion');

        Livewire::test(ViewFunnelVersion::class, ['record' => $this->product->getRouteKey(), 'version' => $second->version])
            ->assertOk()->assertSee('SEGUNDA')->assertDontSee('Etapa:')->assertDontSee('Reglas, en su orden')->assertDontSee('presentar_oferta');

        expect(FunnelRestorePreview::differences($second->definition, GuidedFunnel::definition('TERCERA', 2)))
            ->toBe(['Instrucciones para la IA: «SEGUNDA» → «TERCERA»', 'Respuestas gratuitas: «5» → «2»'])
            ->and(FunnelRestorePreview::differences($second->definition, $second->definition))->toBe(['Sin diferencias de configuración.']);

        $preview = collect(FunnelPublicationPreview::components($this->product->fresh(), GuidedFunnel::definition('TERCERA', 2), []));

        expect($preview->first(fn ($entry) => $entry->getName() === 'publish_displaced')->isVisible())->toBeFalse()
            ->and($preview->first(fn ($entry) => $entry->getName() === 'publish_conversations_warning')->getState())->not->toContain('etapa');
    });

    it('warns about amounts where the model would read them, and about a payment that cannot start', function () {
        $this->product->update(['faq_context' => 'Vale $19.900 al mes']);
        Topic::where('key', 'tema')->update(['instructions' => 'Di que cuesta 9.900 pesos']);
        $this->store->update(['payment_instructions' => null]);

        $warnings = implode("\n", FunnelPublicationChecks::warnings($this->product->fresh(), GuidedFunnel::definition('Ofrécelo a $5.000', 3)));

        expect($warnings)
            ->toContain('Las instrucciones para la IA parecen incluir un precio')
            ->toContain('Las preguntas frecuentes del producto parecen incluir un precio')
            ->toContain('Las instrucciones del tema parecen incluir un precio')
            ->not->toContain('La descripción del producto')
            ->toContain('la tienda no tiene instrucciones')
            ->not->toContain('Ciclo')
            ->not->toContain('etapa «');
    });

    it('keeps another store\'s product out of reach', function () {
        $foreign = Product::create(['store_id' => makeStore()->id, 'domain_key' => 'tema', 'name' => 'Ajeno', 'description' => 'x', 'price' => 1, 'stock' => 1, 'access_mode' => Product::ACCESS_SUBSCRIPTION]);

        $this->get(ProductResource::getUrl('funnel-guided', ['record' => $foreign]))->assertNotFound();
    });
});

describe('the conversation', function () {
    beforeEach(function () {
        gfPublish();
    });

    it('converses with questions and objections, spending free replies and moving nothing', function () {
        $this->replies = [gfJson('Claro, te cuento cómo funciona.'), gfJson('Te entiendo, pensémoslo juntos.')];

        gfSay('¿Esto cómo funciona?');
        expect(gfLast())->toBe('Claro, te cuento cómo funciona.')->and(gfTurns())->toBe(1);

        gfSay('Mmm, no sé si me sirva');
        expect(gfLast())->toBe('Te entiendo, pensémoslo juntos.')->and(gfTurns())->toBe(2)
            ->and(Payment::count())->toBe(0)
            ->and($this->prompts)->toHaveCount(2);
    });

    it('gives the model the instruction, the product, the topic and the plans without amounts', function () {
        gfSay('¿Qué planes tienen disponibles para mí exactamente?, cuéntame de qué se trata');

        expect(gfLastPrompt())
            ->toContain('INSTRUCCION-DEL-ADMINISTRADOR')
            ->toContain('DESCRIPCION-PRODUCTO')
            ->toContain('FAQ-PRODUCTO')
            ->toContain('INSTRUCCIONES-TEMA')
            ->toContain('- Semanal: 7 días')
            ->toContain('- Mensual: 30 días')
            ->toContain('Esta es la respuesta gratuita 1 de 3')
            ->not->toContain('9900')
            ->not->toContain('9.900')
            ->not->toContain('19900')
            ->not->toContain('19.900');
    });

    it('lets the funnel\'s instruction prevail over the product\'s commercial instructions', function () {
        gfSay('¿De qué se trata?');

        expect(gfLastPrompt())
            ->not->toContain('COMERCIAL-ANTIGUA')
            ->not->toContain('No menciones precios, planes, descuentos')
            ->toContain('prevalece sobre cualquier otra indicación comercial');
    });

    it('shows the offer when the model proposes it: its reply and then the catalog\'s prices', function () {
        $this->replies = [gfJson('Esto te ayudaría mucho.', GuidedFunnel::INTENT_OFFER, 0.9)];

        gfSay('Me parece interesante');

        expect(gfLast())
            ->toStartWith('Esto te ayudaría mucho.')
            ->toContain('1. Semanal: $9.900 COP por 7 días')
            ->toContain('2. Mensual: $19.900 COP por 30 días')
            ->and(Payment::count())->toBe(0);
    });

    it('does not act on a proposal the model is not sure of, nor on one it was not offered', function () {
        $this->replies = [gfJson('Sigamos.', GuidedFunnel::INTENT_BUY, 0.5), gfJson('Sigamos otra vez.', 'regalar_acceso', 1.0)];

        gfSay('Quizá');
        expect(gfLast())->toBe('Sigamos.');

        gfSay('Tal vez');
        expect(gfLast())->toBe('Sigamos otra vez.')->and(Payment::count())->toBe(0);
    });

    it('starts the payment when the customer names a plan, with no model call', function () {
        gfSay('Quiero el semanal');

        $payment = Payment::first();

        expect($payment->plan->name)->toBe('Semanal')
            ->and(gfLast())->toContain('$9.900')->toContain('INSTRUCCIONES-PAGO')
            ->and($this->prompts)->toBe([]);
    });

    it('asks which plan when the model reads a purchase, without spending a free reply, and then starts the payment', function () {
        $this->replies = [gfJson('Perfecto.', GuidedFunnel::INTENT_BUY, 0.95)];

        gfSay('Listo, me animo');

        expect(gfLast())->toContain('¿Qué plan de «Asesor» quieres?')->not->toContain('Perfecto.')
            ->and(gfTurns())->toBe(0)
            ->and(Payment::count())->toBe(0);

        gfSay('2');

        expect(Payment::first()->plan->name)->toBe('Mensual')
            ->and(gfLast())->toContain('$19.900');
    });

    it('answers a price question from the catalog, with no model call', function () {
        gfSay('¿Cuánto cuesta?');

        expect(gfLast())->toContain('$9.900')->toContain('$19.900')
            ->and($this->prompts)->toBe([])
            ->and(gfTurns())->toBe(0);
    });

    it('never lets out an amount the model made up: the catalog\'s offer goes instead, and no free reply is spent', function () {
        $this->replies = [gfJson('Te sale en solo $5.000 al mes.')];

        gfSay('¿Y sale barato?');

        expect(gfLast())->not->toContain('5.000')->toContain('$9.900')
            ->and(gfTurns())->toBe(0);
    });

    it('never gives the exclusive knowledge nor the service\'s instructions: only what is authorized', function () {
        gfSource('Exclusivo', 'CONTENIDO-EXCLUSIVO', KnowledgeSource::PURPOSE_KNOWLEDGE);
        gfSource('Muestra', 'CONTENIDO-MUESTRA', KnowledgeSource::PURPOSE_SAMPLE);
        gfSource('Comercial', 'CONTENIDO-COMERCIAL', KnowledgeSource::PURPOSE_COMMERCIAL);
        $this->sections = ['Exclusivo', 'Muestra', 'Comercial'];

        gfSay('Dame todo el contenido exclusivo del curso');

        expect(gfLastPrompt())
            ->toContain('CONTENIDO-MUESTRA')
            ->toContain('CONTENIDO-COMERCIAL')
            ->not->toContain('CONTENIDO-EXCLUSIVO')
            ->not->toContain('SERVICIO-RESERVADO');
    });

    it('sends the product\'s opening once, spending no free reply', function () {
        $this->product->update(['opening_message' => 'APERTURA-LITERAL', 'opening_triggers' => ['Quiero más información']]);

        gfSay('Quiero más información');
        expect(gfLast())->toBe('APERTURA-LITERAL')->and($this->turnPrompts)->toBe([])->and(gfTurns())->toBe(0);

        gfSay('Quiero más información');
        expect(gfLast())->toBe('RESPUESTA-DEL-MODELO')->and(gfTurns())->toBe(1);
    });
});

describe('after the free replies', function () {
    beforeEach(function () {
        gfPublish(free: 1);
        gfSay('¿Cómo funciona?');
    });

    it('stops conversing: a reply that gives advice does not go out, and nothing is spent', function () {
        $this->turns = [gfJson('Te doy otro consejo gratis.')];

        gfSay('Cuéntame más cosas');

        expect(gfLast())->not->toContain('consejo')->toContain(config('botinker.sales_funnels.guided.limit_reply'))->not->toContain('necesitas una suscripción')
            ->and(gfTurns())->toBe(1)
            ->and(gfLastTurnPrompt())->toContain('no resuelvas peticiones del contenido del servicio')
            ->and(Payment::count())->toBe(0);
    });

    it('does not repeat the offer: the Core answers with its reminder', function () {
        gfSay('Cuéntame más');
        gfSay('Y algo más');

        expect(gfLast())->toContain('Escribe «planes» para ver los precios o «pagar» para suscribirte.')->not->toContain('$9.900');
    });

    it('buys in the customer\'s own words', function () {
        $this->turns = [gfJson('', GuidedFunnel::INTENT_BUY, 0.95, 'comercial')];

        gfSay('Va, me quedo con esto');

        expect(gfLast())->toContain('¿Qué plan de «Asesor» quieres?')->and(Payment::count())->toBe(0);

        gfSay('1');

        expect(Payment::first()->plan->name)->toBe('Semanal')
            ->and(gfLast())->toContain('INSTRUCCIONES-PAGO')
            ->and(gfTurns())->toBe(1);
    });

    it('shows the plans when asked in the customer\'s own words, even right after showing them', function () {
        gfSay('Cuéntame más');

        $this->turns = [gfJson('', GuidedFunnel::INTENT_OFFER, 0.9)];

        gfSay('Muéstrame otra vez las opciones que hay');

        expect(gfLast())->toContain('$9.900')->toContain('$19.900');
    });

    it('still takes a plan by its name and the exact words, with no model call', function () {
        $calls = count($this->turnPrompts);

        gfSay('Quiero el mensual');

        expect(Payment::first()->plan->name)->toBe('Mensual')
            ->and($this->turnPrompts)->toHaveCount($calls);
    });

    it('costs one model call per message: no selector and no commercial advisor, whatever the product has', function () {
        gfSource('Comercial', 'CONTENIDO-COMERCIAL', KnowledgeSource::PURPOSE_COMMERCIAL);
        gfSource('Muestra', 'CONTENIDO-MUESTRA', KnowledgeSource::PURPOSE_SAMPLE);
        $calls = count($this->turnPrompts);
        $selectors = $this->selectorCalls;

        gfSay('Háblame de lo que incluye');

        expect($this->turnPrompts)->toHaveCount($calls + 1)
            ->and($this->selectorCalls)->toBe($selectors)
            ->and(gfLastTurnPrompt())->not->toContain('CONTENIDO-COMERCIAL')->not->toContain('CONTENIDO-MUESTRA');
    });

    it('falls back on the Core\'s reply when the model fails', function () {
        $this->modelDown = true;

        gfSay('Cuéntame más');

        expect(gfLast())->toContain(config('botinker.sales_funnels.guided.limit_reply'))->toContain('Escribe «planes»')
            ->and(Payment::count())->toBe(0);
    });

    it('does not take a broken answer as a purchase', function () {
        $this->turns = ['{"intent": "comprar", roto'];

        gfSay('Cuéntame más');

        expect(gfLast())->toContain(config('botinker.sales_funnels.guided.limit_reply'))->and(Payment::count())->toBe(0);
    });

    it('grants nothing by itself: a purchase ends in a pending payment, with no access', function () {
        $this->turns = [gfJson('', GuidedFunnel::INTENT_BUY, 0.95, 'comercial')];

        gfSay('Va, me quedo con esto');
        gfSay('1');

        expect(Payment::first()->status)->toBe(Payment::STATUS_PENDING)
            ->and(Subscription::count())->toBe(0);

        // The conversation goes on with the payment open: the instructions are not repeated.
        $calls = count($this->turnPrompts);
        $this->turns = [gfJson('Claro, cuéntame tu duda sobre el servicio.', question: 'comercial')];
        gfSay('Tengo una duda');

        expect(gfLast())->toBe('Claro, cuéntame tu duda sobre el servicio.')->and($this->turnPrompts)->toHaveCount($calls + 1)
            ->and(Payment::count())->toBe(1)->and(Subscription::count())->toBe(0);
    });
});

describe('the last free reply', function () {
    beforeEach(function () {
        $this->product->update(['sample_closing_message' => 'CIERRE-DEL-PRODUCTO']);
        gfPublish(free: 3);
    });

    it('tells the model only on the last one, and closes that one with the product\'s closing and the catalog\'s plans', function () {
        $this->replies = [gfJson('Primera.'), gfJson('Segunda.'), gfJson('Tercera.')];

        gfSay('¿Cómo funciona?');
        gfSay('¿Y sirve para mí?');

        expect(gfLast())->toBe('Segunda.')
            ->and(gfLastPrompt())->not->toContain('Es su última respuesta gratuita');

        gfSay('¿Cómo sé si le gusto?');

        expect(gfLastPrompt())->toContain('Es su última respuesta gratuita')
            ->and(gfLast())->toStartWith('Tercera.')->toContain('CIERRE-DEL-PRODUCTO')->toContain('1. Semanal: $9.900 COP por 7 días')->toContain('2. Mensual: $19.900 COP por 30 días')
            ->and(gfTurns())->toBe(3)
            ->and(Payment::count())->toBe(0)
            ->and(Conversation::first()->offers_shown)->toHaveKey((string) $this->product->id);
    });

    it('shows the offer once when the model also proposes it', function () {
        gfSay('Uno');
        gfSay('Dos');
        $this->replies = [gfJson('Tercera.', GuidedFunnel::INTENT_OFFER, 0.9)];

        gfSay('Tres');

        expect(substr_count(gfLast(), '$9.900'))->toBe(1);
    });

    it('still starts a purchase instead of closing, spending no free reply', function () {
        gfSay('Uno');
        gfSay('Dos');
        $this->replies = [gfJson('Perfecto.', GuidedFunnel::INTENT_BUY, 0.95)];

        gfSay('Listo, me animo');

        expect(gfLast())->toContain('¿Qué plan de «Asesor» quieres?')->not->toContain('Perfecto.')
            ->and(gfTurns())->toBe(2);
    });

    it('never lets out an amount the model made up in its closing', function () {
        gfSay('Uno');
        gfSay('Dos');
        $this->replies = [gfJson('Hoy te sale en solo $5.000 al mes.')];

        gfSay('Tres');

        expect(gfLast())->not->toContain('5.000')->toContain('$9.900')
            ->and(gfTurns())->toBe(2);
    });
});

describe('the reply after the limit', function () {
    beforeEach(function () {
        gfPublish(free: 1);
        gfSay('¿Cómo funciona?');
    });

    it('is kind, and never carries an invalid model reply nor the access message', function () {
        $this->turns = [gfJson('CONTENIDO-GENERADO-POR-IA')];

        gfSay('¿Cómo identifico sus señales?');

        expect(gfLast())
            ->toStartWith(config('botinker.sales_funnels.guided.limit_reply'))
            ->toContain('Escribe «planes» para ver los precios o «pagar» para suscribirte.')
            ->not->toContain('CONTENIDO-GENERADO-POR-IA')
            ->not->toContain('necesitas una suscripción');
    });

    it('shows the plans again once the offer is no longer recent, only for a commercial question', function () {
        $this->travel(25)->hours();
        $this->turns = [gfJson('ARGUMENTO-DEL-PROCESO-COMERCIAL', question: 'comercial')];

        gfSay('¿Cómo se paga?');

        // Without verbatim resources, a commercial question gets the funnel's own wording.
        expect(gfLast())->toStartWith('ARGUMENTO-DEL-PROCESO-COMERCIAL')->toContain('$9.900')->toContain('$19.900');
    });

    it('gives the fixed reply to a commercial question whose wording does not pass', function () {
        $this->travel(25)->hours();
        $this->turns = [gfJson('Hoy te sale en $5.000.', question: 'comercial')];

        gfSay('¿Cómo se paga?');

        expect(gfLast())->toStartWith(config('botinker.sales_funnels.guided.limit_reply'))->not->toContain('5.000')->toContain('$19.900');
    });

    it('never adds the plan list to any other question, however old the offer: only how to ask for it', function () {
        $this->travel(25)->hours();

        gfSay('¿Cómo identifico sus señales?');

        expect(gfLast())->toStartWith(config('botinker.sales_funnels.guided.limit_reply'))
            ->toEndWith('Escribe «planes» para ver los precios o «pagar» para suscribirte.')
            ->not->toContain('$9.900');
    });
});

describe('the commercial replies after the limit', function () {
    beforeEach(function () {
        gfPublish(free: 3);
        $this->comercial = 'Entiendo que eso te preocupa. Trabajarlo con el servicio completo te da orientación sobre tu caso. Si quieres, conoce los planes.';

        gfSay('Uno');
        gfSay('Dos');
        gfSay('Tres');
    });

    it('keeps the three free replies, and only then starts the commercial replies', function () {
        expect(gfTurns())->toBe(3)
            ->and(gfLast())->toContain('$9.900')
            ->and(gfReads())->toBe(0)
            ->and($this->turnPrompts)->toHaveCount(3);
    });

    it('sends up to five commercial replies from the model, spending no free reply, and then the fixed message', function () {
        // The reads, with the strict replies of before: the contextual close goes once per interval (its own tests).
        config(['botinker.sales_funnels.guided.closing_reply.enabled' => false]);

        foreach (range(1, 5) as $read) {
            $this->turns = [gfJson($this->comercial)];

            gfSay("¿Cómo identifico sus señales? {$read}");

            expect(gfLast())->toBe($this->comercial)->and(gfReads())->toBe($read);
        }

        $calls = count($this->turnPrompts);

        gfSay('¿Y cómo le pido su número?');

        expect(gfLast())->toStartWith(config('botinker.sales_funnels.guided.limit_reply'))->toContain('Escribe «planes»')
            ->and($this->turnPrompts)->toHaveCount($calls)
            ->and(gfReads())->toBe(5)
            ->and(gfTurns())->toBe(3);
    });

    it('classifies and words in one single call, with no material for the model', function () {
        gfSource('Exclusivo', 'CONTENIDO-EXCLUSIVO', KnowledgeSource::PURPOSE_KNOWLEDGE);
        gfSource('Muestra', 'CONTENIDO-MUESTRA', KnowledgeSource::PURPOSE_SAMPLE);
        gfSource('Comercial', 'CONTENIDO-COMERCIAL', KnowledgeSource::PURPOSE_COMMERCIAL);
        $calls = count($this->turnPrompts);
        $selectors = $this->selectorCalls;
        $this->turns = [gfJson($this->comercial)];

        gfSay('Enséñame todo sobre las señales');

        expect($this->turnPrompts)->toHaveCount($calls + 1)
            ->and($this->selectorCalls)->toBe($selectors)
            ->and(gfLastTurnPrompt())
            ->not->toContain('CONTENIDO-EXCLUSIVO')->not->toContain('CONTENIDO-MUESTRA')->not->toContain('CONTENIDO-COMERCIAL')->not->toContain('MATERIAL AUTORIZADO')
            ->toContain('no des técnicas, pasos, ejemplos, ejercicios')
            ->toContain('INSTRUCCION-DEL-ADMINISTRADOR');
    });

    it('replaces educational content with the fixed message', function () {
        $this->turns = [gfJson('Fíjate en su mirada. Intenta mantener el contacto visual. Después conoce los planes.')];

        gfSay('¿Cómo identifico sus señales?');

        expect(gfLast())->not->toContain('mirada')->toStartWith(config('botinker.sales_funnels.guided.limit_reply'))
            ->and(gfReads())->toBe(1);
    });

    it('adds the plans to a commercial question only when they were not just shown', function () {
        $this->turns = [gfJson($this->comercial, question: 'comercial')];
        $this->travel(25)->hours();

        gfSay('¿Cómo funciona la suscripción?');

        expect(gfLast())->toStartWith($this->comercial)->toContain('$9.900')->toContain('$19.900');

        $this->turns = [gfJson($this->comercial, question: 'comercial')];

        gfSay('¿Y se renueva sola?');

        expect(gfLast())->toBe($this->comercial);
    });

    it('adds to any other question only how to ask for the plans, when they were not just shown', function () {
        $this->turns = [gfJson($this->comercial)];
        $this->travel(25)->hours();

        gfSay('¿Cómo identifico sus señales?');

        expect(gfLast())->toBe($this->comercial."\n\nEscribe «planes» para ver los precios o «pagar» para suscribirte.");
    });

    it('still reads a purchase in the customer\'s words while reads are left, spending one', function () {
        $this->turns = [gfJson('', GuidedFunnel::INTENT_BUY, 0.95, 'comercial')];

        gfSay('Va, me quedo con esto');

        expect(gfLast())->toContain('¿Qué plan de «Asesor» quieres?')->and(gfReads())->toBe(1);
    });

    it('serves what the Core recognizes without a call and without spending a read', function (string $message, string $expected) {
        $calls = count($this->turnPrompts);

        gfSay($message);

        expect(gfLast())->toContain($expected)
            ->and($this->turnPrompts)->toHaveCount($calls)
            ->and(gfReads())->toBe(0);
    })->with([
        'precios' => ['¿Cuánto cuesta?', '$19.900'],
        'planes' => ['Planes', '$19.900'],
        'opciones' => ['Qué opciones hay', '$19.900'],
        'pagar' => ['Pagar', '¿Qué plan de «Asesor» quieres?'],
        'su frase de compra' => ['QUIERO MI ACCESO', '¿Qué plan de «Asesor» quieres?'],
        'con saludo y cortesía' => ['Hola, me animo, gracias', '¿Qué plan de «Asesor» quieres?'],
    ]);

    describe('with no reads left', function () {
        beforeEach(function () {
            foreach (range(1, 5) as $read) {
                gfSay("Pregunta {$read}");
            }

            $this->callsBefore = count($this->turnPrompts);
        });

        it('keeps the plans, the prices and every purchase available, without the model', function (string $message, string $expected) {
            gfSay($message);

            expect(gfLast())->toContain($expected)
                ->and($this->turnPrompts)->toHaveCount($this->callsBefore)
                ->and(gfReads())->toBe(5);
        })->with([
            'precios' => ['¿Cuánto cuesta?', '$19.900'],
            'planes' => ['Planes', '$19.900'],
            'opciones' => ['Muéstrame las opciones', '$19.900'],
            'pagar' => ['Quiero pagar', '¿Qué plan de «Asesor» quieres?'],
            'su frase de compra' => ['Quiero mi acceso', '¿Qué plan de «Asesor» quieres?'],
        ]);

        it('completes a purchase from its phrase to the payment', function () {
            gfSay('Quiero mi acceso');
            gfSay('2');

            expect(Payment::first()->plan->name)->toBe('Mensual')
                ->and(gfLast())->toContain('INSTRUCCIONES-PAGO')
                ->and($this->turnPrompts)->toHaveCount($this->callsBefore);
        });

        it('starts the payment for a plan by its name', function () {
            gfSay('Quiero el semanal');

            expect(Payment::first()->plan->name)->toBe('Semanal')->and($this->turnPrompts)->toHaveCount($this->callsBefore);
        });

        it('answers an ambiguous expression with a simple way to go on', function () {
            gfSay('Pues creo que sí me interesa');

            expect(gfLast())->toContain('Escribe «planes» para ver los precios o «pagar» para suscribirte.')
                ->and($this->turnPrompts)->toHaveCount($this->callsBefore);
        });
    });

    it('does not count twice a message retried after a failed delivery, nor call the model again', function () {
        $this->turns = [gfJson($this->comercial)];
        $calls = count($this->turnPrompts);
        $this->whatsAppDown = true;

        expect(fn () => ProcessWhatsAppMessage::dispatchSync($this->store->fresh(), GF_PHONE, '¿Cómo identifico sus señales?', 'wamid.reintento', 'text', null))->toThrow(RuntimeException::class);

        $this->whatsAppDown = false;

        ProcessWhatsAppMessage::dispatchSync($this->store->fresh(), GF_PHONE, '¿Cómo identifico sus señales?', 'wamid.reintento', 'text', null);

        expect(gfReads())->toBe(1)
            ->and($this->turnPrompts)->toHaveCount($calls + 1)
            ->and(gfLast())->toBe($this->comercial);
    });

    it('keeps the read of a retried message even when the reads ran out meanwhile', function () {
        foreach (range(1, 5) as $read) {
            $states = app(FunnelStates::class)->takeLimitRead(Conversation::first(), $this->product, $this->product->activeFunnel(), GuidedFunnel::STAGE, "wamid.{$read}", 5);

            expect($states)->toBeTrue();
        }

        $take = fn (string $wamid) => app(FunnelStates::class)->takeLimitRead(Conversation::first(), $this->product, $this->product->activeFunnel(), GuidedFunnel::STAGE, $wamid, 5);

        expect($take('wamid.3'))->toBeTrue()
            ->and($take('wamid.nuevo'))->toBeFalse()
            ->and(gfReads())->toBe(5);
    });
});

describe('the question after the limit', function () {
    beforeEach(function () {
        // The last free reply closes with the offer: from here it is recent.
        gfPublish(free: 1);
        gfSay('¿Cómo funciona?');
        $this->pointer = 'Escribe «planes» para ver los precios o «pagar» para suscribirte.';
    });

    it('reads the question the model was asked for: commercial, content, or nothing else', function (string $raw, ?string $question) {
        expect(StageReply::parse($raw, true, ['comprar' => 'c'])->question)->toBe($question);
    })->with([
        'comercial' => [gfJson('R', question: 'comercial'), 'comercial'],
        'contenido' => [gfJson('R', question: 'contenido'), 'contenido'],
        'ausente' => [gfJson('R'), null],
        'nulo' => ['{"reply": "R", "question": null}', null],
        'con mayúsculas y espacios' => ['{"reply": "R", "question": " Contenido "}', 'contenido'],
        'en mayúsculas' => ['{"reply": "R", "question": "COMERCIAL"}', 'comercial'],
        'inválido' => ['{"reply": "R", "question": "compra"}', null],
        'otro tipo' => ['{"reply": "R", "question": ["comercial"]}', null],
        'texto plano' => ['comercial', null],
    ]);

    it('asks the model for the question, in the field the Core reads', function () {
        gfSay('¿Cómo identifico sus señales?');

        expect(gfLastTurnPrompt())->toContain('"question": "comercial"')->toContain('"contenido"');
    });

    it('puts the question in the JSON the commercial turn answers with, and only there', function () {
        $this->replies = [gfJson('CONTENIDO-DE-LA-RESPUESTA-GRATUITA')];

        gfSay('¿Cómo le hablo a una chica en un bar?');

        expect(gfLastTurnPrompt())->toContain('{"reply": "texto para el cliente", "intent": "clave" o null, "confidence": número de 0 a 1, "question": "comercial" o "contenido" o "cortesia" o "rechazo" o null, "fallback_reply": "respuesta alternativa breve"}')
            ->toContain('En "question" clasifica siempre su último mensaje')
            ->and(gfLastPrompt())->toContain('{"reply": "texto para el cliente", "intent": "clave" o null, "confidence": número de 0 a 1}')->not->toContain('"question"');
    });

    it('shows no plans for [Planes] in a request for content once the free reply was given', function () {
        gfSay('¿Cómo le hablo a una chica en un bar?');
        $this->travel(25)->hours();
        $this->turns = [gfJson('Entiendo. Eso se trabaja con el servicio completo. [Planes]', question: 'contenido')];

        gfSay('¿Y en una discoteca?');

        expect(gfLast())->not->toContain('$9.900')->not->toMatch(StageReply::PLANS_MARKER)->and(gfTurns())->toBe(1);
    });

    it('shows the offer the model proposes for a commercial question, and only how to ask for it when it was just shown', function () {
        $this->travel(25)->hours();
        $this->turns = [gfJson('', GuidedFunnel::INTENT_OFFER, 0.9, 'comercial'), gfJson('', GuidedFunnel::INTENT_OFFER, 0.9, 'comercial')];

        gfSay('¿Qué opciones de pago tienen?');

        expect(gfLast())->toContain('$9.900')->toContain('$19.900');

        gfSay('¿Y cuáles eran?');

        expect(gfLast())->toBe('Escribe «planes» para ver los precios o «pagar» para suscribirte.');
    });

    it('never shows the offer unless the question was read as commercial, however old the offer', function (string $raw) {
        Log::spy();
        $this->travel(25)->hours();
        $this->turns = [$raw];

        gfSay('¿Cómo identifico sus señales?');

        expect(gfLast())->not->toContain('$9.900')->toEndWith($this->pointer)
            ->and(ConversationFunnelState::first()?->stage)->toBe(GuidedFunnel::STAGE);

        Log::shouldHaveReceived('info')->with('FUNNEL_LIMIT_READ', Mockery::on(fn (array $context) => $context['action_rejected'] === 'offer_unclassified'));
    })->with([
        'contenido' => [gfJson('', GuidedFunnel::INTENT_OFFER, 0.9, 'contenido')],
        'ausente' => [gfJson('', GuidedFunnel::INTENT_OFFER, 0.9)],
        'nulo' => ['{"reply": "", "intent": "presentar_oferta", "confidence": 0.9, "question": null}'],
        'inválido' => ['{"reply": "", "intent": "presentar_oferta", "confidence": 0.9, "question": "planes"}'],
    ]);

    it('never takes an unclassified «bueno», «ok» or «gracias» right after the offer as a purchase', function (string $message, string $question) {
        Log::spy();
        $this->turns = [gfJson('', GuidedFunnel::INTENT_BUY, 0.9, $question ?: null)];

        gfSay($message);

        expect(gfLast())->not->toContain('¿Qué plan de «Asesor» quieres?')->and(Payment::count())->toBe(0)->and($this->turnPrompts)->not->toBeEmpty();
        Log::shouldHaveReceived('info')->with('FUNNEL_LIMIT_READ', Mockery::on(fn (array $context) => $context['action_rejected'] === 'purchase_without_context'));
    })->with([
        'bueno, sin clasificar' => ['Bueno', ''],
        'ok, sin clasificar' => ['Ok', ''],
        'gracias, como cortesía' => ['Gracias', 'cortesia'],
    ]);

    it('takes "ok, lo tomo" right after the offer as a purchase when it is read as a decision to buy', function () {
        $this->turns = [gfJson('', GuidedFunnel::INTENT_BUY, 0.9, 'comercial')];

        gfSay('Ok, lo tomo');

        expect(gfLast())->toContain('¿Qué plan de «Asesor» quieres?')->and(Payment::count())->toBe(0);
    });

    it('takes a purchase read as commercial, even with no recent offer', function () {
        $this->travel(25)->hours();
        $this->turns = [gfJson('', GuidedFunnel::INTENT_BUY, 0.9, 'comercial')];

        gfSay('Quiero comprarlo ya mismo');

        expect(gfLast())->toContain('¿Qué plan de «Asesor» quieres?');
    });

    it('never starts a purchase for a content question or an ambiguous answer just because the offer is recent', function (string $raw, string $message) {
        $this->turns = [$raw];

        gfSay($message);

        expect(gfLast())->not->toContain('¿Qué plan de «Asesor» quieres?')->not->toContain('$9.900')
            ->and(Payment::count())->toBe(0)
            ->and(Conversation::first()->pending_state)->toBeNull();
    })->with([
        'contenido' => [gfJson('', GuidedFunnel::INTENT_BUY, 0.95, 'contenido'), '¿Cómo le hablo para que me diga que sí?'],
        'Contenido, con mayúscula' => [gfJson('', GuidedFunnel::INTENT_BUY, 0.95, 'Contenido'), '¿Cómo le hablo para que me diga que sí?'],
        'sin seguridad' => [gfJson('', GuidedFunnel::INTENT_BUY, 0.5), 'Puede ser'],
        'sin intención' => [gfJson('Entiendo. Eso se trabaja con el servicio completo. Si quieres, conoce los planes.'), 'Mmm, vale'],
        'roto' => ['{"intent": "comprar", "confidence": 0.95, roto', 'Ok'],
    ]);

    it('never starts a purchase with no question read once the offer is no longer recent', function () {
        $this->travel(25)->hours();
        $this->turns = [gfJson('', GuidedFunnel::INTENT_BUY, 0.95)];

        gfSay('Ok, lo tomo');

        expect(gfLast())->not->toContain('¿Qué plan de «Asesor» quieres?')->and(Payment::count())->toBe(0);
    });

    it('keeps every action the Core recognizes, without the model', function (string $message, string $expected) {
        $calls = count($this->turnPrompts);

        gfSay($message);

        expect(gfLast())->toContain($expected)->and($this->turnPrompts)->toHaveCount($calls)->and(gfReads())->toBe(0);
    })->with([
        'pagar' => ['Pagar', '¿Qué plan de «Asesor» quieres?'],
        'planes' => ['Planes', '$19.900'],
        'un plan por su nombre' => ['Quiero el mensual', 'INSTRUCCIONES-PAGO'],
        'su frase de compra' => ['Quiero mi acceso', '¿Qué plan de «Asesor» quieres?'],
        'su frase de opciones' => ['Ver opciones', '$19.900'],
    ]);
});

describe('the literal commercial answers after the limit', function () {
    beforeEach(function () {
        gfPublish(free: 1);
        gfSay('¿Cómo funciona?');
        $this->pointer = 'Escribe «planes» para ver los precios o «pagar» para suscribirte.';
        $this->comercial = 'Entiendo. Eso se trabaja con el servicio completo. Si quieres, conoce los planes.';
    });

    it('answers a commercial question with the verbatim resource exactly as written, within the same read', function () {
        gfSource('Qué incluye', 'TEXTO-LITERAL-APROBADO', KnowledgeSource::PURPOSE_COMMERCIAL, KnowledgeSource::DELIVERY_VERBATIM);
        $this->sections = ['Qué incluye'];
        $this->turns = [gfJson($this->comercial, question: 'comercial')];
        $calls = count($this->turnPrompts);
        $selectors = $this->selectorCalls;

        gfSay('¿Qué incluye el entrenamiento?');

        expect(gfLast())->toBe('TEXTO-LITERAL-APROBADO')
            ->and($this->turnPrompts)->toHaveCount($calls + 1)
            ->and($this->selectorCalls)->toBe($selectors + 1)
            ->and(gfReads())->toBe(1)
            ->and(gfTurns())->toBe(1)
            ->and(Payment::count())->toBe(0);
    });

    it('adds the plans after the literal answer when they were not just shown', function () {
        gfSource('Qué incluye', 'TEXTO-LITERAL-APROBADO', KnowledgeSource::PURPOSE_COMMERCIAL, KnowledgeSource::DELIVERY_VERBATIM);
        $this->sections = ['Qué incluye'];
        $this->travel(25)->hours();
        $this->turns = [gfJson($this->comercial, question: 'comercial')];

        gfSay('¿Qué incluye el entrenamiento?');

        expect(gfLast())->toStartWith('TEXTO-LITERAL-APROBADO')->toContain('$9.900')->toContain('$19.900');
    });

    it('offers and sends only verbatim commercial resources: never one to be worded, the sample or the knowledge', function () {
        gfSource('Qué incluye', 'TEXTO-LITERAL-APROBADO', KnowledgeSource::PURPOSE_COMMERCIAL, KnowledgeSource::DELIVERY_VERBATIM);
        gfSource('Qué incluye', 'TEXTO-PARA-REDACTAR', KnowledgeSource::PURPOSE_COMMERCIAL);
        gfSource('Solo para redactar', 'OTRO-TEXTO-PARA-REDACTAR', KnowledgeSource::PURPOSE_COMMERCIAL);
        gfSource('Qué incluye', 'TEXTO-DE-MUESTRA', KnowledgeSource::PURPOSE_SAMPLE, KnowledgeSource::DELIVERY_VERBATIM);
        gfSource('Qué incluye', 'TEXTO-EXCLUSIVO', KnowledgeSource::PURPOSE_KNOWLEDGE);
        $this->sections = ['Qué incluye', 'Solo para redactar'];
        $this->turns = [gfJson($this->comercial, question: 'comercial')];
        $this->chats = [];

        gfSay('¿Qué incluye el entrenamiento?');

        $selector = collect($this->chats)->first(fn (array $messages) => str_contains($messages[0]['content'], 'Eres un selector de conocimiento'));

        expect(gfLast())->toBe('TEXTO-LITERAL-APROBADO')
            ->and(collect($selector)->pluck('content')->implode(' '))->not->toContain('Solo para redactar');
    });

    it('leaves out resources with an amount before keeping two', function () {
        app(KnowledgeIngestor::class)->ingest($this->store, 'tema', $this->product, 'Fuente', 'markdown',
            "# Precio\n\nCuesta $50.000 al mes.\n\n# Uno\n\nTEXTO-UNO\n\n# Dos\n\nTEXTO-DOS\n\n# Tres\n\nTEXTO-TRES",
            origin: 'origen-literal', purpose: KnowledgeSource::PURPOSE_COMMERCIAL, delivery: KnowledgeSource::DELIVERY_VERBATIM);
        $this->sections = ['Precio', 'Uno', 'Dos', 'Tres'];
        $this->turns = [gfJson($this->comercial, question: 'comercial')];

        gfSay('¿Qué incluye y cuánto cuesta?');

        expect(gfLast())->toBe("TEXTO-UNO\n\nTEXTO-DOS");
    });

    it('says it has no confirmed information when nothing configured answers the question', function () {
        gfSource('Qué incluye', 'TEXTO-LITERAL-APROBADO', KnowledgeSource::PURPOSE_COMMERCIAL, KnowledgeSource::DELIVERY_VERBATIM);
        $this->sections = [];
        $this->turns = [gfJson('Sí, tiene garantía de 30 días.', question: 'comercial')];

        gfSay('¿El entrenamiento tiene garantía?');

        expect(gfLast())->toBe(config('botinker.sales_funnels.guided.undocumented_reply')."\n\n".$this->pointer)
            ->not->toContain('30 días')->not->toContain('TEXTO-LITERAL-APROBADO');
    });

    it('lets the funnel answer an objection that needs no document, even with verbatim resources', function () {
        gfSource('Qué incluye', 'TEXTO-LITERAL-APROBADO', KnowledgeSource::PURPOSE_COMMERCIAL, KnowledgeSource::DELIVERY_VERBATIM);
        $this->needsKnowledge = false;
        $this->turns = [gfJson('Te entiendo. Con el plan semanal puedes empezar con poco y ver cómo te acompaño.', question: 'comercial')];

        gfSay('Es caro');

        expect(gfLast())->toStartWith('Te entiendo. Con el plan semanal puedes empezar con poco')
            ->not->toContain('TEXTO-LITERAL-APROBADO')->not->toContain(config('botinker.sales_funnels.guided.undocumented_reply'))
            ->and($this->selectorCalls)->toBe(1);
    });

    it('still makes up nothing for an objection: a guarantee in the model\'s words does not go out', function () {
        gfSource('Qué incluye', 'TEXTO-LITERAL-APROBADO', KnowledgeSource::PURPOSE_COMMERCIAL, KnowledgeSource::DELIVERY_VERBATIM);
        $this->needsKnowledge = false;
        $this->turns = [gfJson('Tranquilo, si no te funciona te devolvemos el dinero.', question: 'comercial')];

        gfSay('¿Y si no me sirve?');

        expect(gfLast())->not->toContain('devolvemos')->toStartWith(config('botinker.sales_funnels.guided.limit_reply'));
    });

    it('gives no content read as commercial by mistake, even when no document is needed', function () {
        gfSource('Qué incluye', 'TEXTO-LITERAL-APROBADO', KnowledgeSource::PURPOSE_COMMERCIAL, KnowledgeSource::DELIVERY_VERBATIM);
        $this->needsKnowledge = false;
        $this->turns = [gfJson('Fíjate en su mirada y luego háblale de algo del lugar.', question: 'comercial')];

        gfSay('¿Cómo sé si le gusto?');

        expect(gfLast())->not->toContain('mirada')->toStartWith(config('botinker.sales_funnels.guided.limit_reply'))->and(gfTurns())->toBe(1);
    });

    it('also counts a resource whose only match states an amount as nothing configured', function () {
        gfSource('Precio', 'Cuesta $50.000 al mes.', KnowledgeSource::PURPOSE_COMMERCIAL, KnowledgeSource::DELIVERY_VERBATIM);
        $this->sections = ['Precio'];
        $this->turns = [gfJson($this->comercial, question: 'comercial')];

        gfSay('¿Cuánto vale al mes el entrenamiento, más o menos?');

        expect(gfLast())->not->toContain('50.000');
    });

    it('never claims there is no information when the selection failed: the fixed reply goes', function () {
        gfSource('Qué incluye', 'TEXTO-LITERAL-APROBADO', KnowledgeSource::PURPOSE_COMMERCIAL, KnowledgeSource::DELIVERY_VERBATIM);
        $this->selectorDown = true;
        $this->turns = [gfJson('Sí, tiene garantía de 30 días.', question: 'comercial')];

        gfSay('¿El entrenamiento tiene garantía?');

        expect(gfLast())->toStartWith(config('botinker.sales_funnels.guided.limit_reply'))
            ->not->toContain(config('botinker.sales_funnels.guided.undocumented_reply'))->not->toContain('30 días');
    });

    it('keeps the current behaviour, with no selector call, when there is no verbatim commercial resource', function () {
        gfSource('Qué incluye', 'TEXTO-PARA-REDACTAR', KnowledgeSource::PURPOSE_COMMERCIAL);
        $this->sections = ['Qué incluye'];
        $this->turns = [gfJson($this->comercial, question: 'comercial')];
        $selectors = $this->selectorCalls;

        gfSay('¿Qué incluye el entrenamiento?');

        expect(gfLast())->toBe($this->comercial)->and($this->selectorCalls)->toBe($selectors);
    });

    it('keeps the current behaviour, with no selector call, without commercial instructions', function () {
        $this->product->update(['commercial_instructions' => null]);
        gfSource('Qué incluye', 'TEXTO-LITERAL-APROBADO', KnowledgeSource::PURPOSE_COMMERCIAL, KnowledgeSource::DELIVERY_VERBATIM);
        $this->sections = ['Qué incluye'];
        $this->turns = [gfJson($this->comercial, question: 'comercial')];
        $selectors = $this->selectorCalls;

        gfSay('¿Qué incluye el entrenamiento?');

        expect(gfLast())->toBe($this->comercial)->and($this->selectorCalls)->toBe($selectors);
    });

    it('never consults the resources for a question that is not commercial', function (?string $question) {
        gfSource('Qué incluye', 'TEXTO-LITERAL-APROBADO', KnowledgeSource::PURPOSE_COMMERCIAL, KnowledgeSource::DELIVERY_VERBATIM);
        $this->sections = ['Qué incluye'];
        $this->turns = [gfJson($this->comercial, question: $question)];
        $selectors = $this->selectorCalls;

        gfSay('¿Cómo identifico sus señales?');

        expect(gfLast())->not->toContain('TEXTO-LITERAL-APROBADO')->and($this->selectorCalls)->toBe($selectors);
    })->with(['contenido' => 'contenido', 'sin leer' => null]);

    it('does not spend a second read nor send twice when a literal answer is retried after a failed delivery', function () {
        gfSource('Qué incluye', 'TEXTO-LITERAL-APROBADO', KnowledgeSource::PURPOSE_COMMERCIAL, KnowledgeSource::DELIVERY_VERBATIM);
        $this->sections = ['Qué incluye'];
        $this->turns = [gfJson($this->comercial, question: 'comercial')];
        $calls = count($this->turnPrompts);
        $this->whatsAppDown = true;

        expect(fn () => ProcessWhatsAppMessage::dispatchSync($this->store->fresh(), GF_PHONE, '¿Qué incluye?', 'wamid.reintento', 'text', null))->toThrow(RuntimeException::class);

        $this->whatsAppDown = false;

        ProcessWhatsAppMessage::dispatchSync($this->store->fresh(), GF_PHONE, '¿Qué incluye?', 'wamid.reintento', 'text', null);

        $delivered = Http::recorded(fn (Request $request, $response) => str_ends_with($request->url(), '/messages')
            && str_starts_with($request->data()['text']['body'] ?? '', 'TEXTO-LITERAL-APROBADO')
            && $response->successful());

        expect(gfReads())->toBe(1)
            ->and($this->turnPrompts)->toHaveCount($calls + 1)
            ->and($delivered)->toHaveCount(1)
            ->and(gfLast())->toBe('TEXTO-LITERAL-APROBADO')
            ->and(gfTurns())->toBe(1);
    });

    it('keeps the free replies as each product configures them', function (int $free) {
        gfPublish(free: $free);
        gfSource('Qué incluye', 'TEXTO-LITERAL-APROBADO', KnowledgeSource::PURPOSE_COMMERCIAL, KnowledgeSource::DELIVERY_VERBATIM);
        $this->sections = ['Qué incluye'];

        for ($turn = 2; $turn <= $free; $turn++) {
            gfSay("Pregunta {$turn}");
        }

        $this->turns = [gfJson($this->comercial, question: 'comercial')];

        gfSay('¿Qué incluye el entrenamiento?');

        expect(gfTurns())->toBe($free)->and(gfLast())->toStartWith('TEXTO-LITERAL-APROBADO');
    })->with([1, 3]);
});

describe('the commercial turn', function () {
    beforeEach(function () {
        gfPublish(free: 1);
        $this->pointer = 'Escribe «planes» para ver los precios o «pagar» para suscribirte.';
    });

    it('follows the question the model read, and the safe side without one', function (string $turn, string $message, Closure $expect) {
        $this->turns = [$turn];
        $this->replies = [gfJson('CONTENIDO-DE-LA-RESPUESTA-GRATUITA')];

        gfSay($message);

        $expect($this);
    })->with([
        'comercial' => [gfJson('Te acompaño por WhatsApp durante tu suscripción.', question: 'comercial'), '¿Esto cómo funciona?',
            fn ($test) => expect(gfLast())->toStartWith('Te acompaño por WhatsApp durante tu suscripción.')->and(gfTurns())->toBe(0)],
        'contenido' => [gfJson('', question: 'contenido'), '¿Cómo le hablo a una chica en un bar?',
            fn ($test) => expect(gfLast())->toStartWith('CONTENIDO-DE-LA-RESPUESTA-GRATUITA')->and(gfTurns())->toBe(1)],
        'ausente' => [gfJson('Fíjate en su mirada y luego háblale.', GuidedFunnel::INTENT_OFFER, 0.9), 'Mmm, no sé',
            fn ($test) => expect(gfLast())->toBe(config('botinker.sales_funnels.guided.unclassified_reply'))->and(gfTurns())->toBe(0)],
        'inválida' => ['{"reply": "Fíjate en su mirada y luego háblale.", "intent": "presentar_oferta", "confidence": 0.9, "question": "venta"}', 'Mmm, no sé',
            fn ($test) => expect(gfLast())->toBe(config('botinker.sales_funnels.guided.unclassified_reply'))->and(gfTurns())->toBe(0)],
        '[Planes] y comercial' => [gfJson('Con el plan semanal empiezas hoy. [Planes]', question: 'comercial'), '¿Esto cómo funciona?',
            fn ($test) => expect(gfLast())->toStartWith('Con el plan semanal empiezas hoy.')->toContain('$9.900')->not->toMatch(StageReply::PLANS_MARKER)->and(gfTurns())->toBe(0)],
    ]);

    it('tells the model the official prices come from the catalog, never to say it cannot give them, and gives it no amount', function () {
        gfSay('¿Esto cómo funciona?');

        expect(gfLastTurnPrompt())->toContain('los oficiales los muestra el sistema desde el catálogo')->toContain('nunca digas que no puedes darlos')
            ->toContain('INSTRUCCION-DEL-ADMINISTRADOR')->toContain('- Semanal: 7 días')
            ->not->toContain('9.900')->not->toContain('19.900');
    });

    it('answers a commercial question in the funnel\'s words without spending the free reply', function () {
        $this->turns = [gfJson('Te acompaño por WhatsApp durante tu suscripción.', question: 'comercial')];

        gfSay('¿Esto cómo funciona?');

        expect(gfLast())->toStartWith('Te acompaño por WhatsApp durante tu suscripción.')
            ->and(gfTurns())->toBe(0)
            ->and($this->prompts)->toBe([])
            ->and(gfReads())->toBe(1);
    });

    it('argues an objection in the funnel\'s words without spending the free reply', function () {
        $this->turns = [gfJson('Te entiendo: con el plan semanal puedes empezar con poco.', question: 'comercial')];

        gfSay('Es caro');

        expect(gfLast())->toStartWith('Te entiendo: con el plan semanal puedes empezar con poco.')->and(gfTurns())->toBe(0);
    });

    it('spends the free reply only on a request for content, giving the turn\'s read back', function () {
        $this->replies = [gfJson('CONTENIDO-DE-LA-RESPUESTA-GRATUITA')];

        gfSay('¿Cómo le hablo a una chica en un bar?');

        expect(gfLast())->toStartWith('CONTENIDO-DE-LA-RESPUESTA-GRATUITA')->toContain('$9.900')
            ->and(gfTurns())->toBe(1)
            ->and(gfReads())->toBe(0)
            ->and($this->turnPrompts)->toHaveCount(1)
            ->and($this->prompts)->toHaveCount(1)
            ->and(gfLastPrompt())->toContain('Es su última respuesta gratuita');
    });

    it('answers content without a free reply left with the limit and how to ask for the plans', function () {
        gfSay('¿Cómo le hablo a una chica en un bar?');
        $this->turns = [gfJson('Fíjate en su mirada y luego háblale.', question: 'contenido')];

        gfSay('¿Y cómo le pido el número?');

        expect(gfLast())->toStartWith(config('botinker.sales_funnels.guided.limit_reply'))->toEndWith($this->pointer)->not->toContain('mirada')
            ->and(gfTurns())->toBe(1);
    });

    it('never spends the free reply on plans, prices, a plan chosen or a purchase', function (string $message, ?string $turn) {
        $this->turns = $turn !== null ? [$turn] : [];

        gfSay($message);

        expect(gfTurns())->toBe(0)->and($this->prompts)->toBe([]);
    })->with([
        'precios' => ['¿Cuánto cuesta?', null],
        'planes' => ['Planes', null],
        'un plan por su nombre' => ['Quiero el semanal', null],
        'una compra leída por el modelo' => ['Va, me quedo con esto', gfJson('', GuidedFunnel::INTENT_BUY, 0.95, 'comercial')],
        'su frase de compra' => ['Quiero mi acceso', null],
    ]);

    it('gives no content in a turn the model did not classify, and says nothing about a limit not reached', function () {
        $this->turns = [gfJson('Fíjate en su mirada y luego háblale.')];

        gfSay('Mmm, no sé');

        expect(gfLast())->toBe(config('botinker.sales_funnels.guided.unclassified_reply'))->and(gfTurns())->toBe(0);
    });

    it('reads a request for content with a grace read once the reads ran out, and gives the free reply', function () {
        gfSpendReads();
        $this->replies = [gfJson('CONTENIDO-DE-LA-RESPUESTA-GRATUITA')];

        gfSay('¿Cómo le hablo a una chica en un bar?');

        expect(gfLast())->toStartWith('CONTENIDO-DE-LA-RESPUESTA-GRATUITA')
            ->and(gfTurns())->toBe(1)
            ->and($this->turnPrompts)->toHaveCount(1)
            ->and(gfReads())->toBe(5)
            ->and(gfGraceReads())->toBe(0);
    });

    it('answers a commercial question with a grace read without spending the free reply, and then reads nothing more', function () {
        gfSpendReads();
        $this->turns = [gfJson('Te acompaño por WhatsApp durante tu suscripción.', question: 'comercial')];

        gfSay('¿Esto cómo funciona?');

        expect(gfLast())->toStartWith('Te acompaño por WhatsApp durante tu suscripción.')
            ->and(gfTurns())->toBe(0)
            ->and(gfGraceReads())->toBe(1);

        gfSay('¿Cómo le hablo a una chica en un bar?');

        expect(gfLast())->toBe($this->pointer)
            ->and($this->turnPrompts)->toHaveCount(1)
            ->and($this->prompts)->toBe([])
            ->and(gfTurns())->toBe(0);
    });

    it('gives nothing free to an ambiguous message read with grace, and spends nothing', function () {
        gfSpendReads();
        $this->turns = [gfJson('Fíjate en su mirada y luego háblale.')];

        gfSay('Mmm, no sé');

        expect(gfLast())->toBe(config('botinker.sales_funnels.guided.unclassified_reply'))->and(gfTurns())->toBe(0)->and($this->prompts)->toBe([]);
    });

    it('reads nothing with grace once the free replies are over', function () {
        $this->replies = [gfJson('CONTENIDO-DE-LA-RESPUESTA-GRATUITA')];
        gfSay('¿Cómo le hablo a una chica en un bar?');
        gfSpendReads();
        $turns = count($this->turnPrompts);

        gfSay('¿Y en una discoteca?');

        expect(gfLast())->toStartWith(config('botinker.sales_funnels.guided.limit_reply'))
            ->and($this->turnPrompts)->toHaveCount($turns)
            ->and(gfTurns())->toBe(1);
    });

    it('keeps a reactivation\'s invitation once the grace was spent: one read for its answer', function () {
        gfSpendReads();
        $this->turns = [gfJson('Te acompaño por WhatsApp.', question: 'comercial')];
        gfSay('¿Esto cómo funciona?');
        Conversation::query()->update(['reactivation_episode_at' => now()->subHour()]);
        $this->replies = [gfJson('CONTENIDO-DE-LA-RESPUESTA-GRATUITA')];

        gfSay('Vale, te pregunto: ¿cómo le hablo a una chica en un bar?');

        expect(gfLast())->toStartWith('CONTENIDO-DE-LA-RESPUESTA-GRATUITA')->and(gfTurns())->toBe(1);

        gfSay('¿Y en una discoteca?');

        expect(gfLast())->toStartWith(config('botinker.sales_funnels.guided.limit_reply'))->and($this->turnPrompts)->toHaveCount(2);
    });

    it('grants one read per reactivation, not one per message', function () {
        gfSpendReads();
        $this->turns = [gfJson('Te acompaño por WhatsApp.', question: 'comercial'), gfJson('Sigo aquí para lo que necesites.', question: 'comercial')];
        gfSay('¿Esto cómo funciona?');
        Conversation::query()->update(['reactivation_episode_at' => now()->subHour()]);

        gfSay('¿Y qué más?');
        gfSay('¿Algo más?');

        expect($this->turnPrompts)->toHaveCount(2)->and(gfLast())->toBe($this->pointer)->and(gfTurns())->toBe(0);
    });

    it('bounds the grace by the free replies left, as each product configures them', function () {
        gfPublish(free: 3);
        gfSpendReads();
        $this->turns = array_fill(0, 4, gfJson('Te acompaño por WhatsApp.', question: 'comercial'));

        foreach (range(1, 4) as $message) {
            gfSay("Pregunta comercial {$message}");
        }

        expect($this->turnPrompts)->toHaveCount(3)->and(gfGraceReads())->toBe(3)->and(gfTurns())->toBe(0);
    });

    it('gives a retried message the free reply it reserved before the worker died, spending it once', function () {
        // The attempt that died had reserved the free reply and never sent it.
        app(SampleLedger::class)->reserve(app(CustomerMemory::class)->customerFor($this->store, GF_PHONE), $this->product, 'wamid.muerto');
        $this->replies = [gfJson('CONTENIDO-DE-LA-RESPUESTA-GRATUITA')];

        ProcessWhatsAppMessage::dispatchSync($this->store->fresh(), GF_PHONE, '¿Cómo le hablo a una chica en un bar?', 'wamid.muerto', 'text', null);

        expect(gfLast())->toStartWith('CONTENIDO-DE-LA-RESPUESTA-GRATUITA')->and(gfTurns())->toBe(1);
    });

    it('gives it back too when the reads had run out and it was read with grace', function () {
        gfSpendReads();
        app(SampleLedger::class)->reserve(app(CustomerMemory::class)->customerFor($this->store, GF_PHONE), $this->product, 'wamid.muerto');
        $this->replies = [gfJson('CONTENIDO-DE-LA-RESPUESTA-GRATUITA')];

        ProcessWhatsAppMessage::dispatchSync($this->store->fresh(), GF_PHONE, '¿Cómo le hablo a una chica en un bar?', 'wamid.muerto', 'text', null);

        expect(gfLast())->toStartWith('CONTENIDO-DE-LA-RESPUESTA-GRATUITA')->and(gfTurns())->toBe(1)->and(gfGraceReads())->toBe(0);
    });

    it('never gives another message the free reply a message reserved', function () {
        app(SampleLedger::class)->reserve(app(CustomerMemory::class)->customerFor($this->store, GF_PHONE), $this->product, 'wamid.otro');
        $this->replies = [gfJson('CONTENIDO-DE-LA-RESPUESTA-GRATUITA')];

        ProcessWhatsAppMessage::dispatchSync($this->store->fresh(), GF_PHONE, '¿Cómo le hablo a una chica en un bar?', 'wamid.nuevo', 'text', null);

        expect(gfLast())->not->toContain('CONTENIDO-DE-LA-RESPUESTA-GRATUITA')->and(gfTurns())->toBe(1)->and($this->prompts)->toBe([]);
    });

    it('ties each reservation to its message: kept for a retry, never shared, gone once released or delivered', function () {
        gfPublish(free: 3);
        $ledger = app(SampleLedger::class);
        $customer = app(CustomerMemory::class)->customerFor($this->store, GF_PHONE);

        $first = $ledger->reserve($customer, $this->product, 'wamid.A');
        $retried = $ledger->reserve($customer, $this->product, 'wamid.A');
        $other = $ledger->reserve($customer, $this->product, 'wamid.B');

        expect([$first->turns_used, $retried->id, $retried->turns_used, $other->turns_used, gfTurns()])->toBe([1, $first->id, 1, 2, 2]);

        // Released (its send failed): the free reply comes back, and the message holds nothing any more.
        $ledger->release($other, 'wamid.B');
        expect(gfTurns())->toBe(1)->and($ledger->reserve($customer, $this->product, 'wamid.B')->turns_used)->toBe(2);

        // Delivered: a later delivery of the same message gets no free reply for nothing.
        $ledger->settle($customer, $this->product, 'wamid.A');
        expect($ledger->reserve($customer, $this->product, 'wamid.A')->turns_used)->toBe(3);
    });

    it('does not take a second grace read, call the model again or send twice when a grace free reply is retried', function () {
        gfSpendReads();
        $this->replies = [gfJson('CONTENIDO-DE-LA-RESPUESTA-GRATUITA')];
        $this->whatsAppDown = true;

        expect(fn () => ProcessWhatsAppMessage::dispatchSync($this->store->fresh(), GF_PHONE, '¿Cómo le hablo?', 'wamid.gracia', 'text', null))->toThrow(RuntimeException::class);

        $this->whatsAppDown = false;

        ProcessWhatsAppMessage::dispatchSync($this->store->fresh(), GF_PHONE, '¿Cómo le hablo?', 'wamid.gracia', 'text', null);

        expect(gfLast())->toStartWith('CONTENIDO-DE-LA-RESPUESTA-GRATUITA')
            ->and(gfTurns())->toBe(1)
            ->and(gfGraceReads())->toBe(0)
            ->and($this->turnPrompts)->toHaveCount(1)
            ->and($this->prompts)->toHaveCount(1);
    });

    it('gives the limit and how to ask for the plans once both the reads and the free replies ran out', function () {
        $this->replies = [gfJson('CONTENIDO-DE-LA-RESPUESTA-GRATUITA')];
        gfSay('¿Cómo le hablo a una chica en un bar?');

        gfSpendReads();

        gfSay('¿Y en una discoteca?');

        expect(gfLast())->toStartWith(config('botinker.sales_funnels.guided.limit_reply'))->toEndWith($this->pointer)->and(gfTurns())->toBe(1);
    });

    it('gives no free reply when the model failed to read the message', function () {
        $this->modelDown = true;

        gfSay('¿Cómo le hablo a una chica en un bar?');

        expect(gfLast())->toBe(config('botinker.sales_funnels.guided.unclassified_reply'))->and(gfTurns())->toBe(0);
    });

    it('closes the free reply with how to ask for the plans when they were just shown, not with them again', function () {
        gfSay('Planes');
        $this->replies = [gfJson('CONTENIDO-DE-LA-RESPUESTA-GRATUITA')];

        gfSay('¿Cómo le hablo a una chica en un bar?');

        expect(gfLast())->toBe("CONTENIDO-DE-LA-RESPUESTA-GRATUITA\n\n".$this->pointer)->and(gfTurns())->toBe(1);
    });

    it('does not spend a second read, call the model again or send twice when a free reply is retried', function () {
        $this->replies = [gfJson('CONTENIDO-DE-LA-RESPUESTA-GRATUITA')];
        $this->whatsAppDown = true;

        expect(fn () => ProcessWhatsAppMessage::dispatchSync($this->store->fresh(), GF_PHONE, '¿Cómo le hablo?', 'wamid.reintento', 'text', null))->toThrow(RuntimeException::class);

        expect(gfTurns())->toBe(0);

        $this->whatsAppDown = false;

        ProcessWhatsAppMessage::dispatchSync($this->store->fresh(), GF_PHONE, '¿Cómo le hablo?', 'wamid.reintento', 'text', null);

        expect(gfLast())->toStartWith('CONTENIDO-DE-LA-RESPUESTA-GRATUITA')
            ->and(gfTurns())->toBe(1)
            ->and(gfReads())->toBe(0)
            ->and($this->turnPrompts)->toHaveCount(1)
            ->and($this->prompts)->toHaveCount(1);
    });
});

describe('the [Planes] marker', function () {
    beforeEach(function () {
        gfPublish(free: 1);
        $this->pointer = 'Escribe «planes» para ver los precios o «pagar» para suscribirte.';
        $this->lastSendType = fn () => collect(Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/messages')))->last()[0]->data()['type'];
    });

    it('puts the official plans, with their buttons, after the argument, and never shows the marker', function (string $raw, string $argument) {
        $this->turns = [$raw];

        gfSay('¿Esto cómo funciona?');

        expect(gfLast())->toStartWith($argument."\n\n")->toContain('$9.900')->toContain('$19.900')->not->toMatch(StageReply::PLANS_MARKER)
            ->and(($this->lastSendType)())->toBe('interactive')
            ->and(WhatsAppMessage::where('role', 'assistant')->latest('id')->value('content'))->toBe(gfLast())
            ->and(Conversation::first()->offers_shown)->toHaveKey((string) $this->product->id)
            ->and(gfTurns())->toBe(0);
    })->with([
        'al final' => [gfJson('Con el plan semanal empiezas hoy. [Planes]', question: 'comercial'), 'Con el plan semanal empiezas hoy.'],
        'en minúsculas y en medio' => [gfJson('Mira esto [ planes ] y decide con calma.', question: 'comercial'), 'Mira esto y decide con calma.'],
    ]);

    it('lets a purchase win over the marker', function () {
        $this->turns = [gfJson('Perfecto. [Planes]', GuidedFunnel::INTENT_BUY, 0.95, 'comercial')];

        gfSay('Va, lo quiero');

        expect(gfLast())->toContain('¿Qué plan de «Asesor» quieres?')->not->toMatch(StageReply::PLANS_MARKER)->not->toContain('Perfecto');
    });

    it('shows no plans for a marker in a message not read as commercial, and never the marker', function (string $raw) {
        gfSay('¿Cómo le hablo a una chica en un bar?');
        $this->travel(25)->hours();
        $this->turns = [$raw];

        gfSay('¿Y en una discoteca?');

        expect(gfLast())->not->toContain('$9.900')->not->toMatch(StageReply::PLANS_MARKER);
    })->with([
        'contenido' => [gfJson('Entiendo. Eso se trabaja con el servicio completo. Si quieres, conoce los planes. [Planes]', question: 'contenido')],
        'sin clasificar' => [gfJson('Entiendo. Eso se trabaja con el servicio completo. Si quieres, conoce los planes. [Planes]')],
        'texto libre, no JSON' => ['Entiendo. Eso se trabaja con el servicio completo. [planes]'],
    ]);

    it('sends the offer alone, safely, when the argument does not pass', function () {
        $this->turns = [gfJson('Prueba mirarla a los ojos cuando le hables. [Planes]', question: 'comercial')];

        gfSay('¿Esto cómo funciona?');

        expect(gfLast())->not->toContain('mirarla')->toContain('Planes de «Asesor»:')->toContain('$9.900')
            ->and($this->verifierPrompts)->toBe([]);
    });

    it('checks the argument that went out, without the marker', function () {
        $this->turns = [gfJson('Con el plan semanal empiezas hoy. [Planes]', question: 'comercial')];

        gfSay('¿Esto cómo funciona?');

        $checked = collect($this->chats)->first(fn (array $messages) => str_contains($messages[0]['content'], 'Eres un verificador'));

        expect($this->verifierPrompts)->toHaveCount(1)->and($checked[1]['content'])->toBe('Con el plan semanal empiezas hoy.');
    });

    it('does not repeat the plans the model asks for right after they were shown, but the customer may always see them', function () {
        $this->turns = [gfJson('Con el plan semanal empiezas hoy. [Planes]', question: 'comercial'), gfJson('Te los dejo aquí. [Planes]', question: 'comercial')];

        gfSay('¿Esto cómo funciona?');
        gfSay('¿Y cuáles eran?');

        expect(gfLast())->toBe("Te los dejo aquí.\n\n".$this->pointer);

        gfSay('Planes');

        expect(gfLast())->toContain('$9.900')->toContain('$19.900');
    });

    it('reads [Planes] in a configured text as {planes}: the catalog, its buttons and the offer counted as shown', function () {
        $this->product->update(['opening_message' => "Hola, soy tu asesor.\n\n[Planes]\n\nCuéntame qué buscas.", 'opening_triggers' => ['Quiero conocer el asesor']]);

        gfSay('Quiero conocer el asesor');

        expect(gfLast())->toContain('1. Semanal: $9.900 COP por 7 días')->toEndWith('Cuéntame qué buscas.')->not->toMatch(StageReply::PLANS_MARKER)
            ->and(($this->lastSendType)())->toBe('interactive')
            ->and(Conversation::first()->offers_shown)->toHaveKey((string) $this->product->id);
    });
});

describe('a static opening and the conversation after it', function () {
    beforeEach(function () {
        gfPublish(free: 1);
        $this->product->update([
            'opening_message' => "Hola, soy tu {producto}.\n\nTe ayudo con tus situaciones y te explico cómo funciona.\n\n[Planes]\n\nResponde con el número del plan que prefieras.",
            'opening_triggers' => ['Quiero conocer el asesor'],
        ]);
        $this->pointer = 'Escribe «planes» para ver los precios o «pagar» para suscribirte.';
    });

    it('sends the opening exactly as configured, with the official plans, and the model writes no presentation', function () {
        gfSay('¡Hola! Quiero conocer el asesor');
        gfSay('Quiero conocer el asesor', '573000000001');

        $bodies = collect(sentWhatsAppTexts())->map(fn ($request) => $request->data()['text']['body'])->all();

        expect($bodies)->toHaveCount(2)
            ->and($bodies[0])->toStartWith("Hola, soy tu Asesor.\n\nTe ayudo con tus situaciones y te explico cómo funciona.\n\n")
            ->toContain("1. Semanal: \$9.900 COP por 7 días\n2. Mensual: \$19.900 COP por 30 días")
            ->toEndWith('Responde con el número del plan que prefieras.')->not->toMatch(StageReply::PLANS_MARKER)
            // Deterministic: every prospect gets the same text.
            ->and($bodies[1])->toBe($bodies[0])
            ->and($this->turnPrompts)->toBe([])->and($this->prompts)->toBe([])
            ->and(Conversation::first()->offers_shown)->toHaveKey((string) $this->product->id)
            ->and(WhatsAppMessage::where('role', 'assistant')->where('customer_phone', GF_PHONE)->value('content'))->toBe($bodies[0])
            ->and(gfTurns())->toBe(0)->and(gfReads())->toBe(0)->and(Payment::count())->toBe(0);
    });

    it('answers a greeting or a thanks naturally, without the plans nor how to ask for them', function (string $message, string $reply) {
        gfSay('Quiero conocer el asesor');
        $this->turns = [gfJson($reply, question: 'cortesia')];

        gfSay($message);

        expect(gfLast())->toBe($reply)->and(Payment::count())->toBe(0)->and(gfTurns())->toBe(0);
    })->with([
        'saludo' => ['Hola, buenas noches', '¡Buenas noches! Qué gusto saludarte.'],
        'cortesía' => ['Un gusto saludarte', 'El gusto es mío.'],
        'agradecimiento' => ['Gracias', 'Con mucho gusto.'],
    ]);

    it('answers a courtesy with the Core\'s neutral reply when the model\'s cannot go out, never with how to ask for the plans', function (Closure $setUp) {
        gfSay('Quiero conocer el asesor');
        $setUp->call($this);

        gfSay('Gracias');

        expect(gfLast())->toBe(config('botinker.sales_funnels.guided.courtesy_reply'))->not->toContain($this->pointer)->and(Payment::count())->toBe(0);
    })->with([
        'da contenido' => [fn () => $this->turns = [gfJson("Gracias a ti. Prueba esto:\n- Míralo a los ojos", question: 'cortesia')]],
        'demasiado larga' => [fn () => $this->turns = [gfJson(str_repeat('Con gusto te acompaño. ', 25), question: 'cortesia')]],
        'el modelo falla' => [fn () => $this->modelDown = true],
    ]);

    it('answers how the service works and an objection in the model\'s words, without inviting to the plans again or starting a payment', function (string $message, string $reply) {
        gfSay('Quiero conocer el asesor');
        $this->turns = [gfJson($reply, question: 'comercial')];

        gfSay($message);

        expect(gfLast())->toBe($reply)->and(Payment::count())->toBe(0);
    })->with([
        'funcionamiento' => ['¿Cómo funciona?', 'Me cuentas tu situación por WhatsApp y te oriento paso a paso con el servicio.'],
        'objeción' => ['Es caro', 'Te entiendo. Con el plan semanal puedes empezar con poco y ver si te sirve.'],
    ]);

    it('reads a request for information about the subscription as a question, not as a purchase', function () {
        $calls = count($this->turnPrompts);
        $this->turns = [gfJson('Con la suscripción conversas conmigo cuando lo necesites.', question: 'comercial')];

        gfSay('Quiero información de la suscripción');

        expect(gfLast())->toStartWith('Con la suscripción conversas conmigo')->not->toContain('¿Qué plan de «Asesor» quieres?')
            ->and($this->turnPrompts)->toHaveCount($calls + 1)
            ->and(Conversation::first()->pending_state)->toBeNull()
            ->and(Payment::count())->toBe(0);
    });

    it('starts the payment flow for an explicit request to pay, without the model', function () {
        gfSay('Quiero pagar');

        expect(gfLast())->toContain('¿Qué plan de «Asesor» quieres?')->and($this->turnPrompts)->toBe([]);

        gfSay('2');

        expect(Payment::sole()->plan->name)->toBe('Mensual')->and(Payment::sole()->status)->toBe(Payment::STATUS_PENDING);
    });

    it('keeps conversing with a payment open: questions get answers, not the payment instructions again', function () {
        gfSay('Quiero el semanal');
        expect(gfLast())->toContain('INSTRUCCIONES-PAGO');

        foreach ([
            ['Nequi', gfJson('Sí, puedes pagar por Nequi con los datos que te envié.', question: 'comercial')],
            ['¿Cómo envío el comprobante?', gfJson('Lo envías como foto por este mismo chat.', question: 'comercial')],
            ['Gracias', gfJson('Con gusto.', question: 'cortesia')],
        ] as [$message, $turn]) {
            $this->turns = [$turn];

            gfSay($message);

            expect(gfLast())->toBe(json_decode($turn, true)['reply'])->not->toContain('INSTRUCCIONES-PAGO');
        }

        expect(Payment::count())->toBe(1)->and(Payment::sole()->status)->toBe(Payment::STATUS_PENDING);
    });

    it('gives the payment instructions again only when asked, and never a second payment', function () {
        gfSay('Quiero el semanal');
        $payment = Payment::sole();

        gfSay('¿Cómo pago?');
        expect(gfLast())->toContain('INSTRUCCIONES-PAGO');

        // The model's offer stands as the open payment's reminder, not the plans again.
        $this->turns = [gfJson('Claro.', GuidedFunnel::INTENT_OFFER, 0.9, 'comercial')];
        gfSay('¿Me recuerdas las opciones?');
        expect(gfLast())->toContain('sigue pendiente')->not->toContain('$19.900');

        // A decision to go on with it gets the payment it already has.
        $this->turns = [gfJson('', GuidedFunnel::INTENT_BUY, 0.95, 'comercial')];
        gfSay('Sí, lo quiero ya');
        expect(gfLast())->toContain('INSTRUCCIONES-PAGO');

        expect(Payment::count())->toBe(1)->and(Payment::sole()->id)->toBe($payment->id)->and(Payment::sole()->plan_name)->toBe('Semanal');

        // Another plan named: the pending one gives way, untouched, and there is still one open payment.
        gfSay('Quiero el mensual');

        expect(Payment::whereIn('status', Payment::OPEN_STATUSES)->sole()->plan_name)->toBe('Mensual')
            ->and($payment->fresh()->status)->toBe(Payment::STATUS_EXPIRED)->and((float) $payment->fresh()->amount)->toBe(9900.0)
            ->and(Subscription::count())->toBe(0);
    });

    it('answers a courtesy without the limit reply once the free replies are spent, and still limits content', function () {
        gfSay('¿Cómo le hablo a una chica en un bar?');
        expect(gfTurns())->toBe(1);

        $this->turns = [gfJson('Con gusto.', question: 'cortesia')];
        gfSay('Gracias');

        expect(gfLast())->toBe('Con gusto.')->not->toContain(config('botinker.sales_funnels.guided.limit_reply'))->and(gfTurns())->toBe(1);

        $this->turns = [gfJson('Te doy otro consejo gratis.', question: 'contenido')];
        gfSay('¿Y en una discoteca?');

        expect(gfLast())->not->toContain('consejo')->and(gfTurns())->toBe(1);
    });

    it('leaves a funnel made of stages as it was: a payment open still gets its instructions', function () {
        $this->versions->publish($this->product, ['name' => 'N', 'goal' => 'G', 'free_replies' => ['limit' => 1], 'stages' => [['key' => 'a', 'type' => 'ai', 'goal' => 'g', 'instructions' => 'i', 'uses_free_replies' => true]]]);
        gfSay('Quiero el semanal');
        $calls = count($this->turnPrompts) + count($this->prompts);

        gfSay('Tengo una duda');

        expect(gfLast())->toContain('INSTRUCCIONES-PAGO')->and(count($this->turnPrompts) + count($this->prompts))->toBe($calls);
    });
});

describe('a message the model did not classify', function () {
    beforeEach(function () {
        gfPublish(free: 1);
        $this->neutral = config('botinker.sales_funnels.guided.unclassified_reply');
        $this->pointer = 'Escribe «planes» para ver los precios o «pagar» para suscribirte.';
    });

    it('asks the model to understand misspelled, colloquial or incomplete messages within the same call', function () {
        $this->turns = [gfJson('', question: 'contenido')];
        $this->replies = [gfJson('RESPUESTA-A-SU-DIFICULTAD')];

        gfSay('Mujer difisil');

        expect(gfLastTurnPrompt())->toContain('errores de ortografía, abreviaciones, expresiones coloquiales o esté incompleto')
            ->toContain('sin corregirlo, sin comentar sus errores y sin inventar detalles que no dijo')
            ->toContain('también cuando solo describe en pocas palabras una situación o dificultad personal')
            ->and($this->turnPrompts)->toHaveCount(1);
    });

    it('gives the free reply to a misspelled difficulty read as content, never an offer', function (string $message) {
        $this->turns = [gfJson('', question: 'contenido')];
        $this->replies = [gfJson('RESPUESTA-A-SU-DIFICULTAD')];

        gfSay($message);

        expect(gfLast())->toStartWith('RESPUESTA-A-SU-DIFICULTAD')->not->toContain($this->pointer)
            ->and(gfTurns())->toBe(1)->and(Payment::count())->toBe(0);
    })->with(['Mujer difisil', 'Quiero tener nv', 'No me ase caso', 'No se como ablarle', 'Me ignora muxo', 'Es mui fria']);

    it('answers a message it did not classify with the neutral reply: no plans, no content, and the free reply stays', function (string $turn) {
        $this->turns = [$turn];

        gfSay('Mujer difisil');

        expect(gfLast())->toBe($this->neutral)->not->toContain('$9.900')->not->toContain('mirada')
            ->and(gfTurns())->toBe(0)->and(gfReads())->toBe(1)
            ->and(SampleDelivery::count())->toBe(0)->and(Payment::count())->toBe(0);
    })->with([
        // The case seen in production: unclassified, and too long for an unclassified reply.
        'demasiado larga' => [gfJson(str_repeat('Te entiendo, cada situación es distinta y vale la pena verla con calma. ', 8))],
        'con contenido' => [gfJson('Fíjate en su mirada y luego háblale.')],
        'pide aclarar a su manera' => [gfJson('¿Me cuentas un poco más de lo que pasó con ella?')],
        'campo vacío' => ['{"reply": "Te entiendo.", "intent": null, "confidence": 0, "question": null}'],
    ]);

    it('lets the customer say more after the neutral reply and then gives the free reply', function () {
        $this->turns = [gfJson('Fíjate en su mirada.'), gfJson('', question: 'contenido')];
        $this->replies = [gfJson('RESPUESTA-A-SU-DIFICULTAD')];

        gfSay('Ella...');
        expect(gfLast())->toBe($this->neutral);

        gfSay('Me gusta una compañera y no sé cómo hablarle');

        expect(gfLast())->toStartWith('RESPUESTA-A-SU-DIFICULTAD')->and(gfTurns())->toBe(1);
    });

    it('uses the neutral reply each guided funnel configures, else the generic one', function () {
        $this->versions->publish($this->product, GuidedFunnel::definition('INSTRUCCION-DEL-ADMINISTRADOR', 1, null, 2, '¿Me cuentas un poco más para orientarte?'));
        $this->turns = [gfJson('Fíjate en su mirada.')];

        gfSay('Ella...');

        expect(gfLast())->toBe('¿Me cuentas un poco más para orientarte?')
            ->and(GuidedFunnel::unclassifiedReply(GuidedFunnel::definition('I', 1)))->toBeNull()
            ->and(fn () => FunnelDefinition::fromArray(GuidedFunnel::definition('I', 1, null, 2, str_repeat('x', GuidedFunnel::MAX_UNCLASSIFIED_REPLY_LENGTH + 1))))->toThrow(InvalidArgumentException::class)
            ->and(fn () => FunnelDefinition::fromArray(['name' => 'N', 'goal' => 'G', 'unclassified_reply' => 'X', 'stages' => [['key' => 'a', 'type' => 'ai', 'goal' => 'g', 'instructions' => 'i']]]))->toThrow(InvalidArgumentException::class);
    });

    it('publishes the neutral reply from the guided funnel\'s editor', function () {
        $this->actingAs(User::factory()->create(['store_id' => $this->store->id, 'is_super_admin' => false]));

        gfPage()
            ->fillForm(['instructions' => 'Vende con calma.', 'free_replies' => 2, 'unclassified_reply' => '  Cuéntame más, por favor.  '])
            ->callAction('publish', data: ['confirmed' => true])
            ->assertHasNoActionErrors();

        expect(GuidedFunnel::unclassifiedReply($this->product->fresh()->activeFunnel()->definition))->toBe('Cuéntame más, por favor.');
    });

    it('keeps the limit as it was once the free replies are spent', function () {
        $this->replies = [gfJson('RESPUESTA-A-SU-DIFICULTAD')];
        $this->turns = [gfJson('', question: 'contenido'), gfJson('Fíjate en su mirada.')];
        gfSay('Mujer difisil');

        gfSay('Ella...');

        expect(gfLast())->toStartWith(config('botinker.sales_funnels.guided.limit_reply'))->not->toBe($this->neutral)->not->toContain('mirada')->and(gfTurns())->toBe(1);
    });

    it('does not invite to say more when no read was left to read it', function () {
        gfSpendReads();
        $this->travel(25)->hours();
        $this->turns = [gfJson('Te acompaño por WhatsApp.', question: 'comercial')];
        gfSay('¿Esto cómo funciona?');

        gfSay('Ella...');

        expect(gfLast())->not->toBe($this->neutral)->and(gfTurns())->toBe(0);
    });

    it('logs how the answer carried the question, without its words', function (string $turn, string $field, ?string $question) {
        Log::spy();
        $this->turns = [$turn];

        gfSay('Mmm');

        Log::shouldHaveReceived('info')->with('FUNNEL_LIMIT_READ', Mockery::on(fn (array $context) => $context['question_field'] === $field && $context['question'] === $question
            && is_int($context['reply_characters'])
            && array_slice(array_keys($context), -6) === ['question_field', 'reply_characters', 'fallback', 'fallback_discarded', 'closing', 'closing_discarded']
            && ! in_array('Te entiendo.', $context, true)));
    })->with([
        'ausente' => ['{"reply": "Te entiendo.", "intent": null, "confidence": 0}', StageReply::FIELD_ABSENT, null],
        'vacío' => ['{"reply": "Te entiendo.", "intent": null, "confidence": 0, "question": null}', StageReply::FIELD_EMPTY, null],
        'en blanco' => ['{"reply": "Te entiendo.", "intent": null, "confidence": 0, "question": " "}', StageReply::FIELD_EMPTY, null],
        'inválido' => ['{"reply": "Te entiendo.", "intent": null, "confidence": 0, "question": "venta"}', StageReply::FIELD_INVALID, null],
        'válido' => ['{"reply": "Te entiendo.", "intent": null, "confidence": 0, "question": "cortesia"}', StageReply::FIELD_VALID, 'cortesia'],
    ]);
});

describe('a payment open: another plan, voice notes and refusals', function () {
    beforeEach(function () {
        gfPublish(free: 1);
        $this->weekly = Plan::where('name', 'Semanal')->value('id');
        $this->monthly = Plan::where('name', 'Mensual')->value('id');
        // The plan's reply button, as WhatsApp sends it.
        $this->tap = fn (int $plan, string $title, string $wamid = '') => ProcessWhatsAppMessage::dispatchSync($this->store->fresh(), GF_PHONE, $title, $wamid ?: 'wamid.in.'.Str::random(10), 'interactive', null, null, null, 'plan:'.$plan);
        $this->voice = function (string $transcription) {
            $this->transcriptions[] = $transcription;
            ProcessWhatsAppMessage::dispatchSync($this->store->fresh(), GF_PHONE, null, 'wamid.in.'.Str::random(10), 'audio', 'media-'.Str::random(6));
            $this->travel(1)->seconds();
        };
        // The plans shown, as the opening shows them: the conversation is about this product.
        gfSay('Planes');
    });

    it('changes a pending payment to the plan chosen next: the first expires untouched, one open payment, never two', function () {
        Log::spy();
        ($this->tap)($this->weekly, 'Semanal');
        ($this->tap)($this->monthly, 'Mensual');

        $first = Payment::orderBy('id')->first();
        $second = Payment::orderBy('id')->get()->last();

        expect(Payment::count())->toBe(2)
            ->and($first->status)->toBe(Payment::STATUS_EXPIRED)->and($first->plan_name)->toBe('Semanal')->and((float) $first->amount)->toBe(9900.0)
            ->and($second->status)->toBe(Payment::STATUS_PENDING)->and($second->plan_name)->toBe('Mensual')->and((float) $second->amount)->toBe(19900.0)
            ->and(Payment::whereIn('status', Payment::OPEN_STATUSES)->count())->toBe(1)
            ->and(gfLast())->toStartWith('Listo, cambiamos tu pago al plan Mensual.')->toContain('$19.900 COP por 30 días')->toContain('INSTRUCCIONES-PAGO')->not->toContain('$9.900')
            ->and(Subscription::count())->toBe(0);
        Log::shouldHaveReceived('info')->with('PAYMENT_PLAN_CHANGED', Mockery::on(fn (array $context) => $context['expired_payment_id'] === $first->id && $context['plan_id'] === $this->monthly));
    });

    it('also changes it when the customer writes the other plan\'s name', function () {
        ($this->tap)($this->weekly, 'Semanal');

        gfSay('Mensual');

        expect(Payment::whereIn('status', Payment::OPEN_STATUSES)->sole()->plan_name)->toBe('Mensual')
            ->and(Payment::where('plan_name', 'Semanal')->sole()->status)->toBe(Payment::STATUS_EXPIRED);
    });

    it('keeps the payment for the same plan chosen again, or the same message retried, with no second one', function () {
        ($this->tap)($this->weekly, 'Semanal');
        ($this->tap)($this->weekly, 'Semanal');
        ($this->tap)($this->monthly, 'Mensual', 'wamid.mensual');
        ($this->tap)($this->monthly, 'Mensual', 'wamid.mensual');

        expect(Payment::count())->toBe(2)->and(Payment::whereIn('status', Payment::OPEN_STATUSES)->sole()->plan_name)->toBe('Mensual')
            ->and(gfLast())->toStartWith('Pago de «Asesor», plan Mensual');
    });

    it('never changes a payment a receipt was already sent for', function () {
        ($this->tap)($this->weekly, 'Semanal');
        Payment::sole()->update(['status' => Payment::STATUS_UNDER_REVIEW, 'receipt_submitted_at' => now()]);

        ($this->tap)($this->monthly, 'Mensual');

        expect(Payment::count())->toBe(1)->and(Payment::sole()->status)->toBe(Payment::STATUS_UNDER_REVIEW)->and(Payment::sole()->plan_name)->toBe('Semanal')
            ->and(gfLast())->toContain('está en revisión');
    });

    it('gives the payment instructions when asked how to pay', function () {
        ($this->tap)($this->weekly, 'Semanal');

        gfSay('¿Cómo pago?');

        expect(gfLast())->toContain('INSTRUCCIONES-PAGO')->toContain('plan Semanal')->and(Payment::count())->toBe(1);
    });

    it('lets a voice note reach the model with a payment open, and answers what it says, not the payment again', function () {
        ($this->tap)($this->weekly, 'Semanal');
        $this->turns = [gfJson('Te entiendo, quieres saber cómo te acompaña el servicio con tu novia.', question: 'comercial')];

        ($this->voice)('Pero primero mi novia, también el número una hembra y le doy la plata.');

        $chat = collect($this->chats)->last(fn (array $messages) => str_contains($messages[0]['content'], 'Este es un turno comercial'));

        expect(gfLast())->toBe('Te entiendo, quieres saber cómo te acompaña el servicio con tu novia.')->not->toContain('sigue pendiente')
            ->and(end($chat)['content'])->toBe('🎤 [AUDIO]: Pero primero mi novia, también el número una hembra y le doy la plata.')
            ->and(WhatsAppMessage::where('role', 'user')->latest('id')->value('content'))->toStartWith('🎤 [AUDIO]: Pero primero mi novia')
            ->and(Payment::count())->toBe(1);
    });

    it('gives the free reply to a request for content with a payment open', function () {
        ($this->tap)($this->weekly, 'Semanal');
        $this->turns = [gfJson('', question: 'contenido')];
        $this->replies = [gfJson('CONTENIDO-DE-LA-RESPUESTA-GRATUITA')];

        ($this->voice)('¿Cómo le hablo a una chica que me gusta?');

        expect(gfLast())->toStartWith('CONTENIDO-DE-LA-RESPUESTA-GRATUITA')->and(gfTurns())->toBe(1)->and(Payment::sole()->status)->toBe(Payment::STATUS_PENDING);
    });

    it('answers a refusal or frustration calmly, with no plans and no payment reminder, and never buys on it', function (string $turn, string $expected) {
        ($this->tap)($this->weekly, 'Semanal');
        $this->turns = [$turn];

        ($this->voice)('Vámonos, qué rabia, no sirve para nada.');

        expect(gfLast())->toBe($expected)->not->toContain('sigue pendiente')->not->toContain('$9.900')->not->toContain('INSTRUCCIONES-PAGO')
            ->and(Payment::count())->toBe(1)->and(Subscription::count())->toBe(0);
    })->with([
        'su respuesta' => [gfJson('Entiendo tu molestia. Aquí estaré si quieres retomarlo.', question: 'rechazo'), 'Entiendo tu molestia. Aquí estaré si quieres retomarlo.'],
        'una respuesta que no puede salir' => [gfJson("Tranquilo, prueba esto:\n- Respira", GuidedFunnel::INTENT_BUY, 0.99, 'rechazo'), 'Entiendo. Si en otro momento quieres retomarlo, aquí estaré.'],
    ]);

    it('does not repeat the payment reminder to whatever comes in', function () {
        ($this->tap)($this->weekly, 'Semanal');
        $this->turns = [
            gfJson('Fíjate en su mirada.'),
            gfJson('Con gusto.', question: 'cortesia'),
            gfJson('Entiendo, aquí estaré.', question: 'rechazo'),
        ];

        foreach (['Mmm ella', 'Gracias', 'Qué fastidio'] as $message) {
            gfSay($message);

            expect(gfLast())->not->toContain('sigue pendiente')->not->toContain('INSTRUCCIONES-PAGO');
        }

        expect(Payment::count())->toBe(1)->and(Payment::sole()->status)->toBe(Payment::STATUS_PENDING)->and(gfTurns())->toBe(0);
    });
});

describe('the fallback reply of the commercial turn', function () {
    beforeEach(function () {
        gfPublish(free: 1);
        $this->neutral = config('botinker.sales_funnels.guided.unclassified_reply');
        $this->pointer = 'Escribe «planes» para ver los precios o «pagar» para suscribirte.';
        $this->fallback = 'Te entiendo, esas situaciones se trabajan con calma, y el servicio está pensado para acompañarte en ellas.';
        // A reply that cannot go out unclassified: too long for the strict shape.
        $this->long = str_repeat('Te entiendo, cada situación es distinta y merece verse con calma. ', 8);
        $this->logged = fn () => Log::shouldHaveReceived('info')->with('FUNNEL_LIMIT_READ', Mockery::any());
    });

    it('sends the main reply when it is valid, and never the fallback', function () {
        $this->turns = [gfJson('Te acompaño por WhatsApp durante tu suscripción.', question: 'comercial', fallback: $this->fallback)];

        gfSay('¿Esto cómo funciona?');

        expect(gfLast())->toStartWith('Te acompaño por WhatsApp durante tu suscripción.')->not->toContain($this->fallback);
    });

    it('sends the fallback alone for a message it did not classify, with no plans, and the free reply stays', function () {
        Log::spy();
        $this->turns = [gfJson($this->long, fallback: $this->fallback)];

        gfSay('Uno queda mudo frente a ella, gracias por los consejos');

        expect(gfLast())->toBe($this->fallback)->not->toContain('$9.900')->not->toContain($this->pointer)
            ->and(gfTurns())->toBe(0)->and(Payment::count())->toBe(0);
        Log::shouldHaveReceived('info')->with('FUNNEL_LIMIT_READ', Mockery::on(fn (array $context) => $context['fallback'] === 'model' && $context['fallback_discarded'] === null));
    });

    it('sends the fallback instead of how to ask for the plans when a commercial wording is discarded right after them', function () {
        gfSay('Planes');
        $this->turns = [gfJson("Mira:\n- Primero esto", question: 'comercial', fallback: $this->fallback)];

        gfSay('¿Y eso cómo me ayuda?');

        expect(gfLast())->toBe($this->fallback)->not->toContain($this->pointer);
    });

    it('adds the plans after the fallback of a commercial question when they were not shown recently', function () {
        $this->travel(25)->hours();
        $this->turns = [gfJson("Mira:\n- Primero esto", question: 'comercial', fallback: $this->fallback)];

        gfSay('¿Y eso cómo me ayuda?');

        expect(gfLast())->toStartWith($this->fallback."\n\n")->toContain('$9.900')->toContain('$19.900');
    });

    it('goes back to the fixed text when the fallback is invalid or empty', function (?string $fallback, string $reason) {
        Log::spy();
        $this->turns = [gfJson($this->long, fallback: $fallback)];

        gfSay('Uno queda mudo frente a ella');

        expect(gfLast())->toBe($this->neutral);
        Log::shouldHaveReceived('info')->with('FUNNEL_LIMIT_READ', Mockery::on(fn (array $context) => $context['fallback'] === 'fixed' && $context['fallback_discarded'] === $reason));
    })->with([
        'vacío' => [null, 'empty'],
        'en blanco' => ['   ', 'empty'],
        'con instrucciones' => ['Prueba hablarle con calma y mírala a los ojos.', 'instruction'],
        'con una lista' => ["Te ayudo con:\n- Seguridad\n- Confianza", 'list'],
        'con un precio' => ['El servicio cuesta $9.900 y te acompaña.', 'currency'],
        'con una garantía' => ['Te garantizamos que el servicio te funcionará.', 'unbacked_claim'],
        'con una oferta' => ['El servicio te acompaña; activa tu suscripción hoy.', 'commercial'],
        'demasiado larga' => [str_repeat('El servicio te acompaña con calma. ', 10), 'too_long'],
        'demasiadas frases' => ['Te entiendo. Es normal. El servicio te acompaña.', 'too_many_sentences'],
    ]);

    it('goes back to the fixed text when the fallback says again what the conversation already said', function () {
        Log::spy();
        gfSeedTurn('tema', 'Hola', $this->fallback);
        $this->turns = [gfJson($this->long, fallback: '¡Te entiendo! Esas situaciones se trabajan con calma, y el servicio está pensado para acompañarte en ellas.')];

        gfSay('Uno queda mudo frente a ella');

        expect(gfLast())->toBe($this->neutral);
        Log::shouldHaveReceived('info')->with('FUNNEL_LIMIT_READ', Mockery::on(fn (array $context) => $context['fallback_discarded'] === 'repeated'));
    });

    it('never adds the payment reminder to the fallback with a payment open', function (string $turn) {
        gfSay('Quiero el semanal');
        $this->turns = [$turn];

        gfSay('¿Y eso cómo me ayuda?');

        expect(gfLast())->toBe($this->fallback)->not->toContain('sigue pendiente')->and(Payment::count())->toBe(1);
    })->with(fn () => [
        'sin clasificar' => [gfJson(str_repeat('Te entiendo, cada situación es distinta. ', 8), fallback: 'Te entiendo, esas situaciones se trabajan con calma, y el servicio está pensado para acompañarte en ellas.')],
        'comercial descartado' => [gfJson("Mira:\n- Primero esto", question: 'comercial', fallback: 'Te entiendo, esas situaciones se trabajan con calma, y el servicio está pensado para acompañarte en ellas.')],
    ]);

    it('leaves the free reply, refusals, courtesies, purchases and the limit as they were', function () {
        // A request for content: the free reply.
        $this->turns = [gfJson('', question: 'contenido', fallback: $this->fallback)];
        $this->replies = [gfJson('CONTENIDO-DE-LA-RESPUESTA-GRATUITA')];
        gfSay('¿Cómo le hablo a una chica en un bar?');
        expect(gfLast())->toStartWith('CONTENIDO-DE-LA-RESPUESTA-GRATUITA')->not->toContain($this->fallback);

        // A refusal whose reply cannot go out: its own fixed reply.
        $this->turns = [gfJson("Tranquilo:\n- Respira", question: 'rechazo', fallback: $this->fallback)];
        gfSay('Qué rabia');
        expect(gfLast())->toBe(config('botinker.sales_funnels.guided.decline_reply'));

        // A courtesy whose reply cannot go out: its own fixed reply.
        $this->turns = [gfJson(str_repeat('Con gusto te acompaño. ', 25), question: 'cortesia', fallback: $this->fallback)];
        gfSay('Gracias');
        expect(gfLast())->toBe(config('botinker.sales_funnels.guided.courtesy_reply'));

        // No free reply left, and no close that passes: the fallback, about the service in every sentence, and how to ask for the plans.
        $this->turns = [gfJson($this->long, fallback: $this->fallback)];
        gfSay('Uno queda mudo frente a ella');
        expect(gfLast())->toBe($this->fallback."\n\nEscribe «planes» para ver los precios o «pagar» para suscribirte.");

        // A purchase: the payment flow.
        $this->turns = [gfJson('', GuidedFunnel::INTENT_BUY, 0.95, 'comercial', $this->fallback)];
        gfSay('Va, me quedo con esto');
        expect(gfLast())->toContain('¿Qué plan de «Asesor» quieres?')->not->toContain($this->fallback);
    });

    it('sends the same fallback once when the message is retried, with no second model call', function () {
        $this->turns = [gfJson($this->long, fallback: $this->fallback)];
        $this->whatsAppDown = true;

        expect(fn () => ProcessWhatsAppMessage::dispatchSync($this->store->fresh(), GF_PHONE, 'Uno queda mudo frente a ella', 'wamid.respaldo', 'text', null))->toThrow(RuntimeException::class);

        $this->whatsAppDown = false;
        ProcessWhatsAppMessage::dispatchSync($this->store->fresh(), GF_PHONE, 'Uno queda mudo frente a ella', 'wamid.respaldo', 'text', null);

        expect($this->turnPrompts)->toHaveCount(1)->and(gfLast())->toBe($this->fallback)
            ->and(WhatsAppMessage::where('role', 'assistant')->where('content', $this->fallback)->count())->toBe(1);
    });

    it('asks for no fallback when it is turned off, and keeps the fixed texts', function () {
        config(['botinker.sales_funnels.guided.fallback_reply.enabled' => false]);
        $this->turns = [gfJson($this->long, fallback: $this->fallback)];

        gfSay('Uno queda mudo frente a ella');

        $format = collect(Http::recorded(fn (Request $r) => str_contains($r->url(), 'api.openai.com') && str_contains($r->data()['messages'][0]['content'], 'Este es un turno comercial')))->sole()[0]->data()['response_format'];

        expect(gfLast())->toBe($this->neutral)
            ->and($format['json_schema']['schema']['required'])->toBe(['reply', 'intent', 'confidence', 'question'])
            ->and(gfLastTurnPrompt())->not->toContain('fallback_reply');
    });

    it('never sends an empty message when a configured text is missing: the Core\'s own text goes', function () {
        Log::spy();
        config(['botinker.sales_funnels.guided.unclassified_reply' => null, 'botinker.sales_funnels.guided.fallback_reply.enabled' => false]);
        $this->turns = [gfJson($this->long)];

        gfSay('Uno queda mudo frente a ella');

        expect(gfLast())->toBe($this->pointer);
        Log::shouldHaveReceived('warning')->with('FUNNEL_EMPTY_REPLY', Mockery::any());
    });

    it('works the same for a product of another domain, built only from that product\'s information', function () {
        $store = makeStore(['conversation_mode' => Store::MODE_KNOWLEDGE, 'payment_instructions' => 'INSTRUCCIONES-PAGO-2', 'system_prompt' => 'PERSONA-OTRA-TIENDA']);
        Topic::create(['store_id' => $store->id, 'key' => 'cocina', 'label' => 'Cocina', 'instructions' => 'INSTRUCCIONES-COCINA']);
        $product = Product::create([
            'store_id' => $store->id, 'domain_key' => 'cocina', 'name' => 'Recetario', 'description' => 'DESCRIPCION-RECETARIO', 'price' => 1, 'stock' => 1,
            'access_mode' => Product::ACCESS_SUBSCRIPTION, 'ai_sales_strategy' => 'SERVICIO-COCINA',
        ]);
        Plan::create(['product_id' => $product->id, 'name' => 'Anual', 'price' => 50000, 'duration_days' => 365]);
        $this->versions->publish($product, GuidedFunnel::definition('INSTRUCCION-RECETARIO', 1));
        $fallback = 'Organizar las comidas de la semana cuesta, y el recetario está pensado justo para hacerlo más sencillo.';
        $this->turns = [gfJson($this->long, fallback: $fallback)];

        ProcessWhatsAppMessage::dispatchSync($store->fresh(), '573001110000', 'Me cuesta mucho organizar las comidas', 'wamid.in.cocina', 'text', null);

        expect(gfLast())->toBe($fallback)
            ->and(gfLastTurnPrompt())->toContain('DESCRIPCION-RECETARIO')->toContain('INSTRUCCION-RECETARIO')
            ->toContain(config('botinker.sales_funnels.guided.fallback_reply.instruction'))
            ->not->toContain('DESCRIPCION-PRODUCTO');
    });

    it('reads the fallback from the answer, never a [Planes] in it as a request for the plans', function () {
        $reply = StageReply::parse(json_encode(['reply' => 'Hola.', 'intent' => null, 'confidence' => 0, 'question' => null, 'fallback_reply' => ' Te acompaño. [Planes] ']), true, ['presentar_oferta' => 'x']);

        expect($reply->fallback)->toBe('Te acompaño.')->and($reply->intent)->toBeNull()
            ->and(StageReply::parse(gfJson('Hola.'), true, [])->fallback)->toBeNull();
    });
});

describe('the structured answer of the commercial turn', function () {
    beforeEach(function () {
        gfPublish(free: 1);
        $this->openAi = fn (string $marker) => collect(Http::recorded(fn (Request $request) => str_contains($request->url(), 'api.openai.com')
            && str_contains($request->data()['messages'][0]['content'], $marker)))->map(fn ($pair) => $pair[0]->data())->values();
    });

    it('asks for its JSON schema: every field required, the intent among the funnel\'s and the question among the readings, or null', function () {
        $this->turns = [gfJson('', question: 'contenido')];

        gfSay('¿Cómo le hablo a una chica en un bar?');

        $format = ($this->openAi)('Este es un turno comercial')->sole()['response_format'];
        $schema = $format['json_schema']['schema'];

        expect($format['type'])->toBe('json_schema')->and($format['json_schema']['strict'])->toBeTrue()
            ->and($schema['required'])->toBe(['reply', 'intent', 'confidence', 'question', 'fallback_reply'])
            ->and($schema['additionalProperties'])->toBeFalse()
            ->and($schema['properties']['question']['anyOf'])->toBe([['type' => 'string', 'enum' => ['comercial', 'contenido', 'cortesia', 'rechazo']], ['type' => 'null']])
            ->and($schema['properties']['intent']['anyOf'][0]['enum'])->toBe([GuidedFunnel::INTENT_BUY, GuidedFunnel::INTENT_OFFER]);
    });

    it('never forces JSON on a call that expects free text', function () {
        $this->turns = [gfJson('', question: 'contenido')];
        $this->replies = [gfJson('CONTENIDO-DE-LA-RESPUESTA-GRATUITA')];

        gfSay('¿Cómo le hablo a una chica en un bar?');

        $others = collect(Http::recorded(fn (Request $request) => str_contains($request->url(), 'api.openai.com')
            && ! str_contains($request->data()['messages'][0]['content'], 'Este es un turno comercial')));

        expect(gfLast())->toStartWith('CONTENIDO-DE-LA-RESPUESTA-GRATUITA')
            ->and($others)->not->toBeEmpty()
            ->and($others->contains(fn ($pair) => isset($pair[0]->data()['response_format'])))->toBeFalse();
    });

    it('asks once more without the schema when the model refuses it, and still answers', function () {
        Log::spy();
        $this->schemaRefused = true;
        $this->turns = [gfJson('Te acompaño por WhatsApp durante tu suscripción.', question: 'comercial')];

        gfSay('¿Esto cómo funciona?');

        $calls = ($this->openAi)('Este es un turno comercial');

        expect(gfLast())->toStartWith('Te acompaño por WhatsApp durante tu suscripción.')
            ->and($calls)->toHaveCount(2)
            ->and(isset($calls[0]['response_format']))->toBeTrue()
            ->and(isset($calls[1]['response_format']))->toBeFalse();
        Log::shouldHaveReceived('warning')->with('AI_SCHEMA_REFUSED', Mockery::on(fn (array $context) => $context['schema'] === 'commercial_turn'));
    });
});

describe('what a guided funnel reads as a courtesy', function () {
    it('takes whole messages of greetings and courtesies, nothing more', function (string $message, bool $courtesy) {
        expect(GuidedFunnel::isCourtesy($message))->toBe($courtesy);
    })->with([
        ['Hola', true], ['¡Hola, buenas noches!', true], ['Gracias', true], ['Muchas gracias 🙏', true], ['Buenos días, un gusto', true],
        ['Hola, quiero conocer el coach', false], ['Gracias, ¿y cuánto dura?', false], ['', false], ['Bueno', false], ['Ok', false],
    ]);

    it('lets a courtesy reply be short and harmless, with no invitation to the plans', function () {
        $claims = app(CommercialClaims::class);

        expect($claims->courtesyReplyViolation('¡Con gusto! Aquí estoy.', 'Asesor'))->toBeNull()
            ->and($claims->limitReplyViolation('¡Con gusto! Aquí estoy.', 'Asesor'))->not->toBeNull()
            ->and($claims->courtesyReplyViolation("Prueba esto:\n- Míralo a los ojos", 'Asesor'))->not->toBeNull()
            ->and($claims->courtesyReplyViolation('El plan semanal cuesta $9.900.', 'Asesor'))->not->toBeNull()
            ->and($claims->courtesyReplyViolation(str_repeat('Con gusto te acompaño. ', 25), 'Asesor'))->toBe('too_long');
    });
});

describe('the claims check', function () {
    beforeEach(function () {
        gfPublish(free: 1);
        Log::spy();
    });

    it('checks a commercial wording against the approved facts, with the funnel\'s instruction apart', function () {
        gfSource('Qué incluye', 'TEXTO-COMERCIAL-APROBADO', KnowledgeSource::PURPOSE_COMMERCIAL);
        gfSource('Cómo funciona', 'TEXTO-LITERAL-APROBADO', KnowledgeSource::PURPOSE_COMMERCIAL, KnowledgeSource::DELIVERY_VERBATIM);
        $this->needsKnowledge = false;
        $this->turns = [gfJson('Te acompaño por WhatsApp durante tu suscripción.', question: 'comercial')];

        gfSay('Es caro');

        $prompt = $this->verifierPrompts[0];

        expect($this->verifierPrompts)->toHaveCount(1)
            ->and($prompt)->toContain('### HECHOS APROBADOS')->toContain('DESCRIPCION-PRODUCTO')->toContain('FAQ-PRODUCTO')
            ->toContain('1. Semanal: $9.900 COP por 7 días')->toContain('TEXTO-COMERCIAL-APROBADO')->toContain('TEXTO-LITERAL-APROBADO')
            ->and(Str::before($prompt, '### INSTRUCCIÓN COMERCIAL'))->not->toContain('INSTRUCCION-DEL-ADMINISTRADOR')
            ->and(Str::after($prompt, '### INSTRUCCIÓN COMERCIAL'))->toContain('INSTRUCCION-DEL-ADMINISTRADOR')
            ->and(collect($this->chats)->last()[1]['content'])->toBe('Te acompaño por WhatsApp durante tu suscripción.');
    });

    it('logs what it finds, as counts only, and never changes the reply', function (string $reply, string $verdict, string $expected, int $claims, int $unsupported) {
        $this->turns = [gfJson($reply, question: 'comercial')];
        $this->verdicts = [$verdict];

        gfSay('¿Cómo funciona?');

        expect(gfLast())->toStartWith($reply);
        Log::shouldHaveReceived('info')->with('FUNNEL_CLAIMS_CHECK', Mockery::on(fn (array $context) => $context['mode'] === 'observe' && $context['verdict'] === $expected && $context['claims'] === $claims && $context['unsupported'] === $unsupported
            && array_keys($context) === ['store_id', 'product_id', 'mode', 'verdict', 'claims', 'unsupported']));
    })->with([
        'respaldada' => ['Te acompaño por WhatsApp.', json_encode(['claims' => [['text' => 'Te acompaño por WhatsApp', 'supported' => true]]]), 'supported', 1, 0],
        'sin respaldo' => ['Incluye sesiones en vivo cada semana.', json_encode(['claims' => [['text' => 'Incluye sesiones en vivo cada semana', 'supported' => false]]]), 'unsupported', 1, 1],
        'una condición sin documentar' => ['Puedes pausar la suscripción cuando quieras.', json_encode(['claims' => [['text' => 'Puedes pausar la suscripción cuando quieras', 'supported' => false]]]), 'unsupported', 1, 1],
        'una indicación estratégica, sin hechos' => ['Lo importante es que des el primer paso con el plan semanal.', json_encode(['claims' => []]), 'no_claims', 0, 0],
        'persuasión legítima' => ['Te entiendo: invertir en ti siempre vale más de lo que parece.', json_encode(['claims' => []]), 'no_claims', 0, 0],
    ]);

    it('checks a message once: its retry goes straight to the send', function () {
        $this->turns = [gfJson('Te acompaño por WhatsApp.', question: 'comercial')];
        $this->whatsAppDown = true;

        expect(fn () => ProcessWhatsAppMessage::dispatchSync($this->store->fresh(), GF_PHONE, '¿Cómo funciona?', 'wamid.verificado', 'text', null))->toThrow(RuntimeException::class);

        $this->whatsAppDown = false;

        ProcessWhatsAppMessage::dispatchSync($this->store->fresh(), GF_PHONE, '¿Cómo funciona?', 'wamid.verificado', 'text', null);

        expect($this->verifierPrompts)->toHaveCount(1)
            ->and($this->turnPrompts)->toHaveCount(1)
            ->and(gfLast())->toStartWith('Te acompaño por WhatsApp.');
    });

    it('checks before the reply goes out, so nothing it does can follow the send', function () {
        $this->turns = [gfJson('Te acompaño por WhatsApp.', question: 'comercial')];

        gfSay('¿Cómo funciona?');

        $order = Http::recorded()->map(fn ($pair) => str_contains($pair[0]->url(), 'api.openai.com')
            ? (str_contains($pair[0]->data()['messages'][0]['content'], 'Eres un verificador') ? 'verificador' : 'modelo')
            : 'whatsapp')->values()->all();

        expect($order)->toBe(['modelo', 'verificador', 'whatsapp']);
    });

    it('sends the reply once, as it was, when the check itself breaks unexpectedly', function () {
        $advisor = Mockery::mock(CommercialAdvisor::class, [app(KnowledgeRetriever::class), app(KnowledgePlanner::class), app(PromptAssembler::class), app(CommercialClaims::class), app(PersonFacts::class)])->makePartial();
        $advisor->shouldReceive('checkClaims')->andThrow(new RuntimeException('boom'));
        app()->instance(CommercialAdvisor::class, $advisor);
        $this->turns = [gfJson('Te acompaño por WhatsApp.', question: 'comercial')];

        gfSay('¿Cómo funciona?');

        expect(sentWhatsAppTexts())->toHaveCount(1)->and(gfLast())->toStartWith('Te acompaño por WhatsApp.');
        Log::shouldHaveReceived('warning')->with('FUNNEL_CLAIMS_CHECK_FAILED', Mockery::on(fn (array $context) => $context['error'] === RuntimeException::class));
    });

    it('only logs that it could not check, and the reply goes out as it was', function (bool $down) {
        $this->turns = [gfJson('Te acompaño por WhatsApp.', question: 'comercial')];
        $down ? $this->verifierDown = true : $this->verdicts = ['no es un JSON'];

        gfSay('¿Cómo funciona?');

        expect(gfLast())->toStartWith('Te acompaño por WhatsApp.');
        Log::shouldHaveReceived('warning')->with('FUNNEL_CLAIMS_CHECK_FAILED', Mockery::any());
        Log::shouldNotHaveReceived('info', ['FUNNEL_CLAIMS_CHECK', Mockery::any()]);
    })->with(['caído' => true, 'respuesta inválida' => false]);

    it('checks nothing that is not the funnel\'s own commercial wording', function (Closure $arrange) {
        $arrange($this);

        gfSay('Una pregunta');

        expect($this->verifierPrompts)->toBe([]);
    })->with([
        'una respuesta gratuita' => [fn ($test) => $test->replies = [gfJson('CONTENIDO')]],
        'un texto literal' => [function ($test) {
            gfSource('Qué incluye', 'TEXTO-LITERAL-APROBADO', KnowledgeSource::PURPOSE_COMMERCIAL, KnowledgeSource::DELIVERY_VERBATIM);
            $test->sections = ['Qué incluye'];
            $test->turns = [gfJson('Te acompaño.', question: 'comercial')];
        }],
        'una respuesta sin clasificar' => [fn ($test) => $test->turns = [gfJson('Entiendo. Eso se trabaja con el servicio completo. Si quieres, conoce los planes.')]],
        'una respuesta descartada' => [fn ($test) => $test->turns = [gfJson('Hoy te sale en $5.000.', question: 'comercial')]],
    ]);
});

describe('what the model receives after the limit', function () {
    beforeEach(function () {
        gfPublish(free: 1);
        $this->replies = [gfJson('CONSEJO-ANTERIOR-DE-MAX')];
        gfSay('PREGUNTA-ANTERIOR');
        $this->chats = [];
    });

    it('is the instruction, the product, the conversation so far and the current message, with no material', function () {
        gfSource('Exclusivo', 'CONTENIDO-EXCLUSIVO', KnowledgeSource::PURPOSE_KNOWLEDGE);
        gfSource('Muestra', 'CONTENIDO-MUESTRA', KnowledgeSource::PURPOSE_SAMPLE);

        gfSay('MENSAJE-ACTUAL');

        $chat = $this->chats[0];

        expect($this->chats)->toHaveCount(1)
            ->and(array_column($chat, 'role'))->toBe(['system', 'user', 'assistant', 'user'])
            ->and($chat[1]['content'])->toBe('PREGUNTA-ANTERIOR')
            ->and($chat[2]['content'])->toStartWith('CONSEJO-ANTERIOR-DE-MAX')
            ->and(end($chat)['content'])->toBe('MENSAJE-ACTUAL')
            ->and($chat[0]['content'])
            ->toContain('INSTRUCCION-DEL-ADMINISTRADOR')->toContain('DESCRIPCION-PRODUCTO')->toContain('FAQ-PRODUCTO')->toContain('Este es un turno comercial')
            ->not->toContain('CONTENIDO-EXCLUSIVO')->not->toContain('CONTENIDO-MUESTRA');
    });

    it('leaves the stored history as it was', function () {
        gfSay('MENSAJE-ACTUAL');

        expect(WhatsAppMessage::where('content', 'like', 'CONSEJO-ANTERIOR-DE-MAX%')->exists())->toBeTrue()
            ->and(WhatsAppMessage::where('content', 'PREGUNTA-ANTERIOR')->exists())->toBeTrue();
    });

    it('keeps the history for the free replies', function () {
        gfPublish(free: 3);
        $this->chats = [];

        gfSay('OTRA-PREGUNTA');

        expect(collect($this->chats[0])->pluck('content')->implode(' '))->toContain('CONSEJO-ANTERIOR-DE-MAX');
    });
});

describe('the purchase phrases after the limit', function () {
    beforeEach(function () {
        gfPublish(free: 1);
        gfSay('Uno');
        $this->calls = count($this->prompts);
    });

    it('recognizes each of them without the model, and without spending a read', function (string $message) {
        gfSay($message);

        expect(gfLast())->toContain('¿Qué plan de «Asesor» quieres?')
            ->and($this->prompts)->toHaveCount($this->calls)
            ->and(gfReads())->toBe(0)
            ->and(Payment::count())->toBe(0);
    })->with(['Quiero entrar', 'Estoy listo para comenzar', 'Estoy listo para empezar', 'Quiero tener acceso', 'Dame acceso', 'Quiero acceder', 'Hola, quiero entrar, gracias', 'QUIERO TENER ACCESO!']);

    it('does not take a phrase it merely contains, nor its negation, as a purchase', function (string $message) {
        expect(GuidedFunnel::commercialAction($message))->toBeNull();

        gfSay($message);

        expect(Payment::count())->toBe(0)
            ->and(gfLast())->not->toContain('¿Qué plan de «Asesor» quieres?')
            ->and(gfReads())->toBe(1);
    })->with([
        'No quiero entrar', 'Aún no quiero entrar', 'No estoy listo para comenzar', 'No estoy listo para empezar', 'No quiero tener acceso', 'No me des acceso', 'No quiero acceder',
        'Quiero entrar a hablarle', 'Quiero entrar en confianza', 'Estoy listo para empezar a hablarle', 'Quiero tener acceso a su corazón', 'Quiero acceder a sus redes', 'Listo', 'Estoy listo',
    ]);
});

describe('confirming before a payment', function () {
    it('asks first when there are several plans, and creates the payment only on the choice', function () {
        gfPublish(free: 1);
        gfSay('Uno');

        gfSay('Quiero entrar');

        expect(Payment::count())->toBe(0)->and(gfLast())->toContain('1. Semanal')->toContain('2. Mensual');

        gfSay('2');

        expect(Payment::first()->plan->name)->toBe('Mensual')->and(gfLast())->toContain('INSTRUCCIONES-PAGO');
    });

    describe('with only one plan', function () {
        beforeEach(function () {
            Plan::where('product_id', $this->product->id)->where('name', 'Mensual')->update(['is_active' => false]);
            gfPublish(free: 1);
            gfSay('Uno');
        });

        it('shows the plan and asks, for a phrase, creating nothing yet', function () {
            gfSay('Quiero entrar');

            expect(Payment::count())->toBe(0)
                ->and(gfLast())->toContain('1. Semanal: $9.900 COP por 7 días')->toContain('Selecciona el plan que prefieras usando uno de los botones de abajo. 👇');
        });

        it('creates the payment once confirmed with its number', function () {
            gfSay('Me animo');
            gfSay('1');

            expect(Payment::first()->plan->name)->toBe('Semanal');
        });

        it('creates the payment once confirmed with its name', function () {
            gfSay('Me animo');
            gfSay('Semanal');

            expect(Payment::first()->plan->name)->toBe('Semanal');
        });

        it('creates nothing when the customer does not confirm', function () {
            gfSay('Me animo');
            gfSay('Mejor no, gracias');

            expect(Payment::count())->toBe(0)->and(Conversation::first()->pending_state)->toBeNull();
        });

        it('also asks when the model reads the purchase', function () {
            $this->turns = [gfJson('', GuidedFunnel::INTENT_BUY, 0.95, 'comercial')];

            gfSay('Va, me quedo con esto');

            expect(Payment::count())->toBe(0)->and(gfLast())->toContain('Selecciona el plan que prefieras usando uno de los botones de abajo. 👇');
        });

        it('takes the plan named by the customer as their choice', function () {
            gfSay('Quiero el semanal');

            expect(Payment::first()->plan->name)->toBe('Semanal');
        });
    });
});

describe('the read counter', function () {
    beforeEach(function () {
        $this->funnel = gfPublish();
        $this->conversation = Conversation::create(['store_id' => $this->store->id, 'customer_phone' => GF_PHONE, 'last_session_at' => now()]);
        $this->states = app(FunnelStates::class);
    });

    it('creates the state when there is none, and counts per conversation and product', function () {
        $other = Conversation::create(['store_id' => $this->store->id, 'customer_phone' => '573000000000', 'last_session_at' => now()]);

        $this->states->takeLimitRead($this->conversation, $this->product, $this->funnel, GuidedFunnel::STAGE, 'w1', 5);
        $this->states->takeLimitRead($this->conversation, $this->product, $this->funnel, GuidedFunnel::STAGE, 'w2', 5);
        $this->states->takeLimitRead($other, $this->product, $this->funnel, GuidedFunnel::STAGE, 'w3', 5);

        expect($this->states->find($this->conversation, $this->product)->context['limit_reads'])->toBe(2)
            ->and($this->states->find($other, $this->product)->context['limit_reads'])->toBe(1);
    });

    it('never takes more than the maximum, and counts each message without an identifier', function () {
        $taken = array_map(fn () => $this->states->takeLimitRead($this->conversation, $this->product, $this->funnel, GuidedFunnel::STAGE, null, 2), range(1, 4));

        expect($taken)->toBe([true, true, false, false])
            ->and($this->states->find($this->conversation, $this->product)->context['limit_reads'])->toBe(2);
    });

    it('survives a save without context, and a save with one replaces it', function () {
        $this->states->takeLimitRead($this->conversation, $this->product, $this->funnel, GuidedFunnel::STAGE, 'w1', 5);

        $this->states->save($this->conversation, $this->product, $this->funnel, GuidedFunnel::STAGE, 3);

        $state = $this->states->find($this->conversation, $this->product);

        expect($state->context['limit_reads'])->toBe(1)->and($state->stage_interactions)->toBe(3);

        $this->states->save($this->conversation, $this->product, $this->funnel, GuidedFunnel::STAGE, 3, ['otro' => true]);

        expect($this->states->find($this->conversation, $this->product)->context)->toBe(['otro' => true]);
    });

    it('survives a new published version', function () {
        $this->states->takeLimitRead($this->conversation, $this->product, $this->funnel, GuidedFunnel::STAGE, 'w1', 5);

        $next = gfPublish('OTRA');
        $this->states->save($this->conversation, $this->product, $next, GuidedFunnel::STAGE);

        expect(ConversationFunnelState::where('conversation_id', $this->conversation->id)->first()->context['limit_reads'])->toBe(1);
    });
});

describe('a reply after the limit, for any product', function () {
    it('takes a natural reference to the product\'s whole name as a mention of the service', function (string $name, string $reference) {
        $reply = "Te entiendo. Eso se trabaja en {$reference}. Si quieres, conoce los planes.";

        expect(app(CommercialClaims::class)->limitReplyViolation($reply, $name))->toBeNull();
    })->with([
        'MAX' => ['Secretos de la Seducción', 'Secretos de la Seducción'],
        'MAX sin tildes ni mayúsculas' => ['Secretos de la Seducción', 'secretos de la seduccion'],
        'MAX entre comillas' => ['Secretos de la Seducción', '«Secretos de la Seducción»'],
        'idiomas' => ['Curso de inglés', 'el Curso de inglés'],
        'nutrición' => ['Asesoría Nutricional', 'la Asesoría Nutricional'],
        'nombre con una conjunción' => ['Salud y Bienestar', 'Salud y Bienestar'],
        'nombre con un término de consejo' => ['Prueba Fácil', 'Prueba Fácil'],
    ]);

    it('takes an alias as well', function () {
        $reply = 'Te entiendo. Eso se trabaja en el Método Max. Si quieres, conoce los planes.';

        expect(app(CommercialClaims::class)->limitReplyViolation($reply, 'Secretos de la Seducción', ['Método Max']))->toBeNull();
    });

    it('rejects the same sentences for every sector when they mention no service', function (string $name, string $reference) {
        $reply = "Te entiendo. Eso se trabaja en {$reference}. Si quieres, conoce los planes.";

        expect(app(CommercialClaims::class)->limitReplyViolation($reply, 'Otro Producto Distinto'))->toBe('not_commercial');
    })->with([
        ['Secretos de la Seducción', 'Secretos de la Seducción'],
        ['Curso de inglés', 'el Curso de inglés'],
        ['Asesoría Nutricional', 'la Asesoría Nutricional'],
    ]);

    it('does not take one word of the name, or a word that contains it, as the product', function (string $name, string $reply) {
        expect(app(CommercialClaims::class)->limitReplyViolation($reply, $name))->toBe('not_commercial');
    })->with([
        'una palabra del nombre' => ['Secretos de la Seducción', 'Hay secretos en cómo te mira. Conoce los planes.'],
        'otra palabra' => ['Secretos de la Seducción', 'La seducción empieza con calma al hablar. Conoce los planes.'],
        'parte del curso' => ['Curso de inglés', 'El inglés mejora al leer en voz alta. Conoce los planes.'],
        'parte de la asesoría' => ['Asesoría Nutricional', 'Lo nutricional depende de comer despacio. Conoce los planes.'],
        'nombre dentro de otra palabra' => ['Mente', 'Lo vemos mentalmente al leer despacio. Conoce los planes.'],
        'nombre demasiado corto' => ['Yo', 'Yo sostengo la mirada al hablar. Conoce los planes.'],
    ]);

    it('still rejects advice next to the product\'s name', function (string $name, string $reply) {
        expect(app(CommercialClaims::class)->limitReplyViolation($reply, $name))->not->toBeNull();
    })->with([
        ['Secretos de la Seducción', 'Sostén la mirada y conoce Secretos de la Seducción. Mira los planes.'],
        ['Curso de inglés', 'Lee en voz alta, luego entra al Curso de inglés. Conoce los planes.'],
        ['Asesoría Nutricional', 'Con la Asesoría Nutricional, come despacio y sin pantallas. Conoce los planes.'],
        ['Curso de inglés', 'Empieza con «how are you» en el Curso de inglés. Conoce los planes.'],
    ]);

    it('recognizes the product\'s name and aliases in a real conversation, whatever its sector', function (string $name, string $reference) {
        // The strict reply of before, sent each time: the contextual close goes once per interval (its own tests).
        config(['botinker.sales_funnels.guided.closing_reply.enabled' => false]);
        $this->product->update(['name' => $name, 'aliases' => ['Método de prueba']]);
        gfPublish(free: 1);
        gfSay('Uno');

        foreach ([$reference, 'el Método de prueba'] as $mention) {
            $reply = "Te entiendo. Eso se trabaja en {$mention}. Si quieres, conoce los planes.";
            $this->turns = [gfJson($reply)];

            gfSay('¿Cómo lo hago?');

            expect(gfLast())->toBe($reply);
        }
    })->with([
        ['Secretos de la Seducción', 'Secretos de la Seducción'],
        ['Curso de inglés', 'el Curso de inglés'],
        ['Asesoría Nutricional', 'la Asesoría Nutricional'],
    ]);

    it('keeps the global configuration free of any product or sector', function () {
        $texts = collect(config('botinker.sales_funnels'))->flatten()->merge(config('botinker.free_interactions.strategy'))->implode(' ');

        foreach (['coach', 'MAX', 'señales', 'senales', 'seducci', 'mujer', 'citas', 'acompañamiento', 'acompanamiento', 'ligar', 'coqueteo', 'nutri', 'inglés', 'tarot'] as $term) {
            expect(str_contains($texts, $term))->toBeFalse("La configuración global menciona «{$term}».");
        }
    });
});

describe('what the funnel\'s own wording may say', function () {
    it('lets selling through, however it is worded', function (string $reply) {
        expect(app(CommercialClaims::class)->commercialWordingViolation($reply, 'Asesor'))->toBeNull();
    })->with([
        'prueba el plan' => 'Prueba el plan semanal y me cuentas cómo te va.',
        'empieza por el plan' => 'Empieza por el plan semanal: es la forma más sencilla de arrancar.',
        'te recomiendo la suscripción' => 'Te recomiendo la suscripción mensual si quieres más tiempo.',
        'una objeción' => 'Te entiendo. Con el plan semanal puedes empezar con poco.',
        'lo que hace el servicio' => 'Con el servicio te acompaño en tus conversaciones y resuelvo tus dudas.',
        'el nombre del producto' => 'Usa Asesor durante una semana y decide después.',
        'una garantía negada' => 'No te garantizo resultados: depende de cada persona.',
        'sin reembolso' => 'Es un servicio sin reembolso, por eso puedes empezar con el plan semanal.',
        'no hay reembolso' => 'No hay reembolso, pero puedes empezar con el plan semanal.',
    ]);

    it('stops content of the service and what only the approved material may promise', function (string $reply, string $reason) {
        expect(app(CommercialClaims::class)->commercialWordingViolation($reply, 'Asesor'))->toBe($reason);
    })->with([
        'una técnica' => ['Prueba mirarla a los ojos cuando le hables.', 'instruction'],
        'un paso' => ['Primero elige un lugar tranquilo para la cita.', 'instruction'],
        'una técnica junto a una venta' => ['Prueba mirarla a los ojos y activa tu plan.', 'instruction'],
        'una lista' => ["- Confianza\n- Carisma", 'list'],
        'un ejemplo entre comillas' => ['La frase «me encanta tu estilo» funciona muy bien.', 'quoted_example'],
        'una garantía' => ['Tiene garantía de 30 días.', 'unbacked_claim'],
        'un reembolso condicionado' => ['Si no te funciona, te devolvemos el dinero.', 'unbacked_claim'],
        'un reembolso condicionado, sin coma' => ['Si no te funciona te devolvemos el dinero.', 'unbacked_claim'],
        'una garantía en primera persona' => ['Te garantizo que funciona.', 'unbacked_claim'],
        'un importe' => ['Hoy te sale en $5.000.', 'currency'],
    ]);
});

describe('what a reply after the limit may say', function () {
    it('accepts a commercial reply', function (string $reply) {
        expect(app(CommercialClaims::class)->limitReplyViolation($reply, 'Asesor'))->toBeNull();
    })->with([
        'Entiendo que eso te preocupa. Con el servicio completo lo trabajamos sobre tu caso. Cuando quieras, conoce los planes.',
        'Entiendo que eso te preocupa. Trabajarlo con el servicio completo te da orientación sobre tu caso. Si quieres, conoce los planes.',
        'Qué buena pregunta, y es muy común. Eso lo vemos en el servicio completo. Cuando quieras, te muestro las opciones.',
        'Te entiendo. En el servicio lo trabajamos según tu situación, sin presiones. ¿Te gustaría conocer los planes?',
        'Es justo lo que trabajamos con la suscripción, si te interesa.',
    ]);

    it('rejects each educational reply found in the review, without forbidden words', function (string $reply) {
        expect(app(CommercialClaims::class)->limitReplyViolation($reply, 'Secretos de la Seducción'))->toBe('not_commercial');
    })->with([
        'Las señales suelen estar en su mirada y en cómo busca tu cercanía. Con la suscripción lo trabajamos a fondo.',
        'Cuando ella se inclina hacia ti y te sostiene la mirada, normalmente hay interés. Conoce los planes.',
        'Basta con comentar algo del lugar y sonreír con calma. Activa tu suscripción y seguimos.',
        'Respira hondo, sonríe y saluda con voz tranquila. Conoce los planes.',
        'Conviene mostrar interés sin agobiar y dejarle espacio para responder. Conoce los planes.',
        'Como te comenté, comenta algo del entorno y respeta su espacio. Con el servicio vamos más lejos; conoce los planes.',
        'Una buena pregunta abierta sobre su semana abre la conversación. Conoce los planes.',
    ]);

    it('does not take a mention of the subscription as making a piece of advice commercial', function (string $reply) {
        expect(app(CommercialClaims::class)->limitReplyViolation($reply, 'Asesor'))->not->toBeNull();
    })->with([
        'Sostén la mirada y activa tu suscripción.',
        'Para atraerla, conoce los planes.',
        'Sonríe, saluda con calma, conoce los planes.',
        'Con la suscripción aprenderás a sostener la mirada.',
        'Entiendo, sostén la mirada. Conoce los planes.',
        'Conoce los planes. Entiendo que las señales están en su mirada y su voz.',
        'Conoce los planes, pero mientras tanto mírala a los ojos.',
    ]);

    it('discards commercial replies in case of doubt: a known cost of being strict', function (string $reply) {
        expect(app(CommercialClaims::class)->limitReplyViolation($reply, 'Asesor'))->toBe('not_commercial');
    })->with([
        'Saber leer esas señales cambia mucho tus conversaciones. Es justo lo que trabajamos con la suscripción.',
        'Te entiendo: dar ese paso cuesta. Conoce los planes.',
    ]);

    it('rejects what reads as service, or as no invitation', function (string $reply, string $reason) {
        expect(app(CommercialClaims::class)->limitReplyViolation($reply, 'Asesor'))->toBe($reason);
    })->with([
        'una técnica' => ['Intenta sostener la mirada unos segundos. Conoce los planes.', 'instruction'],
        'sin tildes ni mayúsculas distintas' => ['TE RECOMIENDO escucharla más. Conoce los planes.', 'instruction'],
        'un ejemplo' => ['Por ejemplo, pregúntale por su día. Conoce los planes.', 'instruction'],
        'una frase de ejemplo' => ['Empieza con «qué tal tu día». Conoce los planes.', 'quoted_example'],
        'una lista' => ["Conoce los planes:\n1. Sonríe\n2. Saluda", 'list'],
        'viñetas' => ["Conoce los planes:\n- Sonríe", 'list'],
        'demasiado larga' => [str_repeat('Conoce los planes con calma ', 20), 'too_long'],
        'muchas frases' => ['Bien. Claro. Sí. Entiendo. Conoce los planes.', 'too_many_sentences'],
        'sin invitación' => ['Entiendo tu situación y es muy común.', 'no_invitation'],
        'sin relación con el servicio' => ['Hoy hace buen clima. Conoce los planes.', 'not_commercial'],
        'un importe' => ['Conoce los planes desde $5.000 al mes.', 'currency'],
        'un acceso regalado' => ['Ya tienes acceso a los planes.', 'access'],
    ]);
});

describe('the order of the history', function () {
    it('gives the model each turn in the order it happened, when messages share their created_at', function () {
        gfPublish();
        gfSeedTurns('tema');

        gfSay('Si');

        expect(gfHistorySentWith('M2'))->toBe(['user:M1', 'assistant:R1', 'user:M2', 'assistant:R2', 'user:Si']);
    });

    it('keeps only the latest messages when they share their created_at', function () {
        gfPublish();

        foreach (range(1, 6) as $turn) {
            gfSeedTurn('tema', "M{$turn}", "R{$turn}");
        }

        gfSay('Si');

        expect(gfHistorySentWith('M6'))->toBe(['user:M2', 'assistant:R2', 'user:M3', 'assistant:R3', 'user:M4', 'assistant:R4', 'user:M5', 'assistant:R5', 'user:M6', 'assistant:R6', 'user:Si']);
    });

    it('gives the sales flow its history in order too', function () {
        $this->store = makeStore(['conversation_mode' => Store::MODE_SALES]);
        gfSeedTurns(null);

        gfSay('Si');

        expect(gfHistorySentWith('M2'))->toBe(['user:M1', 'assistant:R1', 'user:M2', 'assistant:R2', 'user:Si']);
    });

    it('gives the lead extraction its history in order too', function () {
        gfSeedTurns(null);

        $job = new ProcessWhatsAppMessage($this->store, GF_PHONE, 'Si', 'wamid.in.1', 'text');
        $history = (new ReflectionMethod($job, 'getRawHistory'))->invoke($job);

        expect(array_map(fn (array $message) => "{$message['role']}:{$message['content']}", array_values($history)))
            ->toBe(['user:M1', 'assistant:R1', 'user:M2', 'assistant:R2']);
    });
});

describe('what stays as it was', function () {
    it('keeps the commercial instructions and the limits of a funnel made of stages', function () {
        $this->versions->publish($this->product, [
            'name' => 'Por etapas', 'goal' => 'G',
            'free_replies' => ['limit' => 1],
            'stages' => [['key' => 'a', 'type' => 'ai', 'goal' => 'g', 'instructions' => 'INSTRUCCION-ETAPA', 'uses_free_replies' => true]],
        ]);
        $this->replies = ['Respuesta libre'];

        gfSay('¿De qué se trata?');

        expect(gfLastPrompt())
            ->toContain('COMERCIAL-ANTIGUA')
            ->toContain('No menciones precios, planes, descuentos')
            ->not->toContain('PLANES DISPONIBLES');

        $calls = count($this->prompts);
        gfSay('Cuéntame más');

        expect($this->prompts)->toHaveCount($calls)
            ->and(gfLast())->toContain('necesitas una suscripción');
    });

    it('leaves a product without a funnel in its free replies and its offer', function () {
        gfSay('¿De qué se trata?');

        expect(SalesFunnel::where('product_id', $this->product->id)->count())->toBe(0)
            ->and(Payment::count())->toBe(0)
            ->and(collect($this->prompts)->implode("\n"))->not->toContain('PROCESO COMERCIAL');

        gfSay('¿Cuánto cuesta?');

        expect(gfLast())->toContain('$9.900');
    });
});

function gfPointer(): string
{
    return 'Escribe «planes» para ver los precios o «pagar» para suscribirte.';
}

/** @return array<string, mixed>|null the contextual close the state keeps */
function gfClosing(): ?array
{
    return ConversationFunnelState::where('product_id', test()->product->id)->first()?->context['closing'] ?? null;
}

function gfConversation(): Conversation
{
    return Conversation::firstOrCreate(['store_id' => test()->store->id, 'customer_phone' => GF_PHONE]);
}

describe('the contextual close after the free replies', function () {
    beforeEach(function () {
        gfPublish(free: 1);
        // The only free reply, with the plans after it.
        gfSay('Uno');

        $this->close = 'Entiendo que quieres sentirte seguro al iniciar conversaciones. Esa habilidad se trabaja en el servicio completo, adaptado a tu situación. Si quieres seguir, puedes activar tu suscripción.';
        $this->otherClose = 'Te entiendo, quieres ganar confianza. El servicio completo trabaja justo eso contigo. Si te interesa, revisa los planes.';
        $this->fallback = 'Entiendo, eso es justo lo que el servicio te ayuda a trabajar.';
    });

    it('asks for the close in place of the strict reply once the free replies are over', function () {
        expect(gfLastTurnPrompt())->toContain('explica en una frase que eso se trabaja con el servicio completo')->not->toContain('El cliente ya usó sus respuestas gratuitas');

        $this->turns = [gfJson($this->close, question: 'contenido')];
        gfSay('¿Cómo inicio una conversación?');

        expect(gfLastTurnPrompt())->toContain('El cliente ya usó sus respuestas gratuitas')->toContain('y 400 caracteres')
            ->not->toContain('explica en una frase que eso se trabaja con el servicio completo')->not->toContain(':characters')->not->toContain(':content_reply');
    });

    it('sends one contextual close per interval, though every message starts another episode', function () {
        Log::spy();
        $this->turns = [gfJson($this->close, question: 'contenido', fallback: $this->fallback)];
        gfSay('¿Cómo inicio una conversación?');

        // The plans went with the last free reply: the close goes alone.
        expect(gfLast())->toBe($this->close)->and(gfClosing()['status'])->toBe(FunnelStates::CLOSING_SENT);

        gfConversation()->update(['last_session_at' => now()]);
        $this->turns = [gfJson($this->otherClose, fallback: $this->fallback)];
        gfSay('¿Y si me pongo nervioso?');

        expect(gfLast())->toBe($this->fallback."\n\n".gfPointer());
        Log::shouldHaveReceived('info')->with('FUNNEL_LIMIT_READ', Mockery::on(fn (array $context) => $context['closing'] === 'model' && $context['closing_discarded'] === null));
        Log::shouldHaveReceived('info')->with('FUNNEL_LIMIT_READ', Mockery::on(fn (array $context) => $context['closing'] === 'fallback' && $context['closing_discarded'] === 'interval'
            && ! in_array($this->otherClose, $context, true) && ! in_array(GF_PHONE, $context, true)));
    });

    it('takes one reservation for two messages at once, and keeps it for the message that took it', function () {
        $states = app(FunnelStates::class);
        $claim = fn (string $wamid) => $states->claimClosing(gfConversation(), $this->product, $this->product->activeFunnel(), GuidedFunnel::STAGE, $wamid, 24);

        expect($claim('wamid.a'))->toBe(FunnelStates::CLOSING_CLAIMED)
            ->and($claim('wamid.b'))->toBeNull()
            ->and($claim('wamid.a'))->toBe(FunnelStates::CLOSING_CLAIMED)
            ->and(gfClosing()['wamid'])->toBe('wamid.a');
    });

    it('sends the same close once when its message is retried, with no second reservation nor model call', function () {
        $this->turns = [gfJson($this->close, question: 'contenido')];
        $this->whatsAppDown = true;

        expect(fn () => ProcessWhatsAppMessage::dispatchSync($this->store->fresh(), GF_PHONE, '¿Cómo inicio una conversación?', 'wamid.cierre', 'text', null))->toThrow(RuntimeException::class)
            ->and(gfClosing())->toMatchArray(['wamid' => 'wamid.cierre', 'status' => 'released']);

        $this->whatsAppDown = false;
        ProcessWhatsAppMessage::dispatchSync($this->store->fresh(), GF_PHONE, '¿Cómo inicio una conversación?', 'wamid.cierre', 'text', null);

        expect($this->turnPrompts)->toHaveCount(2)
            ->and(gfLast())->toBe($this->close)
            ->and(WhatsAppMessage::where('role', 'assistant')->where('content', $this->close)->count())->toBe(1)
            ->and(gfClosing())->toMatchArray(['wamid' => 'wamid.cierre', 'status' => FunnelStates::CLOSING_SENT]);
    });

    it('gives the close back after a failed send: held for its retry, then free for any message', function () {
        $this->turns = [gfJson($this->close, question: 'contenido')];
        $this->whatsAppDown = true;

        expect(fn () => ProcessWhatsAppMessage::dispatchSync($this->store->fresh(), GF_PHONE, '¿Cómo inicio una conversación?', 'wamid.fallido', 'text', null))->toThrow(RuntimeException::class);

        // Another message within the retry window: no close, as the first may have gone out after all.
        $this->whatsAppDown = false;
        $this->turns = [gfJson($this->otherClose, fallback: $this->fallback)];
        gfSay('¿Y si me pongo nervioso?');

        expect(gfLast())->toBe($this->fallback."\n\n".gfPointer());

        // Once the window is over, the close is free again.
        $this->travel(11)->minutes();
        $this->turns = [gfJson($this->otherClose, question: 'contenido')];
        gfSay('¿Cómo sigo?');

        expect(gfLast())->toBe($this->otherClose)->and(gfClosing()['status'])->toBe(FunnelStates::CLOSING_SENT);
    });

    it('holds the interval from the moment WhatsApp took the close', function () {
        $this->turns = [gfJson($this->close, question: 'contenido')];
        $sentAt = now();

        gfSay('¿Cómo inicio una conversación?');

        expect(gfClosing())->toMatchArray(['status' => FunnelStates::CLOSING_SENT, 'until' => $sentAt->copy()->addHours(24)->toIso8601String()]);
    });

    it('never sends a second close when the Core cannot tell whether the first went out', function () {
        Log::spy();
        $this->turns = [gfJson($this->close, question: 'contenido')];
        $this->whatsAppAmbiguous = true;

        expect(fn () => ProcessWhatsAppMessage::dispatchSync($this->store->fresh(), GF_PHONE, '¿Cómo inicio una conversación?', 'wamid.ambiguo', 'text', null))->toThrow(RuntimeException::class);

        // Meanwhile another message: no close of its own.
        $this->whatsAppAmbiguous = false;
        $this->turns = [gfJson($this->otherClose, fallback: $this->fallback)];
        gfSay('¿Y si me pongo nervioso?');

        expect(gfLast())->not->toContain($this->otherClose);

        // The retry: the same close, from the answer already generated.
        ProcessWhatsAppMessage::dispatchSync($this->store->fresh(), GF_PHONE, '¿Cómo inicio una conversación?', 'wamid.ambiguo', 'text', null);

        expect(gfLast())->toBe($this->close)->and($this->turnPrompts)->toHaveCount(3);

        // Once WhatsApp took it, another delivery of the same message sends nothing again.
        $sent = count(sentWhatsAppTexts());
        $this->turns = [gfJson($this->close, question: 'contenido')];
        ProcessWhatsAppMessage::dispatchSync($this->store->fresh(), GF_PHONE, '¿Cómo inicio una conversación?', 'wamid.ambiguo', 'text', null);

        expect(sentWhatsAppTexts())->toHaveCount($sent)
            ->and(WhatsAppMessage::where('role', 'assistant')->where('content', $this->close)->count())->toBe(1);
        Log::shouldHaveReceived('info')->with('FUNNEL_CLOSING_ALREADY_SENT', Mockery::any());
    });

    it('allows another close once the interval is over', function () {
        $this->turns = [gfJson($this->close, question: 'contenido')];
        gfSay('¿Cómo inicio una conversación?');

        $this->travel(25)->hours();
        $this->turns = [gfJson($this->otherClose, question: 'contenido')];
        gfSay('¿Y si me pongo nervioso?');

        expect(gfLast())->toBe($this->otherClose."\n\n".gfPointer());
    });

    it('keeps the interval through a reset of the state, and forgets the state once it is over', function () {
        $this->turns = [gfJson($this->close, question: 'contenido')];
        gfSay('¿Cómo inicio una conversación?');

        expect(app(FunnelStates::class)->reset(gfConversation(), $this->product))->toBeTrue()
            ->and(ConversationFunnelState::sole()->context)->toHaveKey('closing')->not->toHaveKey('limit_reads')
            ->and(ConversationFunnelState::sole()->stage_interactions)->toBe(0);

        $this->turns = [gfJson($this->otherClose, fallback: $this->fallback)];
        gfSay('¿Y si me pongo nervioso?');

        expect(gfLast())->toBe($this->fallback."\n\n".gfPointer());

        $this->travel(25)->hours();

        expect(app(FunnelStates::class)->reset(gfConversation(), $this->product))->toBeTrue()
            ->and(ConversationFunnelState::count())->toBe(0);
    });

    it('sends no persuasive close after a refusal the model read, for an interval', function () {
        Log::spy();
        $this->turns = [gfJson('Entiendo, aquí estaré si quieres retomarlo.', question: 'rechazo')];
        gfSay('Ya me cansé de esto');

        $this->turns = [gfJson($this->close, question: 'contenido', fallback: $this->fallback)];
        gfSay('¿Cómo inicio una conversación?');

        expect(gfLast())->toBe($this->fallback."\n\n".gfPointer())->and(gfClosing())->toBeNull();
        Log::shouldHaveReceived('info')->with('FUNNEL_LIMIT_READ', Mockery::on(fn (array $context) => $context['closing_discarded'] === 'declined'));
    });

    it('sends no persuasive close to a refusal the model read as content', function () {
        $this->turns = [gfJson($this->close, question: 'contenido', fallback: $this->fallback)];
        gfSay('No me interesa');

        expect(gfLast())->not->toContain($this->close)
            ->and(gfLastTurnPrompt())->not->toContain('El cliente ya usó sus respuestas gratuitas')
            ->and(gfClosing())->toBeNull();
    });

    it('reminds of the payment open, with no new invitation to buy', function () {
        Log::spy();
        gfSay('Quiero el semanal');
        $this->turns = [gfJson($this->close, question: 'contenido', fallback: $this->fallback)];

        gfSay('¿Cómo inicio una conversación?');

        expect(gfLast())->toStartWith($this->fallback."\n\n")->toContain('sigue pendiente')->not->toContain($this->close)->not->toContain('$9.900')
            ->and(gfLastTurnPrompt())->not->toContain('El cliente ya usó sus respuestas gratuitas')
            ->and(Payment::count())->toBe(1)
            ->and(gfClosing())->toBeNull();
        Log::shouldHaveReceived('info')->with('FUNNEL_LIMIT_READ', Mockery::on(fn (array $context) => $context['closing_discarded'] === 'payment'));
    });

    it('closes a message the model did not classify only when the close passes', function (string $reply, bool $closes) {
        $this->turns = [gfJson($reply, fallback: $this->fallback)];

        gfSay('Mmm, ella');

        expect(gfLast())->toBe($closes ? $reply : $this->fallback."\n\n".gfPointer());
    })->with([
        'un cierre válido' => ['Te entiendo, quieres ganar confianza. El servicio completo trabaja justo eso contigo. Si te interesa, revisa los planes.', true],
        'una petición de más datos' => ['Cuéntame un poco más de tu situación.', false],
        'un consejo' => ['Una pregunta abierta suele romper el hielo. Con la suscripción verás más ideas.', false],
    ]);

    it('rejects content, pressure and farewells in a close', function (string $text, string $reason) {
        expect(app(CommercialClaims::class)->closingReplyViolation($text, 'Asesor'))->toBe($reason);
    })->with([
        'un consejo sin verbo de instrucción' => ['Para iniciar una conversación, lo ideal es comentar algo del lugar. Con la suscripción profundizas en esto.', 'unanchored'],
        'otro consejo' => ['Una buena forma de romper el hielo es una pregunta abierta. Con la suscripción verás más ideas.', 'unanchored'],
        'una instrucción' => ['Haz contacto visual antes de hablar. Activa tu suscripción para más.', 'instruction'],
        'una receta' => ['Mezcla avena con yogur la noche anterior. Con la suscripción tendrás más recetas.', 'unanchored'],
        'un ejemplo' => ['Puedes decir algo como hola, ¿qué lees? Conoce los planes del servicio.', 'instruction'],
        'un paso de otro sector' => ['Lo primero es identificar los riesgos de cada puesto. Con el acceso completo te guiamos.', 'instruction'],
        'un paso sin verbo de instrucción' => ['Identificar los riesgos de cada puesto ordena todo. Con el acceso completo te guiamos.', 'unanchored'],
        'presión' => ['Entiendo. Solo hoy puedes activar tu suscripción al servicio.', 'pressure'],
        'una despedida' => ['Entiendo. Si algún día quieres, activa tu suscripción al servicio. ¡Hasta pronto!', 'farewell'],
        'sin invitación' => ['Entiendo que quieres sentirte seguro. El servicio trabaja eso contigo.', 'no_invitation'],
        'más respuestas gratis' => ['Entiendo. Activa tu suscripción y tendrás otra respuesta gratis del servicio.', 'free'],
        'un precio' => ['Entiendo. El servicio cuesta $9.900, activa tu suscripción.', 'currency'],
    ]);

    it('takes no bare tip as the fallback of a close', function (string $text, ?string $reason) {
        expect(app(CommercialClaims::class)->closingFallbackViolation($text, 'Asesor'))->toBe($reason);
    })->with([
        'un consejo' => ['Una pregunta abierta suele romper el hielo con naturalidad.', 'unanchored'],
        'otro consejo' => ['Lo ideal es empezar con un comentario sobre el lugar.', 'unanchored'],
        'una despedida' => ['Entiendo, el servicio te acompaña. ¡Hasta pronto!', 'farewell'],
        'sobre el servicio' => ['Entiendo, eso es justo lo que el servicio te ayuda a trabajar.', null],
    ]);

    it('closes for a product of any sector by its own name, with no word of the sector in the Core', function (string $name, string $close) {
        $this->product->update(['name' => $name]);
        $this->turns = [gfJson($close, question: 'contenido')];

        gfSay('¿Por dónde empiezo?');

        expect(gfLast())->toBe($close);
    })->with([
        ['Recetario Fácil', 'Entiendo que quieres organizar tus comidas. Recetario Fácil está pensado justo para eso. Si te interesa, revisa los planes.'],
        ['Gestión SST Pyme', 'Te entiendo, necesitas ordenar la seguridad de tu empresa. Gestión SST Pyme te acompaña en eso. Puedes activar tu suscripción cuando quieras.'],
        ['Curso de inglés', 'Entiendo que quieres hablar con más soltura. El Curso de inglés trabaja justo eso. Conoce los planes cuando quieras.'],
    ]);

    it('keeps everything as before when it is turned off', function () {
        config(['botinker.sales_funnels.guided.closing_reply.enabled' => false]);
        Log::spy();
        $strict = 'Entiendo. Eso se trabaja con el servicio completo. Si quieres, conoce los planes.';

        foreach (['¿Cómo inicio una conversación?', '¿Y si me pongo nervioso?'] as $message) {
            $this->turns = [gfJson($strict, fallback: $this->fallback)];
            gfSay($message);

            expect(gfLast())->toBe($strict)
                ->and(gfLastTurnPrompt())->toContain('explica en una frase que eso se trabaja con el servicio completo')->not->toContain('El cliente ya usó sus respuestas gratuitas');
        }

        $this->turns = [gfJson('Entiendo, aquí estaré.', question: 'rechazo')];
        gfSay('Ya me cansé de esto');

        expect(ConversationFunnelState::sole()->context)->not->toHaveKey('closing')->not->toHaveKey('declined_until');
        Log::shouldNotHaveReceived('info', ['FUNNEL_LIMIT_READ', Mockery::on(fn (array $context) => $context['closing'] !== null)]);
    });
});

describe('voice notes: the transcription is the customer\'s message', function () {
    beforeEach(function () {
        gfPublish(free: 3);
        $this->voice = function (string $transcription) {
            $this->transcriptions[] = $transcription;
            ProcessWhatsAppMessage::dispatchSync($this->store->fresh(), GF_PHONE, null, 'wamid.in.'.Str::random(10), 'audio', 'media-'.Str::random(6));
            $this->travel(1)->seconds();
        };
        // The last message each model call received, and its system prompt.
        $this->lastUserMessage = fn (array $chat) => end($chat)['content'];
        $this->audioRule = 'es la transcripción de un audio del cliente: es su mensaje, respóndelo como si lo hubiera escrito. Nunca digas que no puedes escuchar ni procesar audios';
        gfSay('Hola');
    });

    it('answers what a voice note says, with the rule that it is the customer\'s message in every prompt', function (string $transcription) {
        $this->turns = [gfJson('', question: 'contenido')];
        $this->replies = [gfJson('RESPUESTA-AL-AUDIO')];
        $chats = count($this->chats);

        ($this->voice)($transcription);

        $calls = array_slice($this->chats, $chats);

        expect(gfLast())->toBe('RESPUESTA-AL-AUDIO')->not->toContain('No pude transcribir')
            ->and($calls)->not->toBeEmpty()
            ->and(WhatsAppMessage::where('role', 'user')->latest('id')->value('content'))->toBe(PromptAssembler::AUDIO_MARKER.' '.$transcription);

        foreach ($calls as $chat) {
            if (str_contains($chat[0]['content'], 'Eres un selector de conocimiento')) {
                continue;
            }

            // The whole transcription reaches the model as the customer's message, and the rule is there.
            expect(($this->lastUserMessage)($chat))->toBe('🎤 [AUDIO]: '.$transcription)
                ->and($chat[0]['content'])->toContain($this->audioRule)
                ->and(substr_count($chat[0]['content'], $this->audioRule))->toBe(1);
        }
    })->with([
        'válida' => ['Quiero saber cómo funciona el acompañamiento para no volver a perder.'],
        'breve' => ['para no volver a perder.'],
        'extensa' => [str_repeat('Te cuento que llevo varios meses intentando organizarme y siempre termino dejando todo a medias, ', 15).'¿me puedes orientar?'],
    ]);

    it('asks the customer to repeat or write it, and spends nothing, when there is no valid transcription', function (?string $transcription) {
        $transcription === null ? $this->transcriberDown = true : null;
        $chats = count($this->chats);
        $messages = WhatsAppMessage::count();
        $samples = SampleDelivery::count();

        ($this->voice)($transcription ?? '');

        expect(gfLast())->toBe('No pude transcribir tu audio. Por favor intenta de nuevo o escríbeme tu pregunta.')
            ->and(array_slice($this->chats, $chats))->toBe([])
            ->and(WhatsAppMessage::count())->toBe($messages)
            ->and(SampleDelivery::count())->toBe($samples);
    })->with([
        'vacía' => [''],
        'solo espacios' => ['   '],
        'ilegible, sin letras ni números' => ['... ¿? ♪♪'],
        'fallida' => [null],
    ]);

    it('answers a voice note in a commercial conversation already under way, with the rule in the commercial turn', function () {
        gfPublish(free: 1);
        gfSay('¿Cómo funciona?');
        $this->turns = [gfJson('Te entiendo, quieres dejar de perder oportunidades. El servicio te acompaña en eso; conoce los planes.', question: 'comercial')];

        ($this->voice)('para no volver a perder.');

        expect(gfLast())->toStartWith('Te entiendo, quieres dejar de perder oportunidades.')->not->toContain('No pude transcribir')
            ->and(gfLastTurnPrompt())->toContain($this->audioRule)
            ->and(($this->lastUserMessage)(collect($this->chats)->last(fn (array $chat) => str_contains($chat[0]['content'], 'Este es un turno comercial'))))->toBe('🎤 [AUDIO]: para no volver a perder.')
            ->and(Payment::count())->toBe(0);
    });

    it('carries the rule in the prompt of a reply outside the funnel, after every other configured text', function () {
        $prompt = app(PromptAssembler::class)->build($this->store, null, $this->product);

        expect($prompt)->toContain($this->audioRule)
            ->and(strpos($prompt, $this->audioRule))->toBeGreaterThan(strpos($prompt, '### REGLAS DEL SISTEMA'))
            ->and($prompt)->toEndWith('- Si la información disponible no basta para responder, dilo con honestidad.');
    });
});

describe('product questions after the free replies: how it is taught, its format, what is being bought', function () {
    beforeEach(function () {
        gfPublish(free: 1);
        // The only free reply, with the plans after it.
        gfSay('Uno');
        $this->limitReply = trim((string) config('botinker.sales_funnels.guided.limit_reply'));
        $this->answer = 'El servicio funciona por conversaciones contigo, a tu ritmo y según tu situación. Cuando quieras, conoce los planes.';
        $this->close = 'Entiendo que quieres sentirte seguro al iniciar conversaciones. Esa habilidad se trabaja en el servicio completo, adaptado a tu situación. Si quieres seguir, puedes activar tu suscripción.';
    });

    it('tells the model that how the service is taught or delivered, and what is being bought, is a commercial question', function () {
        $this->turns = [gfJson($this->answer, question: 'comercial')];
        gfSay('Cómo es el método de enseñanza');

        expect(gfLastTurnPrompt())
            ->toContain('por cómo se enseña o se presta (su método, modalidad o formato) o por qué está comprando')
            ->toContain('según la conversación anterior, aclara o supone qué tipo de producto es («¿es un libro?», o «un libro» como respuesta a eso)')
            ->toContain('"contenido" si pide recibir o aplicar el tema del servicio en sí (consejos, técnicas, ejemplos, ejercicios o ayuda con su situación), no cómo se enseña o se presta')
            // Generic: nothing of any one product in the Core's rule.
            ->and((string) config('botinker.sales_funnels.guided.commercial_turn'))->not->toContain('Asesor')->not->toContain('seducción')->not->toContain('MAX');
    });

    it('answers a commercial question about the product with the validated wording, never the limit reply', function (string $message) {
        Log::spy();
        $this->turns = [gfJson($this->answer, question: 'comercial')];

        gfSay($message);

        // The plans were just shown with the last free reply: the answer goes alone.
        expect(gfLast())->toBe($this->answer)->not->toContain($this->limitReply)
            ->and(gfTurns())->toBe(1)->and(Payment::count())->toBe(0);
        Log::shouldHaveReceived('info')->with('FUNNEL_LIMIT_READ', Mockery::on(fn (array $context) => $context['question'] === 'comercial' && $context['discarded'] === null && $context['closing'] === null));
    })->with([
        'método de enseñanza' => ['Cómo es el método de enseñanza'],
        'naturaleza del producto' => ['¿Es un libro?'],
        'modalidad' => ['¿Es presencial o por WhatsApp?'],
        'formato' => ['¿En qué formato llega lo que compro?'],
    ]);

    it('reads «Un libro» with the conversation before it, and answers it as a commercial question', function () {
        $this->turns = [gfJson('Te cuento cómo funciona el servicio. ¿Qué te gustaría saber?', question: 'comercial')];
        gfSay('¿Qué recibo exactamente?');

        $this->turns = [gfJson($this->answer, question: 'comercial')];
        gfSay('Un libro');

        $chat = collect($this->chats)->last(fn (array $messages) => str_contains($messages[0]['content'], 'Este es un turno comercial'));

        expect(gfLast())->toBe($this->answer)->not->toContain($this->limitReply)
            // The model reads it after what was said before, not alone.
            ->and(collect($chat)->pluck('content')->implode("\n"))->toContain('¿Qué recibo exactamente?')
            ->and(end($chat)['content'])->toBe('Un libro');
    });

    it('answers a question about the method with the approved verbatim answer first, when there is one', function () {
        gfSource('Cómo funciona', 'TEXTO-LITERAL-DEL-METODO', KnowledgeSource::PURPOSE_COMMERCIAL, KnowledgeSource::DELIVERY_VERBATIM);
        $this->sections = ['Cómo funciona'];
        $this->turns = [gfJson($this->answer, question: 'comercial')];

        gfSay('Cómo es el método de enseñanza');

        expect(gfLast())->toBe('TEXTO-LITERAL-DEL-METODO')->not->toContain($this->limitReply);
    });

    it('keeps the contextual close for a real request for content', function (string $message) {
        Log::spy();
        $this->turns = [gfJson($this->close, question: 'contenido')];

        gfSay($message);

        expect(gfLast())->toBe($this->close)->and(gfClosing()['status'])->toBe(FunnelStates::CLOSING_SENT)->and(gfTurns())->toBe(1);
        Log::shouldHaveReceived('info')->with('FUNNEL_LIMIT_READ', Mockery::on(fn (array $context) => $context['question'] === 'contenido' && $context['closing'] === 'model'));
    })->with([
        'una técnica' => ['Enséñame una técnica para iniciar conversaciones'],
        'su situación' => ['Ayúdame con una situación concreta'],
    ]);
});

describe('the opening: a pre-filled message cut short, and the ad the customer came from', function () {
    beforeEach(function () {
        gfPublish(free: 1);
        $this->store->update(['welcome_message' => 'BIENVENIDA-TIENDA']);
        $this->product->update(['opening_message' => 'APERTURA-LITERAL', 'opening_triggers' => ['Hola! Quiero conocer el coach Asesor Personal']]);
        $this->other = Product::create(['store_id' => $this->store->id, 'domain_key' => 'tema', 'name' => 'Otro', 'description' => 'd', 'price' => 1, 'stock' => 1, 'access_mode' => Product::ACCESS_SUBSCRIPTION,
            'opening_message' => 'APERTURA-OTRO', 'opening_triggers' => ['Quiero saber del curso de cocina']]);
        $this->openings = app(OpeningMessage::class);
        // A text message as the webhook passes it on, with the product its ad named (or none).
        $this->fromAd = function (string $body, ?int $productId) {
            ProcessWhatsAppMessage::dispatchSync($this->store->fresh(), GF_PHONE, $body, 'wamid.in.'.Str::random(10), 'text', null, $productId);
            $this->travel(1)->seconds();
        };
        $this->openingsSent = fn () => collect(sentWhatsAppTexts())->filter(fn (Request $request) => $request->data()['text']['body'] === 'APERTURA-LITERAL')->count();
    });

    it('opens with the start of the only trigger it fits, without the store\'s welcome and spending no free reply', function () {
        gfConversation()->update(['current_product_id' => $this->product->id]);

        gfSay('Hola! Quiero conocer el co');

        expect(gfLast())->toBe('APERTURA-LITERAL')->not->toContain('BIENVENIDA-TIENDA')
            ->and($this->turnPrompts)->toBe([])->and(gfTurns())->toBe(0);
    });

    it('still opens with the whole trigger', function () {
        gfConversation()->update(['current_product_id' => $this->product->id]);

        gfSay('Hola! Quiero conocer el coach Asesor Personal');

        expect(gfLast())->toBe('APERTURA-LITERAL')->and(gfTurns())->toBe(0);
    });

    it('takes the start of a trigger only when it is long enough, at its start, and of one trigger alone', function (string $message, ?string $opening) {
        expect($this->openings->for($this->product->fresh(), $message))->toBe($opening);
    })->with([
        'el comienzo de un único disparador' => ['Hola! Quiero conocer el co', 'APERTURA-LITERAL'],
        'con tildes y signos distintos' => ['¡hola!  QUIERO conocer el coach', 'APERTURA-LITERAL'],
        'menos de tres palabras' => ['Hola! Quiero conocer', null],
        'tres palabras y menos de 15 caracteres' => ['Quiero el co', null],
        'sus palabras, sin su comienzo' => ['conocer el coach Asesor Personal', null],
        'otro orden' => ['Hola! El coach quiero conocer', null],
        'el texto sigue más allá del disparador' => ['Hola! Quiero conocer el coach Asesor Personal ya mismo', null],
        'solo un saludo' => ['Hola', null],
    ]);

    it('opens nothing when two products of the store share that start', function () {
        $this->other->update(['opening_triggers' => ['Hola! Quiero conocer el coach Otro']]);

        expect($this->openings->for($this->product->fresh(), 'Hola! Quiero conocer el co'))->toBeNull()
            ->and($this->openings->for($this->other->fresh(), 'Hola! Quiero conocer el co'))->toBeNull()
            // What is not shared still tells them apart.
            ->and($this->openings->for($this->product->fresh(), 'Hola! Quiero conocer el coach As'))->toBe('APERTURA-LITERAL');
    });

    it('never opens again once the funnel started', function () {
        gfConversation()->update(['current_product_id' => $this->product->id]);
        gfSay('Hola! Quiero conocer el co');
        $this->turns = [gfJson('RESPUESTA-DEL-TURNO', question: 'comercial')];

        gfSay('Hola! Quiero conocer el co');

        expect(gfLast())->not->toBe('APERTURA-LITERAL')->and(($this->openingsSent)())->toBe(1);
    });

    it('opens from the ad whatever its pre-filled text became: whole, cut short or changed', function (string $body) {
        ($this->fromAd)($body, $this->product->id);

        expect(gfLast())->toBe('APERTURA-LITERAL')->not->toContain('BIENVENIDA-TIENDA')
            ->and($this->turnPrompts)->toBe([])->and(gfTurns())->toBe(0)
            ->and(gfConversation()->fresh()->current_product_id)->toBe($this->product->id);
    })->with([
        'completo' => ['Hola! Quiero conocer el coach Asesor Personal'],
        'incompleto' => ['Hola! Quiero conocer el co'],
        'cambiado' => ['Buenas, me interesa'],
        'casi vacío' => ['.'],
    ]);

    it('keeps the ad first: a message naming another product still opens the ad\'s', function () {
        ($this->fromAd)('Quiero saber del curso de cocina Otro', $this->product->id);

        expect(gfLast())->toBe('APERTURA-LITERAL')->and(gfConversation()->fresh()->current_product_id)->toBe($this->product->id);
    });

    it('goes by the text as before when the ad names no product', function () {
        gfConversation()->update(['current_product_id' => $this->product->id]);
        $this->turns = [gfJson('Hola, cuéntame qué buscas.', question: 'cortesia')];

        ($this->fromAd)('Buenas, me interesa', null);
        expect(gfLast())->not->toContain('APERTURA-LITERAL');

        ConversationFunnelState::query()->delete();
        ($this->fromAd)('Hola! Quiero conocer el co', null);
        expect(gfLast())->toBe('APERTURA-LITERAL');
    });

    it('never opens again nor restarts a funnel under way when the customer comes from the same ad again', function () {
        ($this->fromAd)('Hola! Quiero conocer el coach Asesor Personal', $this->product->id);
        $this->turns = [gfJson('Te cuento cómo funciona.', question: 'comercial')];
        gfSay('¿Cómo funciona?');
        $state = ConversationFunnelState::where('product_id', $this->product->id)->sole();

        $this->turns = [gfJson('Claro, sigamos.', question: 'comercial')];
        ($this->fromAd)('Hola! Quiero conocer el coach Asesor Personal', $this->product->id);

        $after = ConversationFunnelState::where('product_id', $this->product->id)->sole();

        expect(gfLast())->toBe('Claro, sigamos.')->and(($this->openingsSent)())->toBe(1)
            ->and($after->id)->toBe($state->id)->and($after->stage)->toBe($state->stage)->and($after->created_at->equalTo($state->created_at))->toBeTrue();
    });

    it('leaves the funnel under way untouched when an ad of another product arrives', function () {
        ($this->fromAd)('Hola! Quiero conocer el coach Asesor Personal', $this->product->id);
        $this->turns = [gfJson('Te cuento cómo funciona.', question: 'comercial')];
        gfSay('¿Cómo funciona?');
        $state = ConversationFunnelState::where('product_id', $this->product->id)->sole()->only(['id', 'stage', 'stage_interactions', 'context']);

        ($this->fromAd)('Quiero saber del curso de cocina', $this->other->id);

        expect(ConversationFunnelState::where('product_id', $this->product->id)->sole()->only(['id', 'stage', 'stage_interactions', 'context']))->toBe($state);
    });

    it('attends the product of a valid ad after a conversation about another one, with its opening', function (string $body) {
        ($this->fromAd)('Hola! Quiero conocer el coach Asesor Personal', $this->product->id);
        $this->turns = [gfJson('Te cuento cómo funciona.', question: 'comercial')];
        gfSay('¿Cómo funciona?');
        expect(gfConversation()->fresh()->current_product_id)->toBe($this->product->id);

        ($this->fromAd)($body, $this->other->id);

        expect(gfLast())->toBe('APERTURA-OTRO')->not->toContain('BIENVENIDA-TIENDA')
            ->and(gfConversation()->fresh()->current_product_id)->toBe($this->other->id);
    })->with([
        'completo' => ['Quiero saber del curso de cocina'],
        'incompleto' => ['Quiero saber del cu'],
        'casi vacío' => ['.'],
    ]);

    it('changes nothing of the other product\'s commercial state: its payment, its funnel and its history', function () {
        ($this->fromAd)('Hola! Quiero conocer el coach Asesor Personal', $this->product->id);
        ProcessWhatsAppMessage::dispatchSync($this->store->fresh(), GF_PHONE, 'Semanal', 'wamid.in.'.Str::random(10), 'interactive', null, null, null, 'plan:'.Plan::where('name', 'Semanal')->value('id'));
        $this->travel(1)->seconds();
        $payment = Payment::sole()->only(['id', 'product_id', 'status', 'plan_name', 'amount']);
        $state = ConversationFunnelState::where('product_id', $this->product->id)->sole()->only(['id', 'stage', 'stage_interactions', 'context']);
        $history = WhatsAppMessage::orderBy('id')->pluck('content', 'id')->all();

        ($this->fromAd)('Quiero saber del cu', $this->other->id);

        expect(gfLast())->toBe('APERTURA-OTRO')
            ->and(Payment::sole()->only(['id', 'product_id', 'status', 'plan_name', 'amount']))->toBe($payment)
            ->and(ConversationFunnelState::where('product_id', $this->product->id)->sole()->only(['id', 'stage', 'stage_interactions', 'context']))->toBe($state)
            // Earlier messages stay as they were; only the new turn is added.
            ->and(array_slice(WhatsAppMessage::orderBy('id')->pluck('content', 'id')->all(), 0, count($history), true))->toBe($history);
    });

    it('keeps the subscription to the other product when an ad of another one arrives', function () {
        gfSay('Hola');
        $subscription = Subscription::create(['customer_id' => \App\Models\Customer::where('store_id', $this->store->id)->value('id'), 'product_id' => $this->product->id, 'started_at' => now()->subDays(2), 'expires_at' => now()->addDays(5)]);

        ($this->fromAd)('', $this->other->id);

        expect(gfLast())->toBe('APERTURA-OTRO')
            ->and($subscription->fresh()->only(['product_id', 'started_at', 'expires_at']))->toEqual($subscription->only(['product_id', 'started_at', 'expires_at']))
            ->and(Subscription::count())->toBe(1);
    });
});
