<?php

/*
 * Sales funnels in the panel: a product's state in the list, its funnel page
 * and each version, and deactivating and restoring through the Core's
 * service. Each store reaches only its own, and consulting writes nothing.
 */

use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\Pages\ManageProductFunnel;
use App\Filament\Resources\Products\Pages\ViewFunnelVersion;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Products\Schemas\FunnelRestorePreview;
use App\Filament\Resources\Products\Tables\FunnelVersionsTable;
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
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\Domains\OracleTestDomain;

function faProduct(Store $store, array $overrides = []): Product
{
    return Product::create(array_merge([
        'store_id' => $store->id, 'domain_key' => 'oracle', 'name' => 'Asesor', 'description' => 'DESCRIPCION-PRODUCTO', 'price' => 1, 'stock' => 1,
        'access_mode' => Product::ACCESS_SUBSCRIPTION,
    ], $overrides));
}

function faDefinition(string $name = 'Embudo-A', array $overrides = []): array
{
    return array_merge([
        'name' => $name,
        'goal' => 'OBJETIVO-EMBUDO',
        'offer_cta' => 'LLAMADO-OFERTA',
        'free_replies' => ['limit' => 2, 'on_exhausted' => 'venta'],
        'intents' => [['key' => 'comprar', 'description' => 'Quiere adquirir']],
        'stages' => [
            ['key' => 'bienvenida', 'type' => 'message', 'text' => 'TEXTO-LITERAL', 'next' => 'dudas'],
            [
                'key' => 'dudas', 'type' => 'ai', 'goal' => 'OBJETIVO-DUDAS', 'instructions' => 'INSTRUCCIONES-DUDAS', 'restrictions' => 'RESTRICCIONES-DUDAS',
                'context' => ['description', 'sample_content'], 'uses_free_replies' => true, 'max_interactions' => 5, 'on_limit' => 'venta',
                'rules' => [
                    ['when' => ['phrase' => ['Lo quiero', 'Me apunto']], 'then' => ['action' => 'start_payment', 'stage' => 'venta']],
                    ['when' => ['plan_named' => true], 'then' => ['action' => 'start_payment']],
                    ['when' => ['intent' => 'comprar', 'min_confidence' => 0.9], 'then' => ['action' => 'show_offer']],
                    ['when' => ['interactions_reached' => 3], 'then' => ['action' => 'go_to', 'stage' => 'venta']],
                    ['when' => ['free_replies_exhausted' => true], 'then' => ['action' => 'stay']],
                    ['when' => ['any_reply' => true], 'then' => ['action' => 'stay']],
                ],
            ],
            ['key' => 'venta', 'type' => 'ai', 'goal' => 'OBJETIVO-VENTA', 'instructions' => 'INSTRUCCIONES-VENTA'],
        ],
    ], $overrides);
}

function faWrites(callable $consult): array
{
    $writes = [];

    DB::listen(function ($query) use (&$writes) {
        if (! str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
            $writes[] = $query->sql;
        }
    });

    $consult();

    return $writes;
}

beforeEach(function () {
    config(['botinker.domains' => ['oracle' => OracleTestDomain::class]]);

    $this->store = makeStore(['conversation_mode' => Store::MODE_KNOWLEDGE, 'payment_instructions' => 'INSTRUCCIONES-PAGO']);
    $this->other = makeStore(['conversation_mode' => Store::MODE_KNOWLEDGE]);
    $this->product = faProduct($this->store);
    $this->foreignProduct = faProduct($this->other, ['name' => 'Ajeno']);
    $this->operator = User::factory()->create(['store_id' => $this->store->id, 'is_super_admin' => false, 'name' => 'Operadora Uno']);
    $this->versions = app(SalesFunnelVersions::class);

    $this->actingAs($this->operator);
});

