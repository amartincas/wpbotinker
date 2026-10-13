<?php

/*
 * Characterization tests for the existing commercial (sales) flow in
 * App\Jobs\ProcessWhatsAppMessage. They pin down what the flow does TODAY
 * — prompt construction, product selection, lead creation and reply
 * sending — so later changes can prove the sales flow did not move.
 * They describe current behavior, not necessarily desired behavior.
 */

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Product;
use App\Models\WhatsAppMessage;

const CUSTOMER = '573001112233';

beforeEach(function () {
    $this->store = makeStore();

    $this->cream = Product::create([
        'store_id' => $this->store->id,
        'name' => 'Crema Facial',
        'description' => 'Crema hidratante de uso diario',
        'price' => 45000,
        'stock' => 10,
        'type' => 'product',
        'ai_sales_strategy' => 'Destaca la hidratación de 24 horas.',
        'faq_context' => 'Envío gratis en compras superiores a 100000.',
        'required_customer_info' => 'Nombre y dirección',
    ]);

    $this->shampoo = Product::create([
        'store_id' => $this->store->id,
        'name' => 'Shampoo Anticaspa',
        'description' => 'Shampoo para cuero cabelludo sensible',
        'price' => 30000,
        'stock' => 5,
        'type' => 'product',
        'ai_sales_strategy' => 'Ofrece el combo de dos unidades.',
    ]);

    Conversation::create(['store_id' => $this->store->id, 'customer_phone' => CUSTOMER, 'last_session_at' => now()]);
});

function runSalesJob($store, string $body, ?int $productContext = null): void
{
    ProcessWhatsAppMessage::dispatchSync($store, CUSTOMER, $body, 'wamid.in.'.uniqid(), 'text', null, $productContext);
}

function mainSystemPrompt(): string
{
    $requests = mainChatRequests();
    expect($requests)->toHaveCount(1);

    return $requests[0]->data()['messages'][0]['content'];
}

it('builds the prompt from store instructions, the named product and system metadata', function () {
    fakeExternalApis('Claro, te cuento sobre la crema.');

    runSalesJob($this->store, 'Quiero la Crema Facial');

    // A product found by name is formatted by ProductFinderService::formatProducts()
    // (emoji layout); a sticky or ad product uses the job's own formatProductData().
    $prompt = mainSystemPrompt();
    expect($prompt)
        ->toStartWith('Eres el vendedor de Tienda Test.')
        ->toContain('### PRODUCT CATALOG DATA:')
        ->toContain('📦 **Crema Facial** (Product) - $45000.00')
        ->toContain('Sales Strategy: Destaca la hidratación de 24 horas.')
        ->toContain('Rules & FAQ: Envío gratis en compras superiores a 100000.')
        ->toContain('Required Data: Nombre y dirección')
        ->toContain('### SYSTEM METADATA:')
        ->toContain('Lead Completion Signal: [LEAD_COMPLETE]')
        ->not->toContain('Shampoo Anticaspa');

    $messages = mainChatRequests()[0]->data()['messages'];
    expect(end($messages))->toBe(['role' => 'user', 'content' => 'Quiero la Crema Facial']);
});

it('sends the last messages of the customer as chronological history', function () {
    fakeExternalApis();

    WhatsAppMessage::create(['store_id' => $this->store->id, 'customer_phone' => CUSTOMER, 'role' => 'user', 'content' => 'Hola']);
    $this->travel(1)->seconds();
    WhatsAppMessage::create(['store_id' => $this->store->id, 'customer_phone' => CUSTOMER, 'role' => 'assistant', 'content' => '¡Hola! ¿Qué buscas?']);
    $this->travel(1)->seconds();

    runSalesJob($this->store, 'Quiero la Crema Facial');

    $messages = mainChatRequests()[0]->data()['messages'];
    expect($messages[1])->toBe(['role' => 'user', 'content' => 'Hola'])
        ->and($messages[2])->toBe(['role' => 'assistant', 'content' => '¡Hola! ¿Qué buscas?']);
});

it('makes a product named in the message the conversation product', function () {
    fakeExternalApis();

    runSalesJob($this->store, 'Quiero la Crema Facial');

    expect(Conversation::first()->current_product_id)->toBe($this->cream->id);
});

it('keeps the conversation product on a follow-up that names no product', function () {
    fakeExternalApis();
    Conversation::first()->update(['current_product_id' => $this->cream->id]);

    runSalesJob($this->store, 'sí, me parece bien');

    expect(mainSystemPrompt())
        ->toContain('Product: Crema Facial')
        ->not->toContain('Shampoo Anticaspa');
});

it('falls back to the full catalog when nothing matches and no product is active', function () {
    fakeExternalApis();

    runSalesJob($this->store, 'buenas tardes');

    expect(mainSystemPrompt())
        ->toContain('📦 **Crema Facial** (Product)')
        ->toContain('📦 **Shampoo Anticaspa** (Product)');
});

