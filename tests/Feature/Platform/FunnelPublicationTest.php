<?php

/*
 * Publishing a funnel from the editor: validating, warning, showing the
 * impact and adding a new active version through the Core's service, only
 * after an explicit confirmation and only if nobody else published meanwhile.
 */

use App\Filament\Resources\Products\Pages\EditProductFunnel;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Products\Schemas\FunnelPublicationChecks as Checks;
use App\Filament\Resources\Products\Schemas\FunnelPublicationPreview;
use App\Models\Conversation;
use App\Models\ConversationFunnelState;
use App\Models\Customer;
use App\Models\KnowledgeSource;
use App\Models\Plan;
use App\Models\Product;
use App\Models\SalesFunnel;
use App\Models\SampleDelivery;
use App\Models\Store;
use App\Models\User;
use App\Platform\Funnel\SalesFunnelVersions;
use App\Platform\Knowledge\KnowledgeIngestor;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Domains\OracleTestDomain;

function fpubProduct(Store $store, array $overrides = []): Product
{
    return Product::create(array_merge([
        'store_id' => $store->id, 'domain_key' => 'oracle', 'name' => 'Asesor', 'description' => 'x', 'price' => 1, 'stock' => 1,
        'access_mode' => Product::ACCESS_SUBSCRIPTION,
    ], $overrides));
}

function fpubDefinition(string $name = 'Embudo-A', array $overrides = []): array
{
    return array_merge([
        'name' => $name,
        'goal' => 'OBJETIVO',
        'free_replies' => ['limit' => 2, 'on_exhausted' => 'venta'],
        'stages' => [
            ['key' => 'dudas', 'type' => 'ai', 'goal' => 'g', 'instructions' => 'i', 'uses_free_replies' => true, 'rules' => [
                ['when' => ['phrase' => ['Lo quiero']], 'then' => ['action' => 'go_to', 'stage' => 'venta']],
            ]],
            ['key' => 'venta', 'type' => 'ai', 'goal' => 'g', 'instructions' => 'i'],
        ],
    ], $overrides);
}

function fpubPage(Product $product, ?int $from = null): Testable
{
    return Livewire::withQueryParams($from !== null ? ['from' => $from] : [])
        ->test(EditProductFunnel::class, ['record' => $product->getRouteKey()]);
}

function fpubConfirm(Testable $page): Testable
{
    return $page->callAction('publish', data: ['confirmed' => true]);
}

/** The whole record of the versions and the pointer: what a failure must leave as it was. */
function fpubSnapshot(Product $product): array
{
    return [
        SalesFunnel::where('product_id', $product->id)->orderBy('id')->get()->map->only(['id', 'version', 'definition', 'restored_from_id', 'created_by'])->all(),
        Product::whereKey($product->id)->value('active_sales_funnel_id'),
    ];
}

function fpubState(Product $product, SalesFunnel $funnel, string $stage, string $phone): ConversationFunnelState
{
    $conversation = Conversation::create(['store_id' => $product->store_id, 'customer_phone' => $phone, 'last_session_at' => now()]);

    return ConversationFunnelState::create(['conversation_id' => $conversation->id, 'product_id' => $product->id, 'sales_funnel_id' => $funnel->id, 'stage' => $stage]);
}

beforeEach(function () {
    config(['botinker.domains' => ['oracle' => OracleTestDomain::class]]);

    $this->store = makeStore(['conversation_mode' => Store::MODE_KNOWLEDGE, 'payment_instructions' => 'PAGO']);
    $this->other = makeStore(['conversation_mode' => Store::MODE_KNOWLEDGE]);
    $this->product = fpubProduct($this->store);
    Plan::create(['product_id' => $this->product->id, 'name' => 'Mensual', 'price' => 20000, 'duration_days' => 30]);
    $this->foreignProduct = fpubProduct($this->other, ['name' => 'Ajeno']);
    $this->operator = User::factory()->create(['store_id' => $this->store->id, 'is_super_admin' => false]);
    $this->versions = app(SalesFunnelVersions::class);

    $this->actingAs($this->operator);
});