describe('the product list', function () {
    beforeEach(function () {
        $this->withFunnel = faProduct($this->store, ['name' => 'Con embudo']);
        $this->versions->publish($this->withFunnel, faDefinition());
        $this->versions->publish($this->withFunnel, faDefinition('Embudo-B'));
        $this->openProduct = faProduct($this->store, ['name' => 'Libre', 'access_mode' => Product::ACCESS_OPEN]);
    });

    it('shows the state of each product', function () {
        Livewire::test(ListProducts::class)
            ->assertTableColumnStateSet('funnel_status', 'v2 activo', $this->withFunnel)
            ->assertTableColumnStateSet('funnel_status', 'Sin embudo', $this->product)
            ->assertTableColumnStateSet('funnel_status', 'No aplica', $this->openProduct);
    });

    it('does not present a funnel as running on an open product', function () {
        $this->versions->publish($this->openProduct, faDefinition());

        Livewire::test(ListProducts::class)->assertTableColumnStateSet('funnel_status', 'No aplica', $this->openProduct);
    });

    it('filters by that state', function () {
        Livewire::test(ListProducts::class)
            ->filterTable('funnel', 'active')
            ->assertCanSeeTableRecords([$this->withFunnel])
            ->assertCanNotSeeTableRecords([$this->product, $this->openProduct]);

        Livewire::test(ListProducts::class)
            ->filterTable('funnel', 'none')
            ->assertCanSeeTableRecords([$this->product])
            ->assertCanNotSeeTableRecords([$this->withFunnel, $this->openProduct]);

        Livewire::test(ListProducts::class)
            ->filterTable('funnel', 'not_applicable')
            ->assertCanSeeTableRecords([$this->openProduct])
            ->assertCanNotSeeTableRecords([$this->withFunnel, $this->product]);
    });

    it('links to the funnel page', function () {
        Livewire::test(ListProducts::class)
            ->assertSee(ProductResource::getUrl('funnel', ['record' => $this->withFunnel]));
    });

    it('never lists another store', function () {
        $this->versions->publish($this->foreignProduct, faDefinition());

        Livewire::test(ListProducts::class)
            ->filterTable('funnel', 'active')
            ->assertCanNotSeeTableRecords([$this->foreignProduct]);
    });
});

