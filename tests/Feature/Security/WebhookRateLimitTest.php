<?php

/*
 * Per store+contact rate limit on inbound WhatsApp messages: protects AI
 * cost from a single number flooding a store. A rejected message is NOT
 * marked as processed, so Meta's own retry can still deliver it later.
 */

use App\Jobs\ProcessWhatsAppMessage;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    config(['services.meta.contact_rate_limit_max' => 3, 'services.meta.contact_rate_limit_minutes' => 1]);
    $this->store = makeStore();
});

function postMessage($test, $store, string $from, string $wamid)
{
    $raw = json_encode(['entry' => [['changes' => [['value' => [
        'metadata' => ['phone_number_id' => $store->wa_phone_number_id],
        'messages' => [['from' => $from, 'id' => $wamid, 'type' => 'text', 'text' => ['body' => 'Hola']]],
    ]]]]]]);

    return $test->call('POST', '/api/whatsapp/webhook/any', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, 'app-secret'),
    ], $raw);
}

it('rejects messages from one contact beyond the per-minute limit', function () {
    foreach (range(1, 3) as $i) {
        postMessage($this, $this->store, '573001112233', "wamid.rl.{$i}")->assertOk();
    }

    postMessage($this, $this->store, '573001112233', 'wamid.rl.4')->assertStatus(429);

    Queue::assertPushed(ProcessWhatsAppMessage::class, 3);
});

it('counts each contact and each store separately', function () {
    $otherStore = makeStore();

    foreach (range(1, 3) as $i) {
        postMessage($this, $this->store, '573001112233', "wamid.a.{$i}")->assertOk();
    }

    postMessage($this, $this->store, '573009999999', 'wamid.b.1')->assertOk();
    postMessage($this, $otherStore, '573001112233', 'wamid.c.1')->assertOk();

    Queue::assertPushed(ProcessWhatsAppMessage::class, 5);
});

it('processes a rate-limited message when Meta retries it after the window', function () {
    foreach (range(1, 3) as $i) {
        postMessage($this, $this->store, '573001112233', "wamid.w.{$i}")->assertOk();
    }
    postMessage($this, $this->store, '573001112233', 'wamid.w.4')->assertStatus(429);

    $this->travel(61)->seconds();

    postMessage($this, $this->store, '573001112233', 'wamid.w.4')->assertOk();
    Queue::assertPushed(ProcessWhatsAppMessage::class, 4);
});

it('does not rate limit delivery status callbacks', function () {
    $raw = fn (int $i) => json_encode(['entry' => [['changes' => [['value' => [
        'metadata' => ['phone_number_id' => $this->store->wa_phone_number_id],
        'statuses' => [['id' => "wamid.out.{$i}", 'status' => 'delivered']],
    ]]]]]]);

    foreach (range(1, 6) as $i) {
        $body = $raw($i);
        $this->call('POST', '/api/whatsapp/webhook/any', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'app-secret'),
        ], $body)->assertOk();
    }
});
