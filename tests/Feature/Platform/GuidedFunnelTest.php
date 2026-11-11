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
use App\Platform\Commerce\CommercialClaims;
use App\Platform\Funnel\FunnelDefinition;
use App\Platform\Funnel\FunnelStates;
use App\Platform\Funnel\GuidedFunnel;
use App\Platform\Funnel\SalesFunnelVersions;
use App\Platform\Funnel\StageReply;
use App\Platform\Knowledge\KnowledgeIngestor;
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
    $this->selectorCalls = 0;
    $this->chats = [];
    $this->sections = [];
    $this->modelDown = false;
    $this->selectorDown = false;
    $this->whatsAppDown = false;

    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'api.openai.com')) {
            $system = $request->data()['messages'][0]['content'];
            $this->chats[] = $request->data()['messages'];

            if (str_contains($system, 'Eres un selector de conocimiento')) {
                $this->selectorCalls++;

                if ($this->selectorDown) {
                    return Http::response(['error' => 'upstream'], 500);
                }

                return Http::response(['choices' => [['message' => ['content' => json_encode(['needs_knowledge' => true, 'sections' => $this->sections])]]]]);
            }

            if ($this->modelDown) {
                return Http::response(['error' => 'upstream'], 500);
            }

            $this->prompts[] = $system;

            return Http::response(['choices' => [['message' => ['content' => array_shift($this->replies) ?? gfJson('RESPUESTA-DEL-MODELO')]]]]);
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

function gfJson(string $reply, ?string $intent = null, float $confidence = 0.0, ?string $question = null): string
{
    return json_encode(['reply' => $reply, 'intent' => $intent, 'confidence' => $confidence] + ($question !== null ? ['question' => $question] : []), JSON_UNESCAPED_UNICODE);
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
        expect(gfLast())->toBe('APERTURA-LITERAL')->and($this->prompts)->toBe([])->and(gfTurns())->toBe(0);

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
        $this->replies = [gfJson('Te doy otro consejo gratis.')];

        gfSay('Cuéntame más cosas');

        expect(gfLast())->not->toContain('consejo')->toContain(config('botinker.sales_funnels.guided.limit_reply'))->not->toContain('necesitas una suscripción')
            ->and(gfTurns())->toBe(1)
            ->and(gfLastPrompt())->toContain('ya no resuelvas su consulta')
            ->and(Payment::count())->toBe(0);
    });

    it('does not repeat the offer: the Core answers with its reminder', function () {
        gfSay('Cuéntame más');
        gfSay('Y algo más');

        expect(gfLast())->toContain('Escribe «planes» para ver los precios o «pagar» para suscribirte.')->not->toContain('$9.900');
    });

    it('buys in the customer\'s own words', function () {
        $this->replies = [gfJson('', GuidedFunnel::INTENT_BUY, 0.95)];

        gfSay('Va, me quedo con esto');

        expect(gfLast())->toContain('¿Qué plan de «Asesor» quieres?')->and(Payment::count())->toBe(0);

        gfSay('1');

        expect(Payment::first()->plan->name)->toBe('Semanal')
            ->and(gfLast())->toContain('INSTRUCCIONES-PAGO')
            ->and(gfTurns())->toBe(1);
    });

    it('shows the plans when asked in the customer\'s own words, even right after showing them', function () {
        gfSay('Cuéntame más');

        $this->replies = [gfJson('', GuidedFunnel::INTENT_OFFER, 0.9)];

        gfSay('Muéstrame otra vez las opciones que hay');

        expect(gfLast())->toContain('$9.900')->toContain('$19.900');
    });

    it('still takes a plan by its name and the exact words, with no model call', function () {
        $calls = count($this->prompts);

        gfSay('Quiero el mensual');

        expect(Payment::first()->plan->name)->toBe('Mensual')
            ->and($this->prompts)->toHaveCount($calls);
    });

    it('costs one model call per message: no selector and no commercial advisor, whatever the product has', function () {
        gfSource('Comercial', 'CONTENIDO-COMERCIAL', KnowledgeSource::PURPOSE_COMMERCIAL);
        gfSource('Muestra', 'CONTENIDO-MUESTRA', KnowledgeSource::PURPOSE_SAMPLE);
        $calls = count($this->prompts);
        $selectors = $this->selectorCalls;

        gfSay('Háblame de lo que incluye');

        expect($this->prompts)->toHaveCount($calls + 1)
            ->and($this->selectorCalls)->toBe($selectors)
            ->and(gfLastPrompt())->not->toContain('CONTENIDO-COMERCIAL')->not->toContain('CONTENIDO-MUESTRA');
    });

    it('falls back on the Core\'s reply when the model fails', function () {
        $this->modelDown = true;

        gfSay('Cuéntame más');

        expect(gfLast())->toContain(config('botinker.sales_funnels.guided.limit_reply'))->toContain('Escribe «planes»')
            ->and(Payment::count())->toBe(0);
    });

    it('does not take a broken answer as a purchase', function () {
        $this->replies = ['{"intent": "comprar", roto'];

        gfSay('Cuéntame más');

        expect(gfLast())->toContain(config('botinker.sales_funnels.guided.limit_reply'))->and(Payment::count())->toBe(0);
    });

    it('grants nothing by itself: a purchase ends in a pending payment, with no access', function () {
        $this->replies = [gfJson('', GuidedFunnel::INTENT_BUY, 0.95)];

        gfSay('Va, me quedo con esto');
        gfSay('1');

        expect(Payment::first()->status)->toBe(Payment::STATUS_PENDING)
            ->and(Subscription::count())->toBe(0);

        $calls = count($this->prompts);
        gfSay('Tengo una duda');

        expect(gfLast())->toContain('INSTRUCCIONES-PAGO')->and($this->prompts)->toHaveCount($calls);
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
        $this->replies = [gfJson('CONTENIDO-GENERADO-POR-IA')];

        gfSay('¿Cómo identifico sus señales?');

        expect(gfLast())
            ->toStartWith(config('botinker.sales_funnels.guided.limit_reply'))
            ->toContain('Escribe «planes» para ver los precios o «pagar» para suscribirte.')
            ->not->toContain('CONTENIDO-GENERADO-POR-IA')
            ->not->toContain('necesitas una suscripción');
    });

    it('shows the plans again once the offer is no longer recent, only for a commercial question', function () {
        $this->travel(25)->hours();
        $this->replies = [gfJson('CONTENIDO-GENERADO-POR-IA', question: 'comercial')];

        gfSay('¿Cómo se paga?');

        expect(gfLast())->toStartWith(config('botinker.sales_funnels.guided.limit_reply'))->toContain('$9.900')->toContain('$19.900');
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
            ->and($this->prompts)->toHaveCount(3);
    });

    it('sends up to five commercial replies from the model, spending no free reply, and then the fixed message', function () {
        foreach (range(1, 5) as $read) {
            $this->replies = [gfJson($this->comercial)];

            gfSay("¿Cómo identifico sus señales? {$read}");

            expect(gfLast())->toBe($this->comercial)->and(gfReads())->toBe($read);
        }

        $calls = count($this->prompts);

        gfSay('¿Y cómo le pido su número?');

        expect(gfLast())->toStartWith(config('botinker.sales_funnels.guided.limit_reply'))->toContain('Escribe «planes»')
            ->and($this->prompts)->toHaveCount($calls)
            ->and(gfReads())->toBe(5)
            ->and(gfTurns())->toBe(3);
    });

    it('classifies and words in one single call, with no material for the model', function () {
        gfSource('Exclusivo', 'CONTENIDO-EXCLUSIVO', KnowledgeSource::PURPOSE_KNOWLEDGE);
        gfSource('Muestra', 'CONTENIDO-MUESTRA', KnowledgeSource::PURPOSE_SAMPLE);
        gfSource('Comercial', 'CONTENIDO-COMERCIAL', KnowledgeSource::PURPOSE_COMMERCIAL);
        $calls = count($this->prompts);
        $selectors = $this->selectorCalls;
        $this->replies = [gfJson($this->comercial)];

        gfSay('Enséñame todo sobre las señales');

        expect($this->prompts)->toHaveCount($calls + 1)
            ->and($this->selectorCalls)->toBe($selectors)
            ->and(gfLastPrompt())
            ->not->toContain('CONTENIDO-EXCLUSIVO')->not->toContain('CONTENIDO-MUESTRA')->not->toContain('CONTENIDO-COMERCIAL')->not->toContain('MATERIAL AUTORIZADO')
            ->toContain('No des técnicas, pasos, ejemplos, ejercicios')
            ->toContain('INSTRUCCION-DEL-ADMINISTRADOR');
    });

    it('replaces educational content with the fixed message', function () {
        $this->replies = [gfJson('Fíjate en su mirada. Intenta mantener el contacto visual. Después conoce los planes.')];

        gfSay('¿Cómo identifico sus señales?');

        expect(gfLast())->not->toContain('mirada')->toStartWith(config('botinker.sales_funnels.guided.limit_reply'))
            ->and(gfReads())->toBe(1);
    });

    it('adds the plans to a commercial question only when they were not just shown', function () {
        $this->replies = [gfJson($this->comercial, question: 'comercial')];
        $this->travel(25)->hours();

        gfSay('¿Cómo funciona la suscripción?');

        expect(gfLast())->toStartWith($this->comercial)->toContain('$9.900')->toContain('$19.900');

        $this->replies = [gfJson($this->comercial, question: 'comercial')];

        gfSay('¿Y se renueva sola?');

        expect(gfLast())->toBe($this->comercial);
    });

    it('adds to any other question only how to ask for the plans, when they were not just shown', function () {
        $this->replies = [gfJson($this->comercial)];
        $this->travel(25)->hours();

        gfSay('¿Cómo identifico sus señales?');

        expect(gfLast())->toBe($this->comercial."\n\nEscribe «planes» para ver los precios o «pagar» para suscribirte.");
    });

    it('still reads a purchase in the customer\'s words while reads are left, spending one', function () {
        $this->replies = [gfJson('', GuidedFunnel::INTENT_BUY, 0.95)];

        gfSay('Va, me quedo con esto');

        expect(gfLast())->toContain('¿Qué plan de «Asesor» quieres?')->and(gfReads())->toBe(1);
    });

    it('serves what the Core recognizes without a call and without spending a read', function (string $message, string $expected) {
        $calls = count($this->prompts);

        gfSay($message);

        expect(gfLast())->toContain($expected)
            ->and($this->prompts)->toHaveCount($calls)
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

            $this->callsBefore = count($this->prompts);
        });

        it('keeps the plans, the prices and every purchase available, without the model', function (string $message, string $expected) {
            gfSay($message);

            expect(gfLast())->toContain($expected)
                ->and($this->prompts)->toHaveCount($this->callsBefore)
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
                ->and($this->prompts)->toHaveCount($this->callsBefore);
        });

        it('starts the payment for a plan by its name', function () {
            gfSay('Quiero el semanal');

            expect(Payment::first()->plan->name)->toBe('Semanal')->and($this->prompts)->toHaveCount($this->callsBefore);
        });

        it('answers an ambiguous expression with a simple way to go on', function () {
            gfSay('Pues creo que sí me interesa');

            expect(gfLast())->toContain('Escribe «planes» para ver los precios o «pagar» para suscribirte.')
                ->and($this->prompts)->toHaveCount($this->callsBefore);
        });
    });

    it('does not count twice a message retried after a failed delivery, nor call the model again', function () {
        $this->replies = [gfJson($this->comercial)];
        $calls = count($this->prompts);
        $this->whatsAppDown = true;

        expect(fn () => ProcessWhatsAppMessage::dispatchSync($this->store->fresh(), GF_PHONE, '¿Cómo identifico sus señales?', 'wamid.reintento', 'text', null))->toThrow(RuntimeException::class);

        $this->whatsAppDown = false;

        ProcessWhatsAppMessage::dispatchSync($this->store->fresh(), GF_PHONE, '¿Cómo identifico sus señales?', 'wamid.reintento', 'text', null);

        expect(gfReads())->toBe(1)
            ->and($this->prompts)->toHaveCount($calls + 1)
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

        expect(gfLastPrompt())->toContain('"question": "comercial"')->toContain('"contenido"');
    });

    it('shows the offer the model proposes for a commercial question', function () {
        $this->replies = [gfJson('', GuidedFunnel::INTENT_OFFER, 0.9, 'comercial')];

        gfSay('¿Qué opciones de pago tienen?');

        expect(gfLast())->toContain('$9.900')->toContain('$19.900');
    });

    it('never shows the offer unless the question was read as commercial, however old the offer', function (string $raw) {
        Log::spy();
        $this->travel(25)->hours();
        $this->replies = [$raw];

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

    it('takes "ok, lo tomo" right after the offer as a purchase, with no question read', function () {
        $this->replies = [gfJson('', GuidedFunnel::INTENT_BUY, 0.9)];

        gfSay('Ok, lo tomo');

        expect(gfLast())->toContain('¿Qué plan de «Asesor» quieres?')->and(Payment::count())->toBe(0);
    });

    it('takes a purchase read as commercial, even with no recent offer', function () {
        $this->travel(25)->hours();
        $this->replies = [gfJson('', GuidedFunnel::INTENT_BUY, 0.9, 'comercial')];

        gfSay('Quiero comprarlo ya mismo');

        expect(gfLast())->toContain('¿Qué plan de «Asesor» quieres?');
    });

    it('never starts a purchase for a content question or an ambiguous answer just because the offer is recent', function (string $raw, string $message) {
        $this->replies = [$raw];

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
        $this->replies = [gfJson('', GuidedFunnel::INTENT_BUY, 0.95)];

        gfSay('Ok, lo tomo');

        expect(gfLast())->not->toContain('¿Qué plan de «Asesor» quieres?')->and(Payment::count())->toBe(0);
    });

    it('keeps every action the Core recognizes, without the model', function (string $message, string $expected) {
        $calls = count($this->prompts);

        gfSay($message);

        expect(gfLast())->toContain($expected)->and($this->prompts)->toHaveCount($calls)->and(gfReads())->toBe(0);
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
        $this->replies = [gfJson($this->comercial, question: 'comercial')];
        $calls = count($this->prompts);
        $selectors = $this->selectorCalls;

        gfSay('¿Qué incluye el entrenamiento?');

        expect(gfLast())->toBe("TEXTO-LITERAL-APROBADO\n\n".$this->pointer)
            ->and($this->prompts)->toHaveCount($calls + 1)
            ->and($this->selectorCalls)->toBe($selectors + 1)
            ->and(gfReads())->toBe(1)
            ->and(gfTurns())->toBe(1)
            ->and(Payment::count())->toBe(0);
    });

    it('adds the plans after the literal answer when they were not just shown', function () {
        gfSource('Qué incluye', 'TEXTO-LITERAL-APROBADO', KnowledgeSource::PURPOSE_COMMERCIAL, KnowledgeSource::DELIVERY_VERBATIM);
        $this->sections = ['Qué incluye'];
        $this->travel(25)->hours();
        $this->replies = [gfJson($this->comercial, question: 'comercial')];

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
        $this->replies = [gfJson($this->comercial, question: 'comercial')];
        $this->chats = [];

        gfSay('¿Qué incluye el entrenamiento?');

        $selector = collect($this->chats)->first(fn (array $messages) => str_contains($messages[0]['content'], 'Eres un selector de conocimiento'));

        expect(gfLast())->toBe("TEXTO-LITERAL-APROBADO\n\n".$this->pointer)
            ->and(collect($selector)->pluck('content')->implode(' '))->not->toContain('Solo para redactar');
    });

    it('leaves out resources with an amount before keeping two', function () {
        app(KnowledgeIngestor::class)->ingest($this->store, 'tema', $this->product, 'Fuente', 'markdown',
            "# Precio\n\nCuesta $50.000 al mes.\n\n# Uno\n\nTEXTO-UNO\n\n# Dos\n\nTEXTO-DOS\n\n# Tres\n\nTEXTO-TRES",
            origin: 'origen-literal', purpose: KnowledgeSource::PURPOSE_COMMERCIAL, delivery: KnowledgeSource::DELIVERY_VERBATIM);
        $this->sections = ['Precio', 'Uno', 'Dos', 'Tres'];
        $this->replies = [gfJson($this->comercial, question: 'comercial')];

        gfSay('¿Qué incluye y cuánto cuesta?');

        expect(gfLast())->toBe("TEXTO-UNO\n\nTEXTO-DOS\n\n".$this->pointer);
    });

    it('says it has no confirmed information when nothing configured answers the question', function () {
        gfSource('Qué incluye', 'TEXTO-LITERAL-APROBADO', KnowledgeSource::PURPOSE_COMMERCIAL, KnowledgeSource::DELIVERY_VERBATIM);
        $this->sections = [];
        $this->replies = [gfJson('Sí, tiene garantía de 30 días.', question: 'comercial')];

        gfSay('¿El entrenamiento tiene garantía?');

        expect(gfLast())->toBe(config('botinker.sales_funnels.guided.undocumented_reply')."\n\n".$this->pointer)
            ->not->toContain('30 días')->not->toContain('TEXTO-LITERAL-APROBADO');
    });

    it('also counts a resource whose only match states an amount as nothing configured', function () {
        gfSource('Precio', 'Cuesta $50.000 al mes.', KnowledgeSource::PURPOSE_COMMERCIAL, KnowledgeSource::DELIVERY_VERBATIM);
        $this->sections = ['Precio'];
        $this->replies = [gfJson($this->comercial, question: 'comercial')];

        gfSay('¿Cuánto vale al mes el entrenamiento, más o menos?');

        expect(gfLast())->not->toContain('50.000');
    });

    it('never claims there is no information when the selection failed: the fixed reply goes', function () {
        gfSource('Qué incluye', 'TEXTO-LITERAL-APROBADO', KnowledgeSource::PURPOSE_COMMERCIAL, KnowledgeSource::DELIVERY_VERBATIM);
        $this->selectorDown = true;
        $this->replies = [gfJson('Sí, tiene garantía de 30 días.', question: 'comercial')];

        gfSay('¿El entrenamiento tiene garantía?');

        expect(gfLast())->toStartWith(config('botinker.sales_funnels.guided.limit_reply'))
            ->not->toContain(config('botinker.sales_funnels.guided.undocumented_reply'))->not->toContain('30 días');
    });

    it('keeps the current behaviour, with no selector call, when there is no verbatim commercial resource', function () {
        gfSource('Qué incluye', 'TEXTO-PARA-REDACTAR', KnowledgeSource::PURPOSE_COMMERCIAL);
        $this->sections = ['Qué incluye'];
        $this->replies = [gfJson($this->comercial, question: 'comercial')];
        $selectors = $this->selectorCalls;

        gfSay('¿Qué incluye el entrenamiento?');

        expect(gfLast())->toBe($this->comercial)->and($this->selectorCalls)->toBe($selectors);
    });

    it('keeps the current behaviour, with no selector call, without commercial instructions', function () {
        $this->product->update(['commercial_instructions' => null]);
        gfSource('Qué incluye', 'TEXTO-LITERAL-APROBADO', KnowledgeSource::PURPOSE_COMMERCIAL, KnowledgeSource::DELIVERY_VERBATIM);
        $this->sections = ['Qué incluye'];
        $this->replies = [gfJson($this->comercial, question: 'comercial')];
        $selectors = $this->selectorCalls;

        gfSay('¿Qué incluye el entrenamiento?');

        expect(gfLast())->toBe($this->comercial)->and($this->selectorCalls)->toBe($selectors);
    });

    it('never consults the resources for a question that is not commercial', function (?string $question) {
        gfSource('Qué incluye', 'TEXTO-LITERAL-APROBADO', KnowledgeSource::PURPOSE_COMMERCIAL, KnowledgeSource::DELIVERY_VERBATIM);
        $this->sections = ['Qué incluye'];
        $this->replies = [gfJson($this->comercial, question: $question)];
        $selectors = $this->selectorCalls;

        gfSay('¿Cómo identifico sus señales?');

        expect(gfLast())->not->toContain('TEXTO-LITERAL-APROBADO')->and($this->selectorCalls)->toBe($selectors);
    })->with(['contenido' => 'contenido', 'sin leer' => null]);

    it('does not spend a second read nor send twice when a literal answer is retried after a failed delivery', function () {
        gfSource('Qué incluye', 'TEXTO-LITERAL-APROBADO', KnowledgeSource::PURPOSE_COMMERCIAL, KnowledgeSource::DELIVERY_VERBATIM);
        $this->sections = ['Qué incluye'];
        $this->replies = [gfJson($this->comercial, question: 'comercial')];
        $calls = count($this->prompts);
        $this->whatsAppDown = true;

        expect(fn () => ProcessWhatsAppMessage::dispatchSync($this->store->fresh(), GF_PHONE, '¿Qué incluye?', 'wamid.reintento', 'text', null))->toThrow(RuntimeException::class);

        $this->whatsAppDown = false;

        ProcessWhatsAppMessage::dispatchSync($this->store->fresh(), GF_PHONE, '¿Qué incluye?', 'wamid.reintento', 'text', null);

        $delivered = Http::recorded(fn (Request $request, $response) => str_ends_with($request->url(), '/messages')
            && str_starts_with($request->data()['text']['body'] ?? '', 'TEXTO-LITERAL-APROBADO')
            && $response->successful());

        expect(gfReads())->toBe(1)
            ->and($this->prompts)->toHaveCount($calls + 1)
            ->and($delivered)->toHaveCount(1)
            ->and(gfLast())->toBe("TEXTO-LITERAL-APROBADO\n\n".$this->pointer)
            ->and(gfTurns())->toBe(1);
    });

    it('keeps the free replies as each product configures them', function (int $free) {
        gfPublish(free: $free);
        gfSource('Qué incluye', 'TEXTO-LITERAL-APROBADO', KnowledgeSource::PURPOSE_COMMERCIAL, KnowledgeSource::DELIVERY_VERBATIM);
        $this->sections = ['Qué incluye'];

        for ($turn = 2; $turn <= $free; $turn++) {
            gfSay("Pregunta {$turn}");
        }

        $this->replies = [gfJson($this->comercial, question: 'comercial')];

        gfSay('¿Qué incluye el entrenamiento?');

        expect(gfTurns())->toBe($free)->and(gfLast())->toStartWith('TEXTO-LITERAL-APROBADO');
    })->with([1, 3]);
});