describe('the funnel page', function () {
    it('says when the product has no funnel', function () {
        $this->get(ProductResource::getUrl('funnel', ['record' => $this->product]))
            ->assertOk()
            ->assertSee('Este producto funciona sin embudo.')
            ->assertSee('Este producto aún no tiene versiones publicadas');
    });

    it('says a funnel does not run on an open product', function () {
        $open = faProduct($this->store, ['access_mode' => Product::ACCESS_OPEN]);

        $this->get(ProductResource::getUrl('funnel', ['record' => $open]))
            ->assertOk()
            ->assertSee('los embudos solo se ejecutan en productos con suscripción');
    });

    it('summarizes the active version', function () {
        $this->versions->publish($this->product, faDefinition(), $this->operator->id);
        $this->versions->publish($this->product, faDefinition('Embudo-B'), $this->operator->id);

        $this->get(ProductResource::getUrl('funnel', ['record' => $this->product]))
            ->assertOk()
            ->assertSee('Embudo-B')
            ->assertSee('OBJETIVO-EMBUDO')
            ->assertSee('Operadora Uno')
            ->assertSee('bienvenida')
            ->assertSee(ProductResource::getUrl('funnel-version', ['record' => $this->product, 'version' => 2]));
    });

    it('counts conversations by stage, saying which ones the active version lost', function () {
        $definition = faDefinition('Embudo-A');
        $definition['stages'][] = ['key' => 'retirada', 'type' => 'ai', 'goal' => 'x', 'instructions' => 'x'];
        $first = $this->versions->publish($this->product, $definition);
        $this->versions->publish($this->product, faDefinition('Embudo-B'));

        foreach (['dudas' => 2, 'retirada' => 1] as $stage => $total) {
            foreach (range(1, $total) as $i) {
                $conversation = Conversation::create(['store_id' => $this->store->id, 'customer_phone' => "57300{$stage}{$i}", 'last_session_at' => now()]);
                ConversationFunnelState::create(['conversation_id' => $conversation->id, 'product_id' => $this->product->id, 'sales_funnel_id' => $first->id, 'stage' => $stage]);
            }
        }

        $this->get(ProductResource::getUrl('funnel', ['record' => $this->product]))
            ->assertSee('dudas: 2')
            ->assertSee('retirada: 1 (esta versión no tiene esa etapa: vuelven al inicio)');
    });

    it('shows what the funnel depends on, with links to configure it', function () {
        $this->product->update(['opening_message' => 'MENSAJE-INICIAL', 'opening_triggers' => ['Quiero saber']]);
        Plan::create(['product_id' => $this->product->id, 'name' => 'Mensual', 'price' => 20000, 'duration_days' => 30]);

        $ingest = fn (?Product $product, string $purpose, string $title) => app(KnowledgeIngestor::class)
            ->ingest($this->store, 'oracle', $product, $title, 'markdown', "# {$title}\n\nContenido", origin: $title, purpose: $purpose);

        $ingest($this->product, KnowledgeSource::PURPOSE_COMMERCIAL, 'Comercial propia');
        $ingest(null, KnowledgeSource::PURPOSE_COMMERCIAL, 'Comercial del tema');
        $ingest(faProduct($this->store, ['name' => 'Hermano']), KnowledgeSource::PURPOSE_COMMERCIAL, 'Comercial de otro producto');
        $ingest($this->product, KnowledgeSource::PURPOSE_SAMPLE, 'Muestra propia');
        KnowledgeSource::where('title', 'Muestra propia')->sole()->update(['status' => KnowledgeSource::STATUS_RETIRED]);

        $this->get(ProductResource::getUrl('funnel', ['record' => $this->product]))
            ->assertSee('MENSAJE-INICIAL')
            ->assertSee('Quiero saber')
            ->assertSee('Configuradas')
            ->assertSeeInOrder(['Planes activos', '1', 'Instrucciones de pago de la tienda'])
            ->assertSeeInOrder(['Fuentes comerciales activas', '2', 'Fuentes de muestra activas', '0'])
            ->assertSee(ProductResource::getUrl('edit', ['record' => $this->product]));
    });

    it('lists every version, newest first, with its author and origin', function () {
        $first = $this->versions->publish($this->product, faDefinition('Embudo-A'), $this->operator->id);
        $second = $this->versions->publish($this->product, faDefinition('Embudo-B'));
        $third = $this->versions->restore($first, $this->operator->id);

        Livewire::test(ManageProductFunnel::class, ['record' => $this->product->getRouteKey()])
            ->assertCanSeeTableRecords([$third, $second, $first], inOrder: true)
            ->assertTableColumnStateSet('status', 'Activa', $third)
            ->assertTableColumnStateSet('status', 'Anterior', $first)
            ->assertTableColumnStateSet('name', 'Embudo-B', $second)
            ->assertTableColumnStateSet('creator.name', 'Operadora Uno', $first)
            ->assertTableColumnStateSet('restoredFrom.version', 1, $third);
    });

    it('offers no way to edit or delete a version', function () {
        $version = $this->versions->publish($this->product, faDefinition());

        Livewire::test(ManageProductFunnel::class, ['record' => $this->product->getRouteKey()])
            ->assertTableActionExists('viewVersion', record: $version)
            ->assertTableActionDoesNotExist('edit')
            ->assertTableActionDoesNotExist('delete')
            ->assertTableBulkActionDoesNotExist('delete')
            ->assertActionDoesNotExist('publish');
    });
});

