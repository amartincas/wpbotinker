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
use App\Platform\Funnel\FunnelDefinition;
use App\Platform\Funnel\GuidedFunnel;
use App\Platform\Funnel\SalesFunnelVersions;
use App\Platform\Knowledge\KnowledgeIngestor;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;

const GF_PHONE = '573007778899';

beforeEach(function () {
    $this->freezeSecond();

    $this->replies = [];
    $this->prompts = [];
    $this->selectorCalls = 0;
    $this->sections = [];
    $this->modelDown = false;

    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'api.openai.com')) {
            $system = $request->data()['messages'][0]['content'];

            if (str_contains($system, 'Eres un selector de conocimiento')) {
                $this->selectorCalls++;

                return Http::response(['choices' => [['message' => ['content' => json_encode(['needs_knowledge' => true, 'sections' => $this->sections])]]]]);
            }

            if ($this->modelDown) {
                return Http::response(['error' => 'upstream'], 500);
            }

            $this->prompts[] = $system;

            return Http::response(['choices' => [['message' => ['content' => array_shift($this->replies) ?? gfJson('RESPUESTA-DEL-MODELO')]]]]);
        }

        return Http::response(['messages' => [['id' => 'wamid.out.'.Str::random(8)]]]);
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

function gfJson(string $reply, ?string $intent = null, float $confidence = 0.0): string
{
    return json_encode(['reply' => $reply, 'intent' => $intent, 'confidence' => $confidence], JSON_UNESCAPED_UNICODE);
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

function gfTurns(): int
{
    return (int) SampleDelivery::where('product_id', test()->product->id)->value('turns_used');
}

function gfSource(string $title, string $marker, string $purpose): void
{
    app(KnowledgeIngestor::class)->ingest(test()->store, 'tema', test()->product, 'Fuente', 'markdown', "# {$title}\n\n{$marker}", origin: 'origen-'.Str::random(6), purpose: $purpose);
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

    it('stops conversing: the model only reads, its text never goes out, and nothing is spent', function () {
        $this->replies = [gfJson('Te doy otro consejo gratis.')];

        gfSay('Cuéntame más cosas');

        expect(gfLast())->not->toContain('consejo')->toContain('necesitas una suscripción')->toContain('$9.900')
            ->and(gfTurns())->toBe(1)
            ->and(gfLastPrompt())->toContain('tu texto no se le enviará')
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

        expect(gfLast())->toContain('necesitas una suscripción')->toContain('$9.900')
            ->and(Payment::count())->toBe(0);
    });

    it('does not take a broken answer as a purchase', function () {
        $this->replies = ['{"intent": "comprar", roto'];

        gfSay('Cuéntame más');

        expect(gfLast())->toContain('necesitas una suscripción')->and(Payment::count())->toBe(0);
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