describe('publishing', function () {
    it('adds a new active version with its author, leaving the others as they were', function () {
        $first = $this->versions->publish($this->product, fpubDefinition('Embudo-A'));
        $history = $first->fresh()->only(['id', 'version', 'definition', 'product_snapshot', 'created_by']);

        $page = fpubPage($this->product)->fillForm(['name' => 'Embudo-Nuevo']);
        fpubConfirm($page)
            ->assertHasNoActionErrors()
            ->assertNotified('Se publicó y activó la versión v2.')
            ->assertRedirect(ProductResource::getUrl('funnel', ['record' => $this->product]));

        $new = SalesFunnel::where('product_id', $this->product->id)->orderByDesc('version')->first();

        expect($new->version)->toBe(2)
            ->and($new->definition['name'])->toBe('Embudo-Nuevo')
            ->and((int) $new->created_by)->toBe($this->operator->id)
            ->and($new->restored_from_id)->toBeNull()
            ->and((int) $this->product->fresh()->active_sales_funnel_id)->toBe($new->id)
            ->and($first->fresh()->only(['id', 'version', 'definition', 'product_snapshot', 'created_by']))->toEqual($history);
    });

    it('publishes the first version of a product', function () {
        $page = fpubPage($this->product)->fillForm([
            'name' => 'Primero',
            'goal' => 'Vender',
            'stages' => [['key' => 'dudas', 'type' => 'ai', 'goal' => 'g', 'instructions' => 'i']],
        ]);

        fpubConfirm($page)->assertHasNoActionErrors()->assertNotified('Se publicó y activó la versión v1.');

        expect($this->product->fresh()->activeFunnel()->version)->toBe(1)
            ->and($this->product->fresh()->activeFunnel()->definition['name'])->toBe('Primero');
    });

    it('publishes from an old version over the active one', function () {
        $this->versions->publish($this->product, fpubDefinition('Embudo-A'));
        $this->versions->publish($this->product, fpubDefinition('Embudo-B'));

        fpubConfirm(fpubPage($this->product, 1))->assertNotified('Se publicó y activó la versión v3.');

        expect($this->product->fresh()->activeFunnel()->definition['name'])->toBe('Embudo-A')
            ->and($this->product->fresh()->activeFunnel()->version)->toBe(3);
    });

    it('leaves one active version, and free replies and conversations as they were', function () {
        $first = $this->versions->publish($this->product, fpubDefinition());
        $customer = Customer::create(['store_id' => $this->store->id, 'customer_phone' => '573001230001']);
        $sample = SampleDelivery::create(['customer_id' => $customer->id, 'product_id' => $this->product->id, 'turns_used' => 2]);
        $state = fpubState($this->product, $first, 'dudas', '573001230002');

        fpubConfirm(fpubPage($this->product)->fillForm(['name' => 'Embudo-B']))->assertHasNoActionErrors();

        expect(Product::whereKey($this->product->id)->value('active_sales_funnel_id'))->not->toBe($first->id)
            ->and($sample->fresh()->turns_used)->toBe(2)
            ->and($state->fresh()->stage)->toBe('dudas')
            ->and((int) $state->fresh()->sales_funnel_id)->toBe($first->id);
    });

    it('uses the rules edited in the form', function () {
        $this->versions->publish($this->product, fpubDefinition());
        $page = fpubPage($this->product);
        $dudas = array_keys($page->instance()->data['stages'])[0];

        $page->set("data.stages.{$dudas}.rules", ['r' => ['condition' => 'interactions_reached', 'interactions' => '3', 'action' => 'go_to', 'stage' => 'venta']]);
        fpubConfirm($page)->assertHasNoActionErrors();

        expect($this->product->fresh()->activeFunnel()->definition['stages'][0]['rules'])
            ->toBe([['when' => ['interactions_reached' => 3], 'then' => ['action' => 'go_to', 'stage' => 'venta']]]);
    });
});

describe('confirming', function () {
    beforeEach(function () {
        $this->versions->publish($this->product, fpubDefinition());
        $this->before = fpubSnapshot($this->product);
    });

    it('publishes nothing just by opening the modal', function () {
        fpubPage($this->product)->mountAction('publish')->assertActionMounted('publish');

        expect(fpubSnapshot($this->product))->toEqual($this->before);
    });

    it('publishes nothing without the explicit confirmation', function () {
        fpubPage($this->product)
            ->callAction('publish')
            ->assertHasActionErrors(['confirmed' => 'accepted']);

        expect(fpubSnapshot($this->product))->toEqual($this->before);
    });

    it('publishes nothing if the confirmation is declined', function () {
        fpubPage($this->product)
            ->callAction('publish', data: ['confirmed' => false])
            ->assertHasActionErrors(['confirmed' => 'accepted']);

        expect(fpubSnapshot($this->product))->toEqual($this->before);
    });

    it('publishes nothing when the modal is closed', function () {
        fpubPage($this->product)->mountAction('publish')->unmountAction();

        expect(fpubSnapshot($this->product))->toEqual($this->before);
    });
});