describe('a version', function () {
    beforeEach(function () {
        $this->product->update(['opening_message' => 'INICIAL-AL-PUBLICAR']);
        $this->first = $this->versions->publish($this->product, faDefinition('Embudo-A'), $this->operator->id);
        $this->versions->publish($this->product, faDefinition('Embudo-B'));
        $this->product->update(['opening_message' => 'INICIAL-DESPUES']);
    });

    it('shows a previous version as it was published', function () {
        $this->get(ProductResource::getUrl('funnel-version', ['record' => $this->product, 'version' => 1]))
            ->assertOk()
            ->assertSee('Anterior')
            ->assertSee('Embudo-A')
            ->assertSee('OBJETIVO-EMBUDO')
            ->assertSee('LLAMADO-OFERTA')
            ->assertSee('comprar: Quiere adquirir')
            ->assertSee('TEXTO-LITERAL')
            ->assertSee('OBJETIVO-DUDAS')
            ->assertSee('INSTRUCCIONES-DUDAS')
            ->assertSee('RESTRICCIONES-DUDAS')
            ->assertSee('Contenido de muestra')
            ->assertSee('INICIAL-AL-PUBLICAR')
            ->assertDontSee('INICIAL-DESPUES')
            ->assertSee('Operadora Uno');
    });

    it('puts every condition and action in the panel\'s words', function () {
        $page = $this->get(ProductResource::getUrl('funnel-version', ['record' => $this->product, 'version' => 1]));

        foreach ([
            'El mensaje es exactamente «Lo quiero» o «Me apunto»: iniciar el pago y seguir en «venta».',
            'El cliente elige un plan por su nombre: iniciar el pago.',
            'Intención «comprar» con confianza de al menos 0.9: mostrar la oferta.',
            'Al llegar a 3 interacciones: ir a la etapa «venta».',
            'Se agotaron las respuestas gratis: permanecer en la etapa.',
            'Después de cualquier respuesta: permanecer en la etapa.',
        ] as $line) {
            $page->assertSee($line, escape: true);
        }
    });

    it('shows configured text as text, never as markup', function () {
        $this->versions->publish($this->product, faDefinition('<script>alert(1)</script>'));

        $this->get(ProductResource::getUrl('funnel-version', ['record' => $this->product, 'version' => 3]))
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false);
    });

    it('can still be consulted if its definition no longer validates', function () {
        DB::table('sales_funnels')->where('id', $this->first->id)->update(['definition' => json_encode(['name' => 'Antigua', 'stages' => [['key' => 'x', 'type' => 'otro']]])]);

        $this->get(ProductResource::getUrl('funnel-version', ['record' => $this->product, 'version' => 1]))
            ->assertOk()
            ->assertSee('Antigua');
    });

    it('is not found under another product of the store', function () {
        $sibling = faProduct($this->store, ['name' => 'Hermano']);

        $this->get(ProductResource::getUrl('funnel-version', ['record' => $sibling, 'version' => 1]))->assertNotFound();
    });

    it('is not found when it does not exist', function () {
        $this->get(ProductResource::getUrl('funnel-version', ['record' => $this->product, 'version' => 99]))->assertNotFound();
        $this->get(ProductResource::getUrl('funnel', ['record' => $this->product]).'/nada')->assertNotFound();
    });
});

describe('another store', function () {
    beforeEach(function () {
        $this->versions->publish($this->foreignProduct, faDefinition('Embudo-Ajeno'));
    });

    it('answers 404 to an operator on the funnel page and the version', function () {
        $this->get(ProductResource::getUrl('funnel', ['record' => $this->foreignProduct]))->assertNotFound();
        $this->get(ProductResource::getUrl('funnel-version', ['record' => $this->foreignProduct, 'version' => 1]))->assertNotFound();
    });

    it('cannot be reached through an own product with the same version number', function () {
        $this->versions->publish($this->product, faDefinition('Embudo-Propio'));

        $this->get(ProductResource::getUrl('funnel-version', ['record' => $this->product, 'version' => 1]))
            ->assertOk()
            ->assertSee('Embudo-Propio')
            ->assertDontSee('Embudo-Ajeno');
    });

    it('is visible to a super admin', function () {
        $this->actingAs(User::factory()->create(['is_super_admin' => true]));

        $this->get(ProductResource::getUrl('funnel', ['record' => $this->foreignProduct]))->assertOk()->assertSee('Embudo-Ajeno');
        $this->get(ProductResource::getUrl('funnel-version', ['record' => $this->foreignProduct, 'version' => 1]))->assertOk()->assertSee('Embudo-Ajeno');
    });
});

it('writes nothing while consulting', function () {
    $this->product->refresh();
    $first = $this->versions->publish($this->product, faDefinition('Embudo-A'), $this->operator->id);
    $this->versions->restore($first, $this->operator->id);

    $before = [SalesFunnel::count(), $this->product->fresh()->active_sales_funnel_id, $this->product->fresh()->updated_at->toIso8601String()];

    $writes = faWrites(function () {
        $this->get(ProductResource::getUrl('index'))->assertOk();
        $this->get(ProductResource::getUrl('funnel', ['record' => $this->product]))->assertOk();
        $this->get(ProductResource::getUrl('funnel-version', ['record' => $this->product, 'version' => 1]))->assertOk();
        Livewire::test(ManageProductFunnel::class, ['record' => $this->product->getRouteKey()]);
        Livewire::test(ViewFunnelVersion::class, ['record' => $this->product->getRouteKey(), 'version' => 1]);
    });

    expect($writes)->toBe([])
        ->and([SalesFunnel::count(), $this->product->fresh()->active_sales_funnel_id, $this->product->fresh()->updated_at->toIso8601String()])->toBe($before);
});

