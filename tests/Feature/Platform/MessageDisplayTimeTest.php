<?php

/*
 * Messages are stored in UTC; the chat center and a lead's recent
 * conversation show their day and time in the configured time zone.
 */

use App\Livewire\WhatsAppChatCenter;
use App\Models\User;
use App\Models\WhatsAppMessage;
use Carbon\Carbon;
use Livewire\Livewire;

const MDT_PHONE = '573001112233';

beforeEach(function () {
    fakeExternalApis();
    $this->store = makeStore();
});

function mdtMessageAt(string $utc, string $role = 'user', string $content = 'Hola'): WhatsAppMessage
{
    test()->travelTo(Carbon::parse($utc, 'UTC'));

    return WhatsAppMessage::create(['store_id' => test()->store->id, 'customer_phone' => MDT_PHONE, 'role' => $role, 'content' => $content]);
}

it('shows the day and the time in Bogotá, with the day the message had there', function (string $utc, string $shown) {
    expect(mdtMessageAt($utc)->displayedAt())->toBe($shown);
})->with([
    'de noche: el día anterior al de UTC' => ['2026-11-15 03:10:00', '14/11/2026 10:10 p. m.'],
    'de mañana' => ['2026-11-14 14:05:00', '14/11/2026 09:05 a. m.'],
    'mediodía' => ['2026-11-14 17:00:00', '14/11/2026 12:00 p. m.'],
]);

it('takes the time zone from the configuration and keeps the stored time in UTC', function () {
    config(['botinker.display_timezone' => 'Europe/Madrid']);
    $message = mdtMessageAt('2026-11-15 03:10:00');

    expect($message->displayedAt())->toBe('15/11/2026 04:10 a. m.')
        ->and($message->fresh()->created_at->toDateTimeString())->toBe('2026-11-15 03:10:00')
        ->and($message->created_at->timezoneName)->toBe('UTC');
});

it('shows it in the chat center', function () {
    mdtMessageAt('2026-11-15 03:10:00', 'user', 'Mensaje del cliente');
    mdtMessageAt('2026-11-15 03:11:00', 'assistant', 'Respuesta del bot');
    $operator = User::factory()->create(['store_id' => $this->store->id, 'is_super_admin' => false]);

    Livewire::actingAs($operator)
        ->test(WhatsAppChatCenter::class)
        ->call('selectConversation', MDT_PHONE)
        ->assertSee('14/11/2026 10:10 p. m.')
        ->assertSee('14/11/2026 10:11 p. m.');
});

it('shows it in a lead\'s recent conversation', function () {
    $messages = collect([mdtMessageAt('2026-11-15 03:10:00'), mdtMessageAt('2026-11-15 03:11:00', 'assistant', 'Respuesta')]);

    $html = view('filament.components.chat-history', ['record' => (object) ['id' => 1], 'messages' => $messages])->render();

    expect($html)->toContain('14/11/2026 10:10 p. m.')->toContain('14/11/2026 10:11 p. m.');
});
