<?php

/*
 * Characterization tests for the inbound WhatsApp webhook
 * (App\Http\Controllers\WhatsAppController::handle/verify).
 */

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Conversation;
use App\Models\Product;
use App\Models\WhatsAppMessage;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    $this->store = makeStore();
});

function textPayload(string $phoneNumberId, string $from = '573001112233', string $body = 'Hola', string $wamid = 'wamid.in.1', ?array $referral = null): array
{
    $message = ['from' => $from, 'id' => $wamid, 'type' => 'text', 'text' => ['body' => $body]];

    if ($referral) {
        $message['referral'] = $referral;
    }

    return ['entry' => [['changes' => [['value' => [
        'metadata' => ['phone_number_id' => $phoneNumberId],
        'messages' => [$message],
    ]]]]]];
}

function postSignedWebhook($test, array $payload, ?string $secret = 'app-secret')
{
    $raw = json_encode($payload);
    $server = ['CONTENT_TYPE' => 'application/json'];

    if ($secret !== null) {
        $server['HTTP_X_HUB_SIGNATURE_256'] = 'sha256='.hash_hmac('sha256', $raw, $secret);
    }

    return $test->call('POST', '/api/whatsapp/webhook/any-token', [], [], [], $server, $raw);
}

it('queues a signed text message for the store that owns the phone number', function () {
    postSignedWebhook($this, textPayload($this->store->wa_phone_number_id, body: 'Quiero info'))
        ->assertOk();

    Queue::assertPushed(ProcessWhatsAppMessage::class, fn ($job) => $job->store->is($this->store)
        && $job->from === '573001112233'
        && $job->messageBody === 'Quiero info'
        && $job->messageType === 'text'
        && $job->productContext === null);

    expect(Conversation::where('store_id', $this->store->id)->where('customer_phone', '573001112233')->count())->toBe(1);
});

it('rejects a request with an invalid or missing signature', function () {
    postSignedWebhook($this, textPayload($this->store->wa_phone_number_id), 'wrong-secret')->assertForbidden();
    postSignedWebhook($this, textPayload($this->store->wa_phone_number_id), null)->assertForbidden();

    Queue::assertNothingPushed();
});

it('returns 404 when no store owns the phone number', function () {
    postSignedWebhook($this, textPayload('000000'))->assertNotFound();

    Queue::assertNothingPushed();
});

it('ignores a repeated delivery of the same WhatsApp message id', function () {
    $payload = textPayload($this->store->wa_phone_number_id, wamid: 'wamid.dup');

    postSignedWebhook($this, $payload)->assertOk();
    postSignedWebhook($this, $payload)->assertOk();

    Queue::assertPushed(ProcessWhatsAppMessage::class, 1);
});

it('resolves the product from a Click-to-WhatsApp ad and stores the click id', function () {
    $product = Product::create([
        'store_id' => $this->store->id, 'name' => 'Crema Facial', 'description' => 'Crema',
        'price' => 45000, 'stock' => 10, 'type' => 'product', 'meta_ad_ids' => ['ad-777'],
    ]);

    postSignedWebhook($this, textPayload($this->store->wa_phone_number_id, referral: ['source_id' => 'ad-777', 'ctwa_clid' => 'clid-9']))
        ->assertOk();

    Queue::assertPushed(ProcessWhatsAppMessage::class, fn ($job) => $job->productContext === $product->id);
    expect(Conversation::sole()->ctwa_clid)->toBe('clid-9');
});

it('records delivery status updates for sent messages', function () {
    $message = WhatsAppMessage::create([
        'store_id' => $this->store->id, 'customer_phone' => '573001112233', 'role' => 'assistant',
        'content' => 'Hola', 'wamid' => 'wamid.out.1',
    ]);

    postSignedWebhook($this, ['entry' => [['changes' => [['value' => [
        'metadata' => ['phone_number_id' => $this->store->wa_phone_number_id],
        'statuses' => [['id' => 'wamid.out.1', 'status' => 'read', 'timestamp' => '1700000000']],
    ]]]]]])->assertOk();

    expect($message->fresh()->status)->toBe('read');
    Queue::assertNothingPushed();
});

it('answers the Meta verification challenge with the store verify token', function () {
    $this->get("/api/whatsapp/webhook/{$this->store->wa_verify_token}?hub_mode=subscribe&hub_verify_token={$this->store->wa_verify_token}&hub_challenge=12345")
        ->assertOk()
        ->assertSeeText('12345');
});