describe('the store filter of the product list', function () {
    beforeEach(function () {
        $this->store->update(['name' => 'Tienda-Propia']);
        $this->other->update(['name' => 'Tienda-Ajena']);
    });

    it('lets a super admin filter by any store', function () {
        $this->actingAs(User::factory()->create(['is_super_admin' => true]));

        // A store without products can only appear among the filter's options.
        makeStore(['name' => 'Tienda-Sin-Productos']);

        Livewire::test(ListProducts::class)
            ->assertTableFilterVisible('store_id')
            ->assertSee('Tienda-Sin-Productos');

        Livewire::test(ListProducts::class)
            ->filterTable('store_id', $this->other->id)
            ->assertCanSeeTableRecords([$this->foreignProduct])
            ->assertCanNotSeeTableRecords([$this->product]);

        Livewire::test(ListProducts::class)
            ->filterTable('store_id', $this->store->id)
            ->assertCanSeeTableRecords([$this->product])
            ->assertCanNotSeeTableRecords([$this->foreignProduct]);
    });

    it('is not offered to an operator, nor does it name other stores', function () {
        makeStore(['name' => 'Tienda-Sin-Productos']);

        Livewire::test(ListProducts::class)
            ->assertTableFilterHidden('store_id')
            ->assertDontSee('Tienda-Ajena')
            ->assertDontSee('Tienda-Sin-Productos');

        $this->get(ProductResource::getUrl('index'))->assertOk()->assertDontSee('Tienda-Ajena');
    });

    it('keeps an operator on their own products, even asking for another store', function () {
        Livewire::test(ListProducts::class)
            ->assertCanSeeTableRecords([$this->product])
            ->assertCanNotSeeTableRecords([$this->foreignProduct]);

        Livewire::test(ListProducts::class)
            ->set('tableFilters.store_id.value', $this->other->id)
            ->assertCanNotSeeTableRecords([$this->foreignProduct])
            ->assertDontSee('Tienda-Ajena');

        $this->get(ProductResource::getUrl('index', ['filters' => ['store_id' => ['value' => $this->other->id]]]))
            ->assertOk()
            ->assertDontSee('Ajeno')
            ->assertDontSee('Tienda-Ajena');
    });
});

function faSample(Product $product): SampleDelivery
{
    $customer = Customer::create(['store_id' => $product->store_id, 'customer_phone' => '573009998877']);

    return SampleDelivery::create(['customer_id' => $customer->id, 'product_id' => $product->id, 'turns_used' => 1]);
}

describe('deactivating', function () {
    beforeEach(function () {
        $this->first = $this->versions->publish($this->product, faDefinition('Embudo-A'), $this->operator->id);
        $this->second = $this->versions->publish($this->product, faDefinition('Embudo-B'), $this->operator->id);
    });

    it('is offered only with an active funnel', function () {
        Livewire::test(ManageProductFunnel::class, ['record' => $this->product->getRouteKey()])->assertActionVisible('deactivate');

        $this->versions->deactivate($this->product);

        Livewire::test(ManageProductFunnel::class, ['record' => $this->product->getRouteKey()])->assertActionHidden('deactivate');
    });

    it('warns of what it does before confirming', function () {
        config(['botinker.free_interactions.max' => 3]);

        Livewire::test(ManageProductFunnel::class, ['record' => $this->product->getRouteKey()])
            ->mountAction('deactivate')
            ->assertMountedActionModalSee(['quedará sin embudo activo', 'límite global de respuestas gratuitas (3)', 'Todas las versiones se conservan']);
    });

    it('warns that conversations keep their state and may resume', function () {
        Livewire::test(ManageProductFunnel::class, ['record' => $this->product->getRouteKey()])
            ->mountAction('deactivate')
            ->assertMountedActionModalSee([
                'Las conversaciones existentes conservan su estado y progreso',
                'si después se reactiva un embudo compatible, podrán retomar su etapa anterior',
            ]);
    });

    it('leaves the state of a conversation as it was', function () {
        $conversation = Conversation::create(['store_id' => $this->store->id, 'customer_phone' => '573001230000', 'last_session_at' => now()]);
        $state = ConversationFunnelState::create(['conversation_id' => $conversation->id, 'product_id' => $this->product->id, 'sales_funnel_id' => $this->second->id, 'stage' => 'dudas', 'stage_interactions' => 2]);

        Livewire::test(ManageProductFunnel::class, ['record' => $this->product->getRouteKey()])->callAction('deactivate');

        expect($state->fresh()->stage)->toBe('dudas')
            ->and($state->fresh()->stage_interactions)->toBe(2);
    });

    it('leaves the product without a funnel and keeps every version', function () {
        $sample = faSample($this->product);

        Livewire::test(ManageProductFunnel::class, ['record' => $this->product->getRouteKey()])
            ->callAction('deactivate')
            ->assertHasNoActionErrors()
            ->assertRedirect(ProductResource::getUrl('funnel', ['record' => $this->product]));

        expect($this->product->fresh()->active_sales_funnel_id)->toBeNull()
            ->and(SalesFunnel::where('product_id', $this->product->id)->pluck('id')->sort()->values()->all())->toBe([$this->first->id, $this->second->id])
            ->and($this->second->fresh()->definition['name'])->toBe('Embudo-B')
            ->and($sample->fresh()->turns_used)->toBe(1);
    });

    it('logs who deactivated what and when', function () {
        $this->freezeSecond();
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
            if ($event->message === 'FUNNEL_DEACTIVATED') {
                $logged[] = $event->context;
            }
        });

        Livewire::test(ManageProductFunnel::class, ['record' => $this->product->getRouteKey()])->callAction('deactivate');

        expect($logged)->toBe([[
            'user_id' => $this->operator->id,
            'store_id' => $this->store->id,
            'product_id' => $this->product->id,
            'version' => 2,
            'at' => now()->toIso8601String(),
        ]]);
    });
});