describe('validating', function () {
    beforeEach(function () {
        $this->versions->publish($this->product, fpubDefinition());
        $this->before = fpubSnapshot($this->product);
    });

    it('shows the form\'s errors in their fields, in Spanish, without opening the modal', function () {
        fpubPage($this->product)
            ->fillForm(['name' => ''])
            ->mountAction('publish')
            ->assertHasFormErrors(['name' => 'required'])
            ->assertActionNotMounted('publish')
            ->assertSee('El embudo necesita un nombre.');

        expect(fpubSnapshot($this->product))->toEqual($this->before);
    });

    it('refuses a rule pointing to a stage that no longer exists', function () {
        $page = fpubPage($this->product);
        $dudas = array_keys($page->instance()->data['stages'])[0];
        $rule = array_keys($page->instance()->data['stages'][$dudas]['rules'])[0];
        $page->set("data.stages.{$dudas}.rules.{$rule}.stage", 'nada');

        $page->mountAction('publish')
            ->assertHasFormErrors(["stages.{$dudas}.rules.{$rule}.stage"])
            ->assertActionNotMounted('publish');

        expect(fpubSnapshot($this->product))->toEqual($this->before);
    });

    it('accepts a valid definition, storing nothing just for checking', function () {
        fpubPage($this->product)
            ->callAction('checkDefinition')
            ->assertHasNoFormErrors()
            ->assertNotified('La definición es válida.');

        expect(fpubSnapshot($this->product))->toEqual($this->before);
    });

    it('shows the warnings when checking', function () {
        Plan::where('product_id', $this->product->id)->delete();
        $page = fpubPage($this->product);
        $dudas = array_keys($page->instance()->data['stages'])[0];
        $page->set("data.stages.{$dudas}.rules", ['r' => ['condition' => 'plan_named', 'action' => 'start_payment']]);

        $page->callAction('checkDefinition')
            ->assertSee('Advertencias de configuración')
            ->assertSee('el producto no tiene planes activos');
    });
});