it('uses the product resolved from a Click-to-WhatsApp ad', function () {
    fakeExternalApis();

    runSalesJob($this->store, 'Hola, vi el anuncio', $this->shampoo->id);

    expect(mainSystemPrompt())
        ->toContain('Product: Shampoo Anticaspa')
        ->not->toContain('Crema Facial');
    expect(Conversation::first()->current_product_id)->toBe($this->shampoo->id);
});

it('creates a lead from a confirmed order and strips the completion token from the reply', function () {
    fakeExternalApis('Tu pedido está confirmado: Crema Facial. [LEAD_COMPLETE]', [
        'customer_name' => 'Ana Pérez',
        'delivery_address_or_location' => 'Calle 10 # 5-20, Cali, Valle del Cauca',
        'product_service_name' => 'Crema Facial',
    ]);
    Conversation::first()->update(['current_product_id' => $this->cream->id, 'ctwa_clid' => 'clid-123']);

    runSalesJob($this->store, 'Sí, confirmo la Crema Facial');

    $lead = Lead::sole();
    expect($lead->store_id)->toBe($this->store->id)
        ->and($lead->product_id)->toBe($this->cream->id)
        ->and($lead->customer_phone)->toBe(CUSTOMER)
        ->and($lead->customer_name)->toBe('Ana Pérez')
        ->and($lead->delivery_address_or_location)->toBe('Calle 10 # 5-20, Cali, Valle del Cauca')
        ->and($lead->product_service_name)->toBe('Crema Facial')
        ->and((float) $lead->sale_value)->toBe(45000.0) // no line items / total → catalog price
        ->and($lead->ctwa_clid)->toBe('clid-123')
        ->and($lead->order_status)->toBe('confirmado')
        ->and($lead->needs_address_review)->toBeFalse()
        ->and($lead->is_processed)->toBeFalse();

    $sent = sentWhatsAppTexts();
    expect($sent)->toHaveCount(1)
        ->and($sent[0]->data()['text']['body'])->toBe('Tu pedido está confirmado: Crema Facial.');

    // Current behavior: the stored assistant turn keeps the raw AI text, token included.
    expect(WhatsAppMessage::where('role', 'assistant')->sole()->content)
        ->toBe('Tu pedido está confirmado: Crema Facial. [LEAD_COMPLETE]');
});

it('does not create a second lead while the customer has an open one', function () {
    fakeExternalApis('Tu pedido está confirmado. [LEAD_COMPLETE]', ['customer_name' => 'Ana Pérez', 'product_service_name' => 'Crema Facial']);
    Lead::create(['store_id' => $this->store->id, 'customer_phone' => CUSTOMER, 'customer_name' => 'Ana Pérez', 'summary' => 'Pedido anterior', 'is_processed' => false]);

    runSalesJob($this->store, 'Gracias, ¿cuándo llega?');

    expect(Lead::count())->toBe(1);
});

it('persists both turns and replies through the store WhatsApp number', function () {
    fakeExternalApis('La crema cuesta 45000.');

    runSalesJob($this->store, '¿Cuánto cuesta la Crema Facial?');

    $sent = sentWhatsAppTexts();
    expect($sent)->toHaveCount(1)
        ->and($sent[0]->url())->toBe("https://graph.facebook.com/v20.0/{$this->store->wa_phone_number_id}/messages")
        ->and($sent[0]->data()['to'])->toBe(CUSTOMER)
        ->and($sent[0]->data()['text']['body'])->toBe('La crema cuesta 45000.')
        ->and($sent[0]->header('Authorization')[0])->toBe('Bearer wa-token');

    $turns = WhatsAppMessage::orderBy('id')->get();
    expect($turns->pluck('role')->all())->toBe(['user', 'assistant'])
        ->and($turns[0]->content)->toBe('¿Cuánto cuesta la Crema Facial?')
        ->and($turns[1]->content)->toBe('La crema cuesta 45000.')
        ->and($turns[1]->wamid)->toStartWith('wamid.out.')
        ->and(Lead::count())->toBe(0);
});

it('skips the AI entirely when the bot is disabled for the customer', function () {
    fakeExternalApis();
    Lead::create(['store_id' => $this->store->id, 'customer_phone' => CUSTOMER, 'customer_name' => 'Unknown', 'summary' => 'Bot control', 'bot_active' => false]);

    runSalesJob($this->store, 'Hola, ¿hay alguien?');

    expect(mainChatRequests())->toBeEmpty()
        ->and(sentWhatsAppTexts())->toBeEmpty()
        ->and(WhatsAppMessage::sole()->content)->toBe('Hola, ¿hay alguien?');
});

it('apologizes to the customer and rethrows when the AI provider fails', function () {
    fakeExternalApis(failChat: true);

    expect(fn () => runSalesJob($this->store, 'Quiero la Crema Facial'))->toThrow(Exception::class);

    $sent = sentWhatsAppTexts();
    expect($sent)->toHaveCount(1)
        ->and($sent[0]->data()['text']['body'])->toBe('Lo siento, estoy experimentando dificultades técnicas. Por favor intenta más tarde.')
        ->and(WhatsAppMessage::count())->toBe(0)
        ->and(Lead::count())->toBe(0);
});