describe('restoring', function () {
    beforeEach(function () {
        $this->first = $this->versions->publish($this->product, faDefinition('Embudo-A'), $this->operator->id);
        $this->second = $this->versions->publish($this->product, faDefinition('Embudo-B', ['free_replies' => ['limit' => 5, 'on_exhausted' => 'venta']]));
    });

    it('is offered for previous versions, not for the one running', function () {
        Livewire::test(ManageProductFunnel::class, ['record' => $this->product->getRouteKey()])
            ->assertActionVisible(TestAction::make('restore')->table($this->first))
            ->assertActionHidden(TestAction::make('restore')->table($this->second));
    });

    it('is offered for the last active one once the funnel was deactivated', function () {
        $this->versions->deactivate($this->product);

        Livewire::test(ManageProductFunnel::class, ['record' => $this->product->getRouteKey()])
            ->assertActionVisible(TestAction::make('restore')->table($this->second))
            ->mountAction(TestAction::make('restore')->table($this->second))
            ->assertMountedActionModalSee('no tiene embudo activo');
    });

    it('is not offered for a version the engine would reject', function () {
        DB::table('sales_funnels')->where('id', $this->first->id)->update(['definition' => json_encode(['name' => 'Antigua', 'stages' => [['key' => 'x', 'type' => 'otro']]])]);

        Livewire::test(ManageProductFunnel::class, ['record' => $this->product->getRouteKey()])
            ->assertActionHidden(TestAction::make('restore')->table($this->first));
    });

    it('says why a version cannot be restored, and it can still be consulted', function () {
        DB::table('sales_funnels')->where('id', $this->first->id)->update(['definition' => json_encode(['name' => 'Antigua', 'stages' => [['key' => 'x', 'type' => 'otro']]])]);

        Livewire::test(ManageProductFunnel::class, ['record' => $this->product->getRouteKey()])
            ->assertCanSeeTableRecords([$this->first, $this->second])
            ->assertSee('No se puede restaurar: su definición no supera la validación actual.')
            ->assertActionVisible(TestAction::make('viewVersion')->table($this->first))
            ->assertActionHasUrl(TestAction::make('viewVersion')->table($this->first), ProductResource::getUrl('funnel-version', ['record' => $this->product, 'version' => 1]));

        $this->get(ProductResource::getUrl('funnel-version', ['record' => $this->product, 'version' => 1]))->assertOk()->assertSee('Antigua');
    });

    it('says nothing of the kind about versions that validate', function () {
        Livewire::test(ManageProductFunnel::class, ['record' => $this->product->getRouteKey()])
            ->assertDontSee('No se puede restaurar');
    });

    it('shows what changes and warns before confirming', function () {
        $page = Livewire::test(ManageProductFunnel::class, ['record' => $this->product->getRouteKey()])
            ->mountAction(TestAction::make('restore')->table($this->first));

        $page->assertMountedActionModalSee([
            'Se creará la versión v3 como copia de la v1',
            'se activará de inmediato',
            'Las versiones anteriores no cambian',
            'Diferencias con la versión activa (v2)',
            'Nombre: «Embudo-B» → «Embudo-A»',
            'El límite de respuestas gratis baja de 5 a 2',
        ]);
    });

    it('lists the relevant differences', function () {
        $active = faDefinition('Embudo-B');
        $restored = faDefinition('Embudo-A', [
            'goal' => 'OTRO-OBJETIVO',
            'free_replies' => ['limit' => 1, 'on_exhausted' => 'venta'],
            'intents' => [['key' => 'comprar', 'description' => 'Otra descripción'], ['key' => 'dudar', 'description' => 'Duda']],
        ]);
        $restored['stages'][1]['instructions'] = 'OTRAS';
        $restored['stages'][1]['rules'] = [];
        $restored['stages'][2] = ['key' => 'cierre', 'type' => 'ai', 'goal' => 'x', 'instructions' => 'x'];

        expect(FunnelRestorePreview::differences($active, $restored))->toBe([
            'Nombre: «Embudo-B» → «Embudo-A»',
            'Objetivo: «OBJETIVO-EMBUDO» → «OTRO-OBJETIVO»',
            'Respuestas gratis: «2» → «1»',
            'Se agrega la intención «dudar»',
            'Cambia la descripción de la intención «comprar»',
            'Se agrega la etapa «cierre»',
            'Se elimina la etapa «venta»',
            'Etapa «dudas»: cambia instrucciones, reglas',
        ])->and(FunnelRestorePreview::differences($active, $active))->toBe(['Sin diferencias de configuración.']);
    });

    it('warns of the conversations in a stage the version does not have', function () {
        $definition = faDefinition('Embudo-C');
        $definition['stages'][] = ['key' => 'nueva', 'type' => 'ai', 'goal' => 'x', 'instructions' => 'x'];
        $third = $this->versions->publish($this->product, $definition);
        $conversation = Conversation::create(['store_id' => $this->store->id, 'customer_phone' => '573001230000', 'last_session_at' => now()]);
        ConversationFunnelState::create(['conversation_id' => $conversation->id, 'product_id' => $this->product->id, 'sales_funnel_id' => $third->id, 'stage' => 'nueva']);

        Livewire::test(ManageProductFunnel::class, ['record' => $this->product->getRouteKey()])
            ->mountAction(TestAction::make('restore')->table($this->first))
            ->assertMountedActionModalSee('1 conversación(es) están en etapas que esta versión no tiene');
    });

    it('creates a new active version with its origin and author, changing no other', function () {
        $sample = faSample($this->product);
        $this->product->update(['opening_message' => 'INICIAL-ACTUAL']);
        $history = SalesFunnel::orderBy('id')->get()->map->only(['id', 'version', 'definition', 'product_snapshot', 'restored_from_id', 'created_by', 'published_at'])->all();

        Livewire::test(ManageProductFunnel::class, ['record' => $this->product->getRouteKey()])
            ->callAction(TestAction::make('restore')->table($this->first))
            ->assertHasNoActionErrors()
            ->assertRedirect(ProductResource::getUrl('funnel', ['record' => $this->product]));

        $restored = SalesFunnel::where('product_id', $this->product->id)->orderByDesc('version')->first();

        expect($restored->version)->toBe(3)
            ->and((int) $restored->restored_from_id)->toBe($this->first->id)
            ->and((int) $restored->created_by)->toBe($this->operator->id)
            ->and($restored->definition)->toBe($this->first->definition)
            ->and($restored->product_snapshot['opening_message'])->toBe('INICIAL-ACTUAL')
            ->and((int) $this->product->fresh()->active_sales_funnel_id)->toBe($restored->id)
            ->and(SalesFunnel::where('id', '!=', $restored->id)->orderBy('id')->get()->map->only(['id', 'version', 'definition', 'product_snapshot', 'restored_from_id', 'created_by', 'published_at'])->all())->toEqual($history)
            ->and($sample->fresh()->turns_used)->toBe(1);
    });

    it('leaves exactly one version active', function () {
        Livewire::test(ManageProductFunnel::class, ['record' => $this->product->getRouteKey()])
            ->callAction(TestAction::make('restore')->table($this->first));

        $restored = SalesFunnel::where('product_id', $this->product->id)->orderByDesc('version')->first();

        Livewire::test(ManageProductFunnel::class, ['record' => $this->product->getRouteKey()])
            ->assertTableColumnStateSet('status', 'Activa', $restored)
            ->assertTableColumnStateSet('status', 'Anterior', $this->first)
            ->assertTableColumnStateSet('status', 'Anterior', $this->second);
    });

    it('can bring back a deactivated funnel', function () {
        $this->versions->deactivate($this->product);

        Livewire::test(ManageProductFunnel::class, ['record' => $this->product->getRouteKey()])
            ->callAction(TestAction::make('restore')->table($this->second));

        expect($this->product->fresh()->activeFunnel()->version)->toBe(3);
    });

    it('rejects a version of another product of the store', function () {
        $sibling = faProduct($this->store, ['name' => 'Hermano']);
        $siblingVersion = $this->versions->publish($sibling, faDefinition('Embudo-Hermano'));
        $total = SalesFunnel::count();

        expect(fn () => FunnelVersionsTable::restore($this->product, $siblingVersion))->toThrow(NotFoundHttpException::class)
            ->and(SalesFunnel::count())->toBe($total)
            ->and((int) $this->product->fresh()->active_sales_funnel_id)->toBe($this->second->id)
            ->and((int) $sibling->fresh()->active_sales_funnel_id)->toBe($siblingVersion->id);
    });

    it('rejects a version of another store', function () {
        $foreign = $this->versions->publish($this->foreignProduct, faDefinition('Embudo-Ajeno'));
        $total = SalesFunnel::count();

        expect(fn () => FunnelVersionsTable::restore($this->product, $foreign))->toThrow(NotFoundHttpException::class)
            ->and(SalesFunnel::count())->toBe($total)
            ->and((int) $this->foreignProduct->fresh()->active_sales_funnel_id)->toBe($foreign->id);
    });

    it('does not reach a foreign version through the page\'s table', function () {
        $foreign = $this->versions->publish($this->foreignProduct, faDefinition('Embudo-Ajeno'));
        $total = SalesFunnel::count();

        rescue(fn () => Livewire::test(ManageProductFunnel::class, ['record' => $this->product->getRouteKey()])
            ->callAction(TestAction::make('restore')->table($foreign)), report: false);

        expect(SalesFunnel::count())->toBe($total)
            ->and((int) $this->foreignProduct->fresh()->active_sales_funnel_id)->toBe($foreign->id)
            ->and((int) $this->product->fresh()->active_sales_funnel_id)->toBe($this->second->id);
    });
});