describe('warnings', function () {
    beforeEach(function () {
        $this->base = fpubDefinition();
    });

    it('finds nothing to warn about in a clean funnel', function () {
        expect(Checks::warnings($this->product, $this->base))->toBe([]);
    });

    it('warns of unreachable stages', function () {
        $this->base['stages'][] = ['key' => 'perdida', 'type' => 'ai', 'goal' => 'g', 'instructions' => 'i'];

        expect(Checks::warnings($this->product, $this->base))->toBe(['La etapa «perdida» es inalcanzable: ninguna regla ni transición lleva a ella.']);
    });

    it('counts every way of reaching a stage', function (array $stage, array $extra) {
        $definition = fpubDefinition(overrides: $extra + ['free_replies' => ['limit' => 2]]);
        $definition['stages'] = [
            ['key' => 'inicio', ...$stage],
            ['key' => 'destino', 'type' => 'ai', 'goal' => 'g', 'instructions' => 'i'],
        ];

        expect(Checks::warnings($this->product, $definition))->not->toContain('La etapa «destino» es inalcanzable: ninguna regla ni transición lleva a ella.');
    })->with([
        'a message\'s next' => [['type' => 'message', 'text' => 'x', 'next' => 'destino'], []],
        'on_limit' => [['type' => 'ai', 'goal' => 'g', 'instructions' => 'i', 'max_interactions' => 2, 'on_limit' => 'destino'], []],
        'a go_to rule' => [['type' => 'ai', 'goal' => 'g', 'instructions' => 'i', 'rules' => [['when' => ['any_reply' => true], 'then' => ['action' => 'go_to', 'stage' => 'destino']]]], []],
        'a payment going on to it' => [['type' => 'ai', 'goal' => 'g', 'instructions' => 'i', 'rules' => [['when' => ['any_reply' => true], 'then' => ['action' => 'start_payment', 'stage' => 'destino']]]], []],
        'an offer going on to it' => [['type' => 'ai', 'goal' => 'g', 'instructions' => 'i', 'rules' => [['when' => ['any_reply' => true], 'then' => ['action' => 'show_offer', 'stage' => 'destino']]]], []],
        'exhausting free replies' => [['type' => 'ai', 'goal' => 'g', 'instructions' => 'i', 'uses_free_replies' => true], ['free_replies' => ['limit' => 2, 'on_exhausted' => 'destino']]],
    ]);

    it('starts counting from the configured start stage', function () {
        $this->base['start_stage'] = 'venta';

        expect(Checks::warnings($this->product, $this->base))->toContain('La etapa «dudas» es inalcanzable: ninguna regla ni transición lleva a ella.');
    });

    it('warns of cycles, once each', function () {
        $this->base['stages'][1]['rules'] = [['when' => ['any_reply' => true], 'then' => ['action' => 'go_to', 'stage' => 'dudas']]];

        $cycles = array_values(array_filter(Checks::warnings($this->product, $this->base), fn (string $warning) => str_starts_with($warning, 'Ciclo entre etapas')));

        expect($cycles)->toHaveCount(1)
            ->and($cycles[0])->toContain('dudas → venta → dudas');
    });

    it('warns of a stage leading to itself', function () {
        $this->base['stages'][1]['rules'] = [['when' => ['any_reply' => true], 'then' => ['action' => 'go_to', 'stage' => 'venta']]];

        expect(implode(' ', Checks::warnings($this->product, $this->base)))->toContain('venta → venta');
    });

    it('does not take a stay rule for a move', function () {
        $this->base['stages'][1]['rules'] = [['when' => ['any_reply' => true], 'then' => ['action' => 'stay', 'stage' => 'dudas']]];

        expect(Checks::warnings($this->product, $this->base))->toBe([]);
    });

    it('warns of rules in message stages, and of those the editor discards', function () {
        $definition = fpubDefinition();
        array_unshift($definition['stages'], ['key' => 'aviso', 'type' => 'message', 'text' => 'x', 'next' => 'dudas', 'rules' => [['when' => ['any_reply' => true], 'then' => ['action' => 'stay']]]]);

        expect(Checks::warnings($this->product, $definition))->toContain('La etapa de mensaje «aviso» tiene reglas: el motor no las evalúa en un mensaje.')
            ->and(Checks::discardedMessageRules($definition))->toBe(['La etapa «aviso» de la versión de origen tenía reglas en un mensaje, donde el motor no las evalúa: no se publicarán.']);

        $this->versions->publish($this->product, $definition);

        fpubPage($this->product)->mountAction('publish')->assertMountedActionModalSee('de la versión de origen tenía reglas en un mensaje');
    });

    it('warns of spending free replies without a stage to go to', function () {
        unset($this->base['free_replies']['on_exhausted']);

        expect(Checks::warnings($this->product, $this->base))->toContain('La etapa «dudas» gasta respuestas gratuitas, pero no hay etapa a la cual pasar al agotarse: el cliente recibirá solo la respuesta comercial del sistema.');
    });

    it('warns of starting a payment without plans', function () {
        $this->base['stages'][0]['rules'][0]['then'] = ['action' => 'start_payment', 'stage' => 'venta'];
        expect(Checks::warnings($this->product, $this->base))->toBe([]);

        Plan::where('product_id', $this->product->id)->update(['is_active' => false]);

        expect(Checks::warnings($this->product->fresh(), $this->base))->toBe(['Hay reglas que inician el pago, pero el producto no tiene planes activos: el cliente no podrá pagar.']);
    });

    it('warns of starting a payment without payment instructions', function () {
        $this->base['stages'][0]['rules'][0]['then'] = ['action' => 'start_payment', 'stage' => 'venta'];
        $this->store->update(['payment_instructions' => null]);

        expect(Checks::warnings($this->product->fresh(), $this->base))->toBe(['Hay reglas que inician el pago, pero la tienda no tiene instrucciones de pago: el cliente no podrá pagar.']);
    });

    it('warns of a funnel on an open product', function () {
        $open = fpubProduct($this->store, ['access_mode' => Product::ACCESS_OPEN]);

        expect(Checks::warnings($open, $this->base))->toBe(['Este producto es de acceso libre: los embudos solo se ejecutan en productos con suscripción, así que este no se aplicará.']);
    });

    it('warns of sample content without sample sources, until there is one', function () {
        $this->base['stages'][0]['context'] = ['description', 'sample_content'];

        expect(Checks::warnings($this->product, $this->base))->toBe(['La etapa «dudas» usa contenido de muestra, pero el producto no tiene fuentes de muestra activas.']);

        app(KnowledgeIngestor::class)->ingest($this->store, 'oracle', $this->product, 'Muestra', 'markdown', "# Muestra\n\nContenido", origin: 'muestra', purpose: KnowledgeSource::PURPOSE_SAMPLE);

        expect(Checks::warnings($this->product, $this->base))->toBe([]);
    });

    it('does not count the sample sources of another product', function () {
        $this->base['stages'][0]['context'] = ['sample_content'];
        app(KnowledgeIngestor::class)->ingest($this->store, 'oracle', fpubProduct($this->store, ['name' => 'Hermano']), 'Muestra', 'markdown', "# Muestra\n\nContenido", origin: 'muestra', purpose: KnowledgeSource::PURPOSE_SAMPLE);

        expect(Checks::warnings($this->product, $this->base))->toHaveCount(1);
    });

    it('never stops a publication the contract allows', function () {
        $this->versions->publish($this->product, fpubDefinition());
        $page = fpubPage($this->product);
        $stages = $page->instance()->data['stages'];
        $stages['nueva'] = ['key' => 'perdida', 'type' => 'ai', 'goal' => 'g', 'instructions' => 'i'];
        $page->set('data.stages', $stages);

        $page->mountAction('publish')->assertMountedActionModalSee('La etapa «perdida» es inalcanzable');

        fpubConfirm(fpubPage($this->product)->set('data.stages', $stages))->assertHasNoActionErrors()->assertNotified('Se publicó y activó la versión v2.');

        expect($this->product->fresh()->activeFunnel()->version)->toBe(2);
    });
});