describe('what the model receives after the limit', function () {
    beforeEach(function () {
        gfPublish(free: 1);
        $this->replies = [gfJson('CONSEJO-ANTERIOR-DE-MAX')];
        gfSay('PREGUNTA-ANTERIOR');
        $this->chats = [];
    });

    it('is the instruction, the product and the current message: no earlier message, and no material', function () {
        gfSource('Exclusivo', 'CONTENIDO-EXCLUSIVO', KnowledgeSource::PURPOSE_KNOWLEDGE);
        gfSource('Muestra', 'CONTENIDO-MUESTRA', KnowledgeSource::PURPOSE_SAMPLE);

        gfSay('MENSAJE-ACTUAL');

        expect($this->chats)->toHaveCount(1)
            ->and(array_column($this->chats[0], 'role'))->toBe(['system', 'user'])
            ->and($this->chats[0][1]['content'])->toBe('MENSAJE-ACTUAL')
            ->and($this->chats[0][0]['content'])
            ->toContain('INSTRUCCION-DEL-ADMINISTRADOR')->toContain('DESCRIPCION-PRODUCTO')->toContain('FAQ-PRODUCTO')
            ->not->toContain('CONSEJO-ANTERIOR-DE-MAX')->not->toContain('CONTENIDO-EXCLUSIVO')->not->toContain('CONTENIDO-MUESTRA');
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
                ->and(gfLast())->toContain('1. Semanal: $9.900 COP por 7 días')->toContain('Responde con el número');
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
            $this->replies = [gfJson('', GuidedFunnel::INTENT_BUY, 0.95)];

            gfSay('Va, me quedo con esto');

            expect(Payment::count())->toBe(0)->and(gfLast())->toContain('Responde con el número');
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
        $this->product->update(['name' => $name, 'aliases' => ['Método de prueba']]);
        gfPublish(free: 1);
        gfSay('Uno');

        foreach ([$reference, 'el Método de prueba'] as $mention) {
            $reply = "Te entiendo. Eso se trabaja en {$mention}. Si quieres, conoce los planes.";
            $this->replies = [gfJson($reply)];

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
