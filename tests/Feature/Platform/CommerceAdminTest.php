<?php

/*
 * C1 — commercial administration in the panel: plans per store, payment
 * instructions and free sample configuration, isolated by store.
 */

use App\Filament\Resources\Plans\Pages\CreatePlan;
use App\Filament\Resources\Plans\Pages\EditPlan;
use App\Filament\Resources\Plans\Pages\ListPlans;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Stores\Pages\EditStore;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Livewire\Livewire;
use Tests\Support\Domains\FinanceTestDomain;
use Tests\Support\Domains\OracleTestDomain;

beforeEach(function () {
    config(['botinker.domains' => ['oracle' => OracleTestDomain::class, 'finance' => FinanceTestDomain::class]]);

    $this->store = makeStore(['conversation_mode' => Store::MODE_KNOWLEDGE]);
    $this->other = makeStore(['conversation_mode' => Store::MODE_KNOWLEDGE]);
    $this->product = adminProduct($this->store);
    $this->foreignProduct = adminProduct($this->other);
    $this->operator = User::factory()->create(['store_id' => $this->store->id, 'is_super_admin' => false]);
});

function adminProduct(Store $store, array $overrides = []): Product
{
    return Product::create(array_merge([
        'store_id' => $store->id, 'domain_key' => 'oracle', 'name' => 'Lectura', 'description' => 'x', 'price' => 1, 'stock' => 1,
        'required_facts' => ['birth_date'], 'capability_key' => 'oracle.lucky_number', 'access_mode' => Product::ACCESS_SUBSCRIPTION,
    ], $overrides));
}

describe('plans', function () {
    it('are listed only for the operator store', function () {
        $own = Plan::create(['product_id' => $this->product->id, 'name' => 'Mensual', 'price' => 20000]);
        $foreign = Plan::create(['product_id' => $this->foreignProduct->id, 'name' => 'Ajeno', 'price' => 10000]);

        $this->actingAs($this->operator);

        Livewire::test(ListPlans::class)->assertCanSeeTableRecords([$own])->assertCanNotSeeTableRecords([$foreign]);
    });

    it('are created for a subscription product of the operator store', function () {
        $this->actingAs($this->operator);

        Livewire::test(CreatePlan::class)
            ->fillForm(['product_id' => $this->product->id, 'name' => 'Mensual', 'price' => 25000, 'currency' => 'cop', 'duration_days' => 30])
            ->call('create')
            ->assertHasNoFormErrors();

        expect(Plan::sole())
            ->store_id->toBe($this->store->id)
            ->price->toBe('25000.00')
            ->currency->toBe('COP')
            ->duration_days->toBe(30);
    });

    it('cannot be attached to another store product or to an open product', function (Closure $product) {
        $this->actingAs($this->operator);

        Livewire::test(CreatePlan::class)
            ->fillForm(['product_id' => $product->call($this)->id, 'name' => 'X', 'price' => 1000, 'currency' => 'COP', 'duration_days' => 30])
            ->call('create')
            ->assertHasFormErrors(['product_id']);

        expect(Plan::count())->toBe(0);
    })->with([
        'another store' => [fn () => $this->foreignProduct],
        'open product' => [fn () => adminProduct($this->store, ['name' => 'Libre', 'access_mode' => Product::ACCESS_OPEN])],
    ]);

    it('validate price and duration in the form', function () {
        $this->actingAs($this->operator);

        Livewire::test(CreatePlan::class)
            ->fillForm(['product_id' => $this->product->id, 'name' => 'X', 'price' => 0, 'currency' => 'COP', 'duration_days' => 0])
            ->call('create')
            ->assertHasFormErrors(['price', 'duration_days']);
    });

    it('of another store cannot be opened', function () {
        $foreign = Plan::create(['product_id' => $this->foreignProduct->id, 'name' => 'Ajeno', 'price' => 10000]);

        $this->actingAs($this->operator)->get("/plans/{$foreign->id}/edit")->assertNotFound();
    });

    it('can be edited and deactivated by their store', function () {
        $plan = Plan::create(['product_id' => $this->product->id, 'name' => 'Mensual', 'price' => 20000]);
        $this->actingAs($this->operator);

        Livewire::test(EditPlan::class, ['record' => $plan->getRouteKey()])
            ->fillForm(['price' => 30000, 'is_active' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($plan->fresh())->price->toBe('30000.00')->is_active->toBeFalse();
    });

    it('are visible to a super admin across stores', function () {
        $own = Plan::create(['product_id' => $this->product->id, 'name' => 'Mensual', 'price' => 20000]);
        $foreign = Plan::create(['product_id' => $this->foreignProduct->id, 'name' => 'Ajeno', 'price' => 10000]);

        $this->actingAs(User::factory()->create(['store_id' => null, 'is_super_admin' => true]));

        Livewire::test(ListPlans::class)->assertCanSeeTableRecords([$own, $foreign]);
    });
});

describe('payment instructions', function () {
    it('are configured per store', function () {
        $this->actingAs($this->operator);

        Livewire::test(EditStore::class, ['record' => $this->store->getRouteKey()])
            ->fillForm(['payment_instructions' => "Nequi: 3000000000\nLlave: @tienda"])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($this->store->fresh()->payment_instructions)->toBe("Nequi: 3000000000\nLlave: @tienda")
            ->and($this->other->fresh()->payment_instructions)->toBeNull();
    });
});

describe('free sample', function () {
    it('is configured on a domain product with instructions and a registered capability', function () {
        $this->actingAs($this->operator);

        Livewire::test(EditProduct::class, ['record' => $this->product->getRouteKey()])
            ->assertFormFieldExists('sample_instructions')
            ->fillForm(['sample_instructions' => 'Muestra breve según las instrucciones del producto.', 'sample_capability_key' => 'oracle.lucky_number'])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($this->product->fresh())
            ->sample_instructions->toBe('Muestra breve según las instrucciones del producto.')
            ->sample_capability_key->toBe('oracle.lucky_number')
            ->offersSample()->toBeTrue();
    });

    it('rejects a capability the product cannot use, and a capability without instructions', function (array $data, string $field) {
        $this->actingAs($this->operator);

        Livewire::test(EditProduct::class, ['record' => $this->product->getRouteKey()])
            ->fillForm($data)
            ->call('save')
            ->assertHasFormErrors([$field]);

        expect($this->product->fresh()->sample_capability_key)->toBeNull();
    })->with([
        'capability of another domain' => [['sample_instructions' => 'Muestra', 'sample_capability_key' => 'finance.savings_target'], 'sample_capability_key'],
        'without instructions' => [['sample_instructions' => null, 'sample_capability_key' => 'oracle.lucky_number'], 'sample_instructions'],
    ]);

    it('is not shown for sales products', function () {
        $sales = Product::create(['store_id' => $this->store->id, 'name' => 'Camisa', 'description' => 'x', 'price' => 1, 'stock' => 1]);
        $this->actingAs($this->operator);

        Livewire::test(EditProduct::class, ['record' => $sales->getRouteKey()])
            ->assertFormFieldHidden('access_mode')
            ->assertFormFieldHidden('sample_instructions');
    });
});