describe('the impact shown before confirming', function () {
    it('names the version, its immediate effect and what does not reset', function () {
        $this->versions->publish($this->product, fpubDefinition());

        fpubPage($this->product)->mountAction('publish')->assertMountedActionModalSee([
            'Se publicará la versión v2',
            'se activará de inmediato para todos los prospectos',
            'Las conversaciones pueden regresar al inicio si desaparece su etapa',
            'turns_used',
            'no se reinician',
        ]);
    });

    it('lists stages added, removed and changed', function () {
        $definition = fpubDefinition();
        $definition['stages'][] = ['key' => 'vieja', 'type' => 'ai', 'goal' => 'g', 'instructions' => 'i'];
        $this->versions->publish($this->product, $definition);

        $page = fpubPage($this->product);
        $keys = array_keys($page->instance()->data['stages']);
        $stages = $page->instance()->data['stages'];
        unset($stages[$keys[2]]);
        $stages[$keys[1]]['goal'] = 'OTRO';
        $stages['nueva'] = ['key' => 'cierre', 'type' => 'ai', 'goal' => 'g', 'instructions' => 'i'];

        $page->set('data.stages', $stages)->mountAction('publish')->assertMountedActionModalSee([
            'Cambios frente a la versión activa (v1)',
            'Se agrega la etapa «cierre»',
            'Se elimina la etapa «vieja»',
            'Etapa «venta»: cambia objetivo',
        ]);
    });

    it('counts the conversations in the stages that disappear', function () {
        $definition = fpubDefinition();
        $definition['stages'][] = ['key' => 'vieja', 'type' => 'ai', 'goal' => 'g', 'instructions' => 'i'];
        $first = $this->versions->publish($this->product, $definition);
        fpubState($this->product, $first, 'vieja', '573001230001');
        fpubState($this->product, $first, 'vieja', '573001230002');
        fpubState($this->product, $first, 'dudas', '573001230003');

        expect(FunnelPublicationPreview::displacedConversations($this->product, fpubDefinition()))->toBe(['«vieja»: 2 conversación(es), que volverán a la etapa inicial.'])
            ->and(FunnelPublicationPreview::displacedConversations($this->product, $definition))->toBe([]);

        $page = fpubPage($this->product);
        $stages = $page->instance()->data['stages'];
        unset($stages[array_keys($stages)[2]]);

        $page->set('data.stages', $stages)->mountAction('publish')->assertMountedActionModalSee('«vieja»: 2 conversación(es)');
    });

    it('shows the change in the free replies limit', function (int $to, string $expected) {
        $active = $this->versions->publish($this->product, fpubDefinition());

        expect(FunnelPublicationPreview::freeLimitChange($active, fpubDefinition(overrides: ['free_replies' => ['limit' => $to, 'on_exhausted' => 'venta']])))->toBe($expected);
    })->with([
        'the same' => [2, 'Se mantiene en 2.'],
        'lower' => [1, 'Baja de 2 a 1: algunos clientes pueden quedar sin respuestas gratuitas.'],
        'higher' => [5, 'Sube de 2 a 5.'],
    ]);

    it('compares the limit with the global one when no funnel is active', function () {
        config(['botinker.free_interactions.max' => 3]);

        expect(FunnelPublicationPreview::freeLimitChange(null, fpubDefinition()))->toBe('Baja de 3 (el límite global, sin embudo activo) a 2: algunos clientes pueden quedar sin respuestas gratuitas.');
    });

    it('shows the limit in the modal', function () {
        $this->versions->publish($this->product, fpubDefinition());

        fpubPage($this->product)->fillForm(['free_replies.limit' => 1])->mountAction('publish')->assertMountedActionModalSee('Baja de 2 a 1');
    });
});

