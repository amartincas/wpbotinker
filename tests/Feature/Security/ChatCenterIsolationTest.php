<?php

/*
 * WhatsAppChatCenter exposes $filterStoreId and $selectedPhone as public
 * Livewire properties, so any client can set them. Only super admins may
 * act on a store other than their own.
 */

use App\Livewire\WhatsAppChatCenter;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppTemplate;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    fakeExternalApis();
    $this->ownStore = makeStore(['name' => 'Tienda Propia']);
    $this->otherStore = makeStore(['name' => 'Tienda Ajena']);
    $this->operator = User::factory()->create(['store_id' => $this->ownStore->id, 'is_super_admin' => false]);

    $this->foreignTemplate = WhatsAppTemplate::create([
        'store_id' => $this->otherStore->id, 'name' => 'promo_ajena', 'body_preview' => 'Hola',
        'parameters_map' => [], 'language' => 'es_CO', 'type' => 'marketing',
    ]);
});

function metaSendsFrom(string $phoneNumberId): array
{
    return Http::recorded(fn (Request $request) => $request->url() === "https://graph.facebook.com/v20.0/{$phoneNumberId}/messages")
        ->values()->all();
}

it('does not let an operator send a free-form message from another store number', function () {
    Livewire::actingAs($this->operator)
        ->test(WhatsAppChatCenter::class)
        ->set('filterStoreId', $this->otherStore->id)
        ->set('selectedPhone', '573005550000')
        ->set('newMessage', 'Mensaje suplantado')
        ->call('sendMessage');

    expect(metaSendsFrom($this->otherStore->wa_phone_number_id))->toBeEmpty()
        ->and(WhatsAppMessage::where('store_id', $this->otherStore->id)->count())->toBe(0);
});

it('does not let an operator send another store template', function () {
    $component = Livewire::actingAs($this->operator)
        ->test(WhatsAppChatCenter::class)
        ->set('filterStoreId', $this->otherStore->id)
        ->set('selectedPhone', '573005550000');

    // The template is looked up in the operator's own store → not found (404).
    expect(fn () => $component->call('sendTemplate', $this->foreignTemplate->id))
        ->toThrow(ModelNotFoundException::class);

    expect(metaSendsFrom($this->otherStore->wa_phone_number_id))->toBeEmpty();
});

it('does not expose another store templates when selecting a conversation', function () {
    $component = Livewire::actingAs($this->operator)
        ->test(WhatsAppChatCenter::class)
        ->set('filterStoreId', $this->otherStore->id)
        ->call('selectConversation', '573005550000');

    expect(collect($component->get('whatsappTemplates'))->pluck('id')->all())
        ->not->toContain($this->foreignTemplate->id);
});

it('still lets an operator message a customer from their own store number', function () {
    Livewire::actingAs($this->operator)
        ->test(WhatsAppChatCenter::class)
        ->set('selectedPhone', '573005550000')
        ->set('newMessage', 'Hola desde la tienda')
        ->call('sendMessage');

    expect(metaSendsFrom($this->ownStore->wa_phone_number_id))->toHaveCount(1)
        ->and(WhatsAppMessage::where('store_id', $this->ownStore->id)->sole()->content)->toBe('Hola desde la tienda');
});

it('still lets a super admin act on the store selected in the filter', function () {
    $admin = User::factory()->create(['store_id' => null, 'is_super_admin' => true]);

    Livewire::actingAs($admin)
        ->test(WhatsAppChatCenter::class)
        ->set('filterStoreId', $this->otherStore->id)
        ->set('selectedPhone', '573005550000')
        ->set('newMessage', 'Hola desde soporte')
        ->call('sendMessage');

    expect(metaSendsFrom($this->otherStore->wa_phone_number_id))->toHaveCount(1);
});
