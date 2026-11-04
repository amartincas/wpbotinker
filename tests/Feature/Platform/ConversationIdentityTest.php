<?php

/*
 * A customer has one conversation per store, also when two first messages
 * arrive at once. These tests prove the index and the recovery from losing
 * the race; several real processes competing are out of reach of a test
 * inside a transaction.
 */

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Conversation;
use App\Models\Store;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

const CI_PHONE = '573007778899';

/** Another process creates the conversation right after this process read that there was none. */
function ciLoseTheRace(Store $store): void
{
    $done = false;

    DB::listen(function ($query) use (&$done, $store) {
        if (! $done && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'conversations')) {
            $done = true;
            DB::table('conversations')->insert(['store_id' => $store->id, 'customer_phone' => CI_PHONE, 'last_session_at' => now()]);
        }
    });
}

function ciWebhook(Store $store, string $wamid): void
{
    $payload = json_encode(['entry' => [['changes' => [['value' => [
        'metadata' => ['phone_number_id' => $store->wa_phone_number_id],
        'messages' => [['id' => $wamid, 'from' => CI_PHONE, 'type' => 'text', 'text' => ['body' => 'Hola']]],
    ]]]]]]);

    test()->call('POST', "/api/whatsapp/webhook/{$store->id}", [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $payload, $store->wa_app_secret),
    ], $payload)->assertOk();
}

beforeEach(function () {
    $this->store = makeStore(['wa_phone_number_id' => '700000111', 'wa_app_secret' => 'secreto']);
});

it('refuses a second conversation for the same customer in the same store', function () {
    Conversation::create(['store_id' => $this->store->id, 'customer_phone' => CI_PHONE, 'last_session_at' => now()]);

    expect(fn () => DB::transaction(fn () => Conversation::create(['store_id' => $this->store->id, 'customer_phone' => CI_PHONE, 'last_session_at' => now()])))
        ->toThrow(UniqueConstraintViolationException::class)
        ->and(Conversation::count())->toBe(1);
});

it('lets the same phone have its own conversation in another store', function () {
    $other = makeStore(['wa_phone_number_id' => '700000222']);

    Conversation::create(['store_id' => $this->store->id, 'customer_phone' => CI_PHONE, 'last_session_at' => now()]);
    Conversation::create(['store_id' => $other->id, 'customer_phone' => CI_PHONE, 'last_session_at' => now()]);

    expect(Conversation::where('customer_phone', CI_PHONE)->count())->toBe(2);
});

it('recovers the conversation another process created first', function () {
    ciLoseTheRace($this->store);

    $conversation = Conversation::firstOrCreate(['store_id' => $this->store->id, 'customer_phone' => CI_PHONE], ['last_session_at' => now()]);

    expect($conversation->exists)->toBeTrue()
        ->and($conversation->wasRecentlyCreated)->toBeFalse()
        ->and(Conversation::sole()->id)->toBe($conversation->id);
});

it('queues the message of a webhook that lost the race on the one conversation', function () {
    Queue::fake();
    ciLoseTheRace($this->store);

    ciWebhook($this->store, 'wamid.in.first');
    ciWebhook($this->store, 'wamid.in.second');

    expect(Conversation::count())->toBe(1);
    Queue::assertPushed(ProcessWhatsAppMessage::class, 2);
});

describe('the migration', function () {
    beforeEach(function () {
        // A separate in-memory database: no DDL touches the test database.
        config(['database.connections.uniqueness_check' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        $this->defaultConnection = DB::getDefaultConnection();
        DB::setDefaultConnection('uniqueness_check');

        Schema::create('conversations', function ($table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->string('customer_phone');
        });

        $this->migration = require database_path('migrations/2026_11_05_000000_add_unique_identity_to_conversations_table.php');
    });

    afterEach(function () {
        DB::setDefaultConnection($this->defaultConnection);
    });

    it('stops on existing duplicates without changing data, naming no phone', function () {
        DB::table('conversations')->insert([
            ['store_id' => 7, 'customer_phone' => CI_PHONE],
            ['store_id' => 7, 'customer_phone' => CI_PHONE],
            ['store_id' => 8, 'customer_phone' => CI_PHONE],
        ]);

        $message = null;

        try {
            $this->migration->up();
        } catch (RuntimeException $e) {
            $message = $e->getMessage();
        }

        expect($message)->toContain('1 customer(s)')->toContain('store ids: 7')->not->toContain(CI_PHONE)
            ->and(DB::table('conversations')->count())->toBe(3)
            ->and(collect(Schema::getIndexes('conversations'))->pluck('unique')->filter()->count())->toBe(1); // only the primary key
    });

    it('creates the index without duplicates, and removes it on the way back', function () {
        DB::table('conversations')->insert([
            ['store_id' => 7, 'customer_phone' => CI_PHONE],
            ['store_id' => 8, 'customer_phone' => CI_PHONE],
        ]);

        $this->migration->up();
        expect(collect(Schema::getIndexes('conversations'))->pluck('unique')->filter()->count())->toBe(2);

        $this->migration->down();
        expect(collect(Schema::getIndexes('conversations'))->pluck('unique')->filter()->count())->toBe(1);
    });

    it('can go back and forth more than once', function () {
        foreach ([1, 2, 3] as $cycle) {
            $this->migration->up();
            expect(collect(Schema::getIndexes('conversations'))->pluck('unique')->filter()->count())->toBe(2);

            $this->migration->down();
            expect(collect(Schema::getIndexes('conversations'))->pluck('unique')->filter()->count())->toBe(1);
        }
    });
});