describe('another publication meanwhile', function () {
    beforeEach(function () {
        $this->versions->publish($this->product, fpubDefinition('Embudo-A'));
    });

    it('does not open the modal, and asks to review', function () {
        $page = fpubPage($this->product)->fillForm(['name' => 'Mi-Trabajo']);
        $this->versions->publish($this->product, fpubDefinition('Embudo-De-Otro'));
        $before = fpubSnapshot($this->product);

        $page->mountAction('publish')
            ->assertActionNotMounted('publish')
            ->assertNotified('El embudo activo cambió mientras editabas. No se publicó nada.');

        expect(fpubSnapshot($this->product))->toEqual($before)
            ->and($this->product->fresh()->activeFunnel()->definition['name'])->toBe('Embudo-De-Otro')
            ->and($page->instance()->data['name'])->toBe('Mi-Trabajo');
    });

    it('catches it after the modal was opened too', function () {
        $page = fpubPage($this->product)->fillForm(['name' => 'Mi-Trabajo'])->mountAction('publish');
        $this->versions->publish($this->product, fpubDefinition('Embudo-De-Otro'));
        $before = fpubSnapshot($this->product);

        $page->setActionData(['confirmed' => true])
            ->callMountedAction()
            ->assertNotified('El embudo activo cambió mientras editabas. No se publicó nada.');

        expect(fpubSnapshot($this->product))->toEqual($before)
            ->and($this->product->fresh()->activeFunnel()->definition['name'])->toBe('Embudo-De-Otro');
    });

    it('catches a deactivation', function () {
        $page = fpubPage($this->product)->fillForm(['name' => 'Mi-Trabajo']);
        $this->versions->deactivate($this->product);
        $before = fpubSnapshot($this->product);

        $page->mountAction('publish')->assertNotified('El embudo activo cambió mientras editabas. No se publicó nada.');

        expect(fpubSnapshot($this->product))->toEqual($before)
            ->and($this->product->fresh()->active_sales_funnel_id)->toBeNull();
    });

    it('catches a first version published by someone else', function () {
        $sibling = fpubProduct($this->store, ['name' => 'Hermano']);
        $page = fpubPage($sibling)->fillForm(['name' => 'Mi-Trabajo', 'goal' => 'g', 'stages' => [['key' => 'a', 'type' => 'ai', 'goal' => 'g', 'instructions' => 'i']]]);
        $this->versions->publish($sibling, fpubDefinition('Embudo-De-Otro'));

        $page->mountAction('publish')->assertNotified('El embudo activo cambió mientras editabas. No se publicó nada.');

        expect(SalesFunnel::where('product_id', $sibling->id)->count())->toBe(1);
    });

    it('publishes after reloading', function () {
        $page = fpubPage($this->product);
        $this->versions->publish($this->product, fpubDefinition('Embudo-De-Otro'));
        $page->mountAction('publish')->assertNotified('El embudo activo cambió mientras editabas. No se publicó nada.');

        fpubConfirm(fpubPage($this->product)->fillForm(['name' => 'Sobre-El-Otro']))->assertNotified('Se publicó y activó la versión v3.');

        expect($this->product->fresh()->activeFunnel()->definition['name'])->toBe('Sobre-El-Otro');
    });

    it('is not a conflict when the same version is still active', function () {
        fpubConfirm(fpubPage($this->product, 1))->assertNotified('Se publicó y activó la versión v2.');
    });
});