describe('an operator of another store', function () {
    beforeEach(function () {
        $this->foreignFirst = $this->versions->publish($this->foreignProduct, faDefinition('Embudo-Ajeno-1'));
        $this->foreignSecond = $this->versions->publish($this->foreignProduct, faDefinition('Embudo-Ajeno-2'));
    });

    it('gets a 404 on the page to deactivate or restore', function () {
        $this->get(ProductResource::getUrl('funnel', ['record' => $this->foreignProduct]))->assertNotFound();
    });

    it('cannot mount the actions on a foreign product', function () {
        expect(fn () => Livewire::test(ManageProductFunnel::class, ['record' => $this->foreignProduct->getRouteKey()])->callAction('deactivate'))
            ->toThrow(ModelNotFoundException::class);

        expect(fn () => Livewire::test(ManageProductFunnel::class, ['record' => $this->foreignProduct->getRouteKey()])->callAction(TestAction::make('restore')->table($this->foreignFirst)))
            ->toThrow(ModelNotFoundException::class);

        expect((int) $this->foreignProduct->fresh()->active_sales_funnel_id)->toBe($this->foreignSecond->id)
            ->and(SalesFunnel::where('product_id', $this->foreignProduct->id)->count())->toBe(2);
    });

    it('leaves a super admin able to act on any store, recording who did it', function () {
        $admin = User::factory()->create(['is_super_admin' => true]);
        $this->actingAs($admin);

        Livewire::test(ManageProductFunnel::class, ['record' => $this->foreignProduct->getRouteKey()])
            ->callAction(TestAction::make('restore')->table($this->foreignFirst));

        expect((int) SalesFunnel::where('product_id', $this->foreignProduct->id)->orderByDesc('version')->first()->created_by)->toBe($admin->id);

        Livewire::test(ManageProductFunnel::class, ['record' => $this->foreignProduct->getRouteKey()])
            ->callAction('deactivate');

        expect($this->foreignProduct->fresh()->active_sales_funnel_id)->toBeNull();
    });
});
