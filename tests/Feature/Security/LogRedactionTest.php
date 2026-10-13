<?php

/*
 * Conversation content, prompts and personal data must not reach the logs.
 * Operational identifiers (store id, customer phone, WhatsApp message ids,
 * lengths, counts) are still logged on purpose — they are what operators
 * use to trace a conversation.
 */

use App\Jobs\ProcessWhatsAppMessage;
use App\Livewire\WhatsAppChatCenter;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Product;
use App\Models\User;
use App\Models\WhatsAppTemplate;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

const SECRET_MARKERS = [
    'SECRETO-MENSAJE',
    'SECRETO-RESPUESTA',
    'SECRETO-PROMPT',
    'SECRETO-NOMBRE',
    'SECRETO-DIRECCION',
    'SECRETO-PERFIL',
    'SECRETO-GUIA',
];

beforeEach(function () {
    $this->logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) {
        $this->logged[] = $event->message.' '.json_encode($event->context, JSON_UNESCAPED_UNICODE);
    });

    $this->store = makeStore(['system_prompt' => 'Eres el vendedor. SECRETO-PROMPT']);
    Product::create(['store_id' => $this->store->id, 'name' => 'Crema Facial', 'description' => 'Crema', 'price' => 45000, 'stock' => 10]);
});

function assertNoSecretsLogged(array $logged): void
{
    expect($logged)->not->toBeEmpty();

    foreach ($logged as $line) {
        foreach (SECRET_MARKERS as $marker) {
            expect(str_contains($line, $marker))->toBeFalse("Log line leaks {$marker}: ".mb_substr($line, 0, 300));
        }
    }
}

it('keeps message content and the raw payload out of the webhook logs', function () {
    Queue::fake();

    $raw = json_encode(['entry' => [['changes' => [['value' => [
        'metadata' => ['phone_number_id' => $this->store->wa_phone_number_id],
        'contacts' => [['profile' => ['name' => 'SECRETO-PERFIL'], 'wa_id' => '573001112233']],
        'messages' => [['from' => '573001112233', 'id' => 'wamid.log.1', 'type' => 'text', 'text' => ['body' => 'Hola SECRETO-MENSAJE']]],
    ]]]]]]);

    $this->call('POST', '/api/whatsapp/webhook/any', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, 'app-secret'),
    ], $raw)->assertOk();

    assertNoSecretsLogged($this->logged);
});

it('keeps conversation content, prompts and extracted personal data out of the job logs', function () {
    fakeExternalApis('Tu pedido está confirmado SECRETO-RESPUESTA [LEAD_COMPLETE]', [
        'customer_name' => 'SECRETO-NOMBRE',
        'delivery_address_or_location' => 'SECRETO-DIRECCION, Cali, Valle',
        'product_service_name' => 'Crema Facial',
    ]);
    Conversation::create(['store_id' => $this->store->id, 'customer_phone' => '573001112233', 'last_session_at' => now()]);

    ProcessWhatsAppMessage::dispatchSync($this->store, '573001112233', 'Confirmo la Crema Facial SECRETO-MENSAJE', 'wamid.log.2');

    assertNoSecretsLogged($this->logged);
});

it('keeps message content out of the logs when the AI provider fails', function () {
    fakeExternalApis(failChat: true);

    try {
        ProcessWhatsAppMessage::dispatchSync($this->store, '573001112233', 'Hola SECRETO-MENSAJE', 'wamid.log.3');
    } catch (Exception) {
        // expected: the job rethrows so the queue can retry it
    }

    assertNoSecretsLogged($this->logged);
});

it('keeps template variables and lead data out of the logs when an operator sends a template', function () {
    fakeExternalApis();
    $operator = User::factory()->create(['store_id' => $this->store->id, 'is_super_admin' => false]);
    Lead::create([
        'store_id' => $this->store->id, 'customer_phone' => '573001112233',
        'customer_name' => 'SECRETO-NOMBRE', 'product_service_name' => 'Crema Facial', 'summary' => 'Pedido',
    ]);
    $template = WhatsAppTemplate::create([
        'store_id' => $this->store->id, 'name' => 'envio_pedido', 'body_preview' => 'Hola {{1}}, tu guía es {{2}}',
        'parameters_map' => ['1' => 'customer_name', '2' => 'Guía'], 'language' => 'es_CO', 'type' => 'utility',
    ]);

    Livewire::actingAs($operator)
        ->test(WhatsAppChatCenter::class)
        ->set('selectedPhone', '573001112233')
        ->call('sendTemplate', $template->id, ['', 'SECRETO-GUIA']);

    // The template still goes out with the real values — only the logs change.
    $sent = Http::recorded(fn (Request $request) => ($request->data()['type'] ?? null) === 'template')->values();
    expect($sent)->toHaveCount(1)
        ->and(json_encode($sent[0][0]->data(), JSON_UNESCAPED_UNICODE))
        ->toContain('SECRETO-NOMBRE')
        ->toContain('SECRETO-GUIA');

    assertNoSecretsLogged($this->logged);
});