describe('a publication that fails', function () {
    beforeEach(function () {
        $this->versions->publish($this->product, fpubDefinition('Embudo-A'));
        $this->before = fpubSnapshot($this->product);
    });

    it('leaves nothing behind when the version cannot be created', function () {
        SalesFunnel::creating(fn () => throw new RuntimeException('falla'));

        fpubConfirm(fpubPage($this->product)->fillForm(['name' => 'Nunca']))
            ->assertNotified('No se pudo publicar. Nada cambió: inténtalo de nuevo.')
            ->assertNoRedirect();

        expect(fpubSnapshot($this->product))->toEqual($this->before);
    });

    it('undoes the version when it fails after creating it', function () {
        SalesFunnel::created(fn () => throw new RuntimeException('falla'));

        fpubConfirm(fpubPage($this->product)->fillForm(['name' => 'Nunca']))
            ->assertNotified('No se pudo publicar. Nada cambió: inténtalo de nuevo.');

        expect(fpubSnapshot($this->product))->toEqual($this->before)
            ->and($this->product->fresh()->activeFunnel()->definition['name'])->toBe('Embudo-A');
    });

    it('leaves nothing behind when the contract refuses it at the last moment', function () {
        SalesFunnel::creating(fn () => throw new InvalidArgumentException('refused'));

        fpubConfirm(fpubPage($this->product)->fillForm(['name' => 'Nunca']))
            ->assertNotified('No se pudo publicar. Nada cambió: inténtalo de nuevo.');

        expect(fpubSnapshot($this->product))->toEqual($this->before);
    });

    it('can be published again afterwards', function () {
        $fails = true;
        SalesFunnel::creating(function () use (&$fails) {
            if ($fails) {
                throw new RuntimeException('falla');
            }
        });

        $page = fpubPage($this->product)->fillForm(['name' => 'Segundo-Intento']);
        fpubConfirm($page)->assertNotified('No se pudo publicar. Nada cambió: inténtalo de nuevo.');

        $fails = false;
        fpubConfirm($page)->assertNotified('Se publicó y activó la versión v2.');

        expect($this->product->fresh()->activeFunnel()->definition['name'])->toBe('Segundo-Intento');
    });
});

describe('another store', function () {
    beforeEach(function () {
        $this->foreignVersion = $this->versions->publish($this->foreignProduct, fpubDefinition('Embudo-Ajeno'));
    });

    it('answers 404 on the editor', function () {
        $this->get(ProductResource::getUrl('funnel-edit', ['record' => $this->foreignProduct]))->assertNotFound();
    });

    it('cannot publish on a foreign product', function () {
        $before = fpubSnapshot($this->foreignProduct);

        expect(fn () => fpubConfirm(fpubPage($this->foreignProduct)))->toThrow(ModelNotFoundException::class);

        expect(fpubSnapshot($this->foreignProduct))->toEqual($before);
    });

    it('leaves other stores alone when publishing', function () {
        $before = fpubSnapshot($this->foreignProduct);

        fpubConfirm(fpubPage($this->product)->fillForm(['name' => 'Propio', 'goal' => 'g', 'stages' => [['key' => 'a', 'type' => 'ai', 'goal' => 'g', 'instructions' => 'i']]]))->assertHasNoActionErrors();

        expect(fpubSnapshot($this->foreignProduct))->toEqual($before);
    });

    it('is open to a super admin, recorded as the author', function () {
        $admin = User::factory()->create(['is_super_admin' => true]);
        $this->actingAs($admin);

        fpubConfirm(fpubPage($this->foreignProduct)->fillForm(['name' => 'Del-Admin']))->assertNotified('Se publicó y activó la versión v2.');

        expect((int) $this->foreignProduct->fresh()->activeFunnel()->created_by)->toBe($admin->id);
    });
});
