<?php

/*
 * Cross-store isolation: an operator of one store must never act on, or
 * receive data from, another store.
 */

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Lead;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Models\WhatsAppTemplate;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->ownStore = makeStore(['name' => 'Tienda Propia']);
    $this->otherStore = makeStore(['name' => 'Tienda Ajena', 'wa_access_token' => 'other-token']);
    $this->operator = User::factory()->create(['store_id' => $this->ownStore->id, 'is_super_admin' => false]);
});

function makeTemplate($store): WhatsAppTemplate
{
    return WhatsAppTemplate::create([
        'store_id' => $store->id, 'name' => 'seguimiento_'.$store->id, 'body_preview' => 'Hola {{1}}',
        'parameters_map' => ['1' => 'customer_name'], 'language' => 'es_CO', 'type' => 'utility',
    ]);
}

function makeLead($store, string $phone = '573009998877'): Lead
{
    return Lead::create(['store_id' => $store->id, 'customer_phone' => $phone, 'customer_name' => 'Cliente', 'summary' => 'Lead']);
}

function metaTemplateSends(): array
{
    return Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/messages')
        && ($request->data()['type'] ?? null) === 'template')->values()->all();
}

describe('POST /whatsapp/templates/send', function () {
    it('refuses to send a template to a lead of another store', function () {
        fakeExternalApis();
        $foreignLead = makeLead($this->otherStore);
        $foreignTemplate = makeTemplate($this->otherStore);

        $this->actingAs($this->operator)
            ->postJson('/whatsapp/templates/send', ['lead_id' => $foreignLead->id, 'template_id' => $foreignTemplate->id])
            ->assertNotFound();

        expect(metaTemplateSends())->toBeEmpty();
    });

    it('still lets an operator send a template to a lead of their own store', function () {
        fakeExternalApis();
        $lead = makeLead($this->ownStore);
        $template = makeTemplate($this->ownStore);

        $this->actingAs($this->operator)
            ->postJson('/whatsapp/templates/send', ['lead_id' => $lead->id, 'template_id' => $template->id])
            ->assertOk()
            ->assertJson(['success' => true]);

        $sends = metaTemplateSends();
        expect($sends)->toHaveCount(1)
            ->and($sends[0][0]->url())->toContain($this->ownStore->wa_phone_number_id);
    });

    it('lets a super admin send a template to a lead of any store', function () {
        fakeExternalApis();
        $admin = User::factory()->create(['store_id' => null, 'is_super_admin' => true]);
        $lead = makeLead($this->otherStore);
        $template = makeTemplate($this->otherStore);

        $this->actingAs($admin)
            ->postJson('/whatsapp/templates/send', ['lead_id' => $lead->id, 'template_id' => $template->id])
            ->assertOk();

        expect(metaTemplateSends())->toHaveCount(1);
    });

    it('rejects guests', function () {
        $this->postJson('/whatsapp/templates/send', [])->assertUnauthorized();
    });
});

describe('[IMG:id] tags in AI replies', function () {
    beforeEach(function () {
        Storage::fake('public');
        Storage::disk('public')->put('products/foreign.jpg', 'fake-image');
        Storage::disk('public')->put('products/own.jpg', 'fake-image');

        $foreignProduct = Product::create(['store_id' => $this->otherStore->id, 'name' => 'Producto Ajeno', 'description' => 'x', 'price' => 1, 'stock' => 1]);
        $this->foreignImage = ProductImage::create(['product_id' => $foreignProduct->id, 'image_path' => 'products/foreign.jpg', 'is_primary' => true]);

        $ownProduct = Product::create(['store_id' => $this->ownStore->id, 'name' => 'Producto Propio', 'description' => 'x', 'price' => 1, 'stock' => 1]);
        $this->ownImage = ProductImage::create(['product_id' => $ownProduct->id, 'image_path' => 'products/own.jpg', 'is_primary' => true]);
    });

    function imageSends(): array
    {
        return Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/messages')
            && ($request->data()['type'] ?? null) === 'image')->values()->all();
    }

    it('never sends an image that belongs to another store', function () {
        fakeExternalApis("Mira esta foto [IMG:{$this->foreignImage->id}]");

        ProcessWhatsAppMessage::dispatchSync($this->ownStore, '573001112233', 'muéstrame una foto', 'wamid.in.img1');

        expect(imageSends())->toBeEmpty()
            ->and(sentWhatsAppTexts()[0]->data()['text']['body'])->toBe('Mira esta foto');
    });

    it('still sends an image of the store own products', function () {
        fakeExternalApis("Mira esta foto [IMG:{$this->ownImage->id}]");

        ProcessWhatsAppMessage::dispatchSync($this->ownStore, '573001112233', 'muéstrame una foto', 'wamid.in.img2');

        $sends = imageSends();
        expect($sends)->toHaveCount(1)
            ->and($sends[0][0]->data()['image']['caption'])->toBe('Producto Propio');
    });
});
