<?php

/*
 * The admin panel speaks Spanish whatever APP_LOCALE says, and only the panel:
 * the rest of the application keeps its locale. And the warnings of a funnel
 * are read in the page, where they do not cover the editor's buttons.
 */

use App\Filament\Resources\Products\Pages\EditProductFunnel;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Http\Middleware\UsePanelLocale;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Platform\Funnel\SalesFunnelVersions;
use Livewire\Livewire;
use Tests\Support\Domains\OracleTestDomain;

function plProduct(Store $store): Product
{
    return Product::create(['store_id' => $store->id, 'domain_key' => 'oracle', 'name' => 'Asesor', 'description' => 'x', 'price' => 1, 'stock' => 1, 'access_mode' => Product::ACCESS_SUBSCRIPTION]);
}

function plDefinition(): array
{
    return [
        'name' => 'Embudo', 'goal' => 'Vender',
        'stages' => [
            ['key' => 'dudas', 'type' => 'ai', 'goal' => 'g', 'instructions' => 'i', 'rules' => [['when' => ['any_reply' => true], 'then' => ['action' => 'go_to', 'stage' => 'venta']]]],
            ['key' => 'venta', 'type' => 'ai', 'goal' => 'g', 'instructions' => 'i', 'rules' => [['when' => ['any_reply' => true], 'then' => ['action' => 'go_to', 'stage' => 'dudas']]]],
            ['key' => 'perdida', 'type' => 'ai', 'goal' => 'g', 'instructions' => 'i'],
        ],
    ];
}

beforeEach(function () {
    config(['botinker.domains' => ['oracle' => OracleTestDomain::class], 'app.locale' => 'en']);
    app()->setLocale('en');

    $this->store = makeStore(['conversation_mode' => Store::MODE_KNOWLEDGE]);
    $this->product = plProduct($this->store);
    $this->operator = User::factory()->create(['store_id' => $this->store->id, 'is_super_admin' => false]);
    $this->actingAs($this->operator);
});

describe('the language of the panel', function () {
    it('is Spanish even with the application in English', function () {
        expect(config('app.locale'))->toBe('en');

        $this->get(ProductResource::getUrl('create'))
            ->assertOk()
            ->assertSee('Crear Producto')
            ->assertSee('Productos')
            ->assertSee('Tiendas')
            ->assertSee('Seleccione una opción')
            ->assertDontSee('Select an option')
            ->assertDontSee('Create another');

        expect(app()->getLocale())->toBe('es');
    });

    it('names the framework\'s texts in Spanish in the list', function () {
        $this->get(ProductResource::getUrl('index'))
            ->assertOk()
            ->assertSee('Crear producto')
            ->assertSee('Nombre')
            ->assertSee('Precio')
            ->assertSee('Existencias')
            ->assertDontSee('>Products<', false);
    });

    it('also applies to Livewire\'s requests', function () {
        expect(Livewire::getPersistentMiddleware())->toContain(UsePanelLocale::class);
    });

    it('does not reach outside the panel', function () {
        $this->get('/api/whatsapp/webhook/cualquiera?hub_mode=subscribe');

        expect(app()->getLocale())->toBe('en');
    });

    it('explains in Spanish the fields without a message of their own', function () {
        app()->setLocale('es');
        $this->actingAs(User::factory()->create(['is_super_admin' => true]));

        $errors = Livewire::test(CreateUser::class)->call('create')->errors()->toArray();
        $messages = collect($errors)->flatten()->implode(' ');

        expect($messages)->toContain('es obligatorio')
            ->not->toContain('field is required')
            ->not->toContain('validation.');
    });
});

describe('the warnings of the last check', function () {
    beforeEach(function () {
        Plan::create(['product_id' => $this->product->id, 'name' => 'Mensual', 'price' => 20000, 'duration_days' => 30]);
        app(SalesFunnelVersions::class)->publish($this->product, plDefinition());
        $this->page = Livewire::test(EditProductFunnel::class, ['record' => $this->product->getRouteKey()]);
    });

    it('are not there before checking', function () {
        $this->page->assertDontSee('Advertencias de configuración');
    });

    it('go in the page, every one of them, and not in a notification', function () {
        $this->page->callAction('checkDefinition')
            ->assertNotified('La definición es válida.')
            ->assertSee('Advertencias de configuración')
            ->assertSee('La etapa «perdida» es inalcanzable')
            ->assertSee('Ciclo entre etapas: dudas → venta → dudas');

        expect($this->page->instance()->configurationWarnings)->toHaveCount(2)
            ->and(collect(session('filament.notifications', []))->pluck('title')->all())->not->toContain('Advertencias de configuración');
    });

    it('leave the buttons available', function () {
        $this->page->callAction('checkDefinition')
            ->assertActionVisible('publish')
            ->assertActionEnabled('publish')
            ->mountAction('publish')
            ->assertActionMounted('publish')
            ->assertMountedActionModalSee('La etapa «perdida» es inalcanzable');
    });

    it('go away once there are none', function () {
        $this->page->callAction('checkDefinition');

        $stages = $this->page->instance()->data['stages'];
        unset($stages[array_keys($stages)[2]]);
        foreach (array_keys($stages) as $key) {
            $stages[$key]['rules'] = [];
        }
        $this->page->set('data.stages', $stages);
        $keys = array_keys($stages);
        $stages[$keys[0]]['rules'] = ['r' => ['condition' => 'any_reply', 'action' => 'go_to', 'stage' => 'venta']];
        $this->page->set('data.stages', $stages)->callAction('checkDefinition');

        expect($this->page->instance()->configurationWarnings)->toBe([]);
        $this->page->assertDontSee('Advertencias de configuración');
    });

    it('do not stay from a check that no longer validates', function () {
        $this->page->callAction('checkDefinition');
        expect($this->page->instance()->configurationWarnings)->not->toBe([]);

        $this->page->fillForm(['name' => ''])->callAction('checkDefinition')->assertHasFormErrors(['name' => 'required']);

        expect($this->page->instance()->configurationWarnings)->toBe([]);
    });
});
