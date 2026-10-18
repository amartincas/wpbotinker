<?php

/*
 * The customer experience texts are configured in the panel: the store's
 * welcome, the product's sample closing and activation message. Each
 * store edits only its own.
 */

use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Stores\Pages\EditStore;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Livewire\Livewire;
use Tests\Support\Domains\OracleTestDomain;

beforeEach(function () {
    config(['botinker.domains' => ['oracle' => OracleTestDomain::class]]);

    $this->store = makeStore(['conversation_mode' => Store::MODE_KNOWLEDGE]);
    $this->other = makeStore(['conversation_mode' => Store::MODE_KNOWLEDGE, 'welcome_message' => 'BIENVENIDA-AJENA']);
    $this->product = cfProduct($this->store);
    $this->foreignProduct = cfProduct($this->other, ['activation_message' => 'ACTIVACION-AJENA']);
    $this->operator = User::factory()->create(['store_id' => $this->store->id, 'is_super_admin' => false]);
});

function cfProduct(Store $store, array $overrides = []): Product
{
    return Product::create(array_merge([
        'store_id' => $store->id, 'domain_key' => 'oracle', 'name' => 'Lectura', 'description' => 'x', 'price' => 1, 'stock' => 1,
        'access_mode' => Product::ACCESS_SUBSCRIPTION,
    ], $overrides));
}

it('saves the store welcome, only for the operator store', function () {
    $this->actingAs($this->operator);

    Livewire::test(EditStore::class, ['record' => $this->store->getRouteKey()])
        ->assertFormFieldExists('welcome_message')
        ->fillForm(['welcome_message' => "¡Hola! Soy el asistente.\nTe cuento qué hago."])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->store->fresh()->welcome_message)->toBe("¡Hola! Soy el asistente.\nTe cuento qué hago.")
        ->and($this->other->fresh()->welcome_message)->toBe('BIENVENIDA-AJENA');
});

it('limits the length of the store welcome', function () {
    $this->actingAs($this->operator);

    Livewire::test(EditStore::class, ['record' => $this->store->getRouteKey()])
        ->fillForm(['welcome_message' => str_repeat('a', 1001)])
        ->call('save')
        ->assertHasFormErrors(['welcome_message' => 'max']);

    expect($this->store->fresh()->welcome_message)->toBeNull();
});

it('saves the product sample closing and activation message', function () {
    $this->actingAs($this->operator);

    Livewire::test(EditProduct::class, ['record' => $this->product->getRouteKey()])
        ->assertFormFieldExists('sample_closing_message')
        ->assertFormFieldExists('activation_message')
        ->fillForm(['sample_closing_message' => 'Cierre de la muestra.', 'activation_message' => "Ya puedes preguntarme.\nEjemplo: «…»"])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->product->fresh())
        ->sample_closing_message->toBe('Cierre de la muestra.')
        ->activation_message->toBe("Ya puedes preguntarme.\nEjemplo: «…»")
        ->and($this->foreignProduct->fresh()->activation_message)->toBe('ACTIVACION-AJENA')
        ->and($this->foreignProduct->fresh()->sample_closing_message)->toBeNull();
});

it('limits the length of the product texts', function () {
    $this->actingAs($this->operator);

    Livewire::test(EditProduct::class, ['record' => $this->product->getRouteKey()])
        ->fillForm(['sample_closing_message' => str_repeat('a', 1001), 'activation_message' => str_repeat('a', 2001)])
        ->call('save')
        ->assertHasFormErrors(['sample_closing_message' => 'max', 'activation_message' => 'max']);
});

it('shows the product texts only for subscription products', function () {
    $open = cfProduct($this->store, ['access_mode' => Product::ACCESS_OPEN]);
    $sales = Product::create(['store_id' => $this->store->id, 'name' => 'Camisa', 'description' => 'x', 'price' => 1, 'stock' => 1]);
    $this->actingAs($this->operator);

    foreach ([$open, $sales] as $product) {
        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertFormFieldHidden('sample_closing_message')
            ->assertFormFieldHidden('activation_message');
    }
});

it('does not let an operator open another store or its products', function () {
    $this->actingAs($this->operator);

    $this->get("/stores/{$this->other->id}/edit")->assertNotFound();
    $this->get("/products/{$this->foreignProduct->id}/edit")->assertNotFound();
});
