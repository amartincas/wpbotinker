<?php

/*
 * Each store answers with its OWN credentials: two stores with different
 * numbers, WhatsApp tokens, App Secrets, AI providers, models and keys,
 * and one customer talking to both. Credentials here are dummy test values.
 */

use App\Factories\AIServiceFactory;
use App\Filament\Resources\Stores\Pages\EditStore;
use App\Jobs\ProcessWhatsAppMessage;
use App\Livewire\WhatsAppChatCenter;
use App\Models\Store;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Services\AI\GeminiService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

const SHARED_CUSTOMER = '573001234567';

beforeEach(function () {
    Storage::fake('local');

    $this->storeA = makeStore([
        'name' => 'Tienda A', 'conversation_mode' => Store::MODE_KNOWLEDGE, 'system_prompt' => 'Persona A',
        'wa_phone_number_id' => '700000001', 'wa_access_token' => 'wa-token-store-a', 'wa_app_secret' => 'app-secret-store-a',
        'ai_provider' => 'openai', 'ai_model' => 'gpt-4o-mini', 'ai_api_key' => 'test-key-store-a',
    ]);
    $this->storeB = makeStore([
        'name' => 'Tienda B', 'conversation_mode' => Store::MODE_KNOWLEDGE, 'system_prompt' => 'Persona B',
        'wa_phone_number_id' => '700000002', 'wa_access_token' => 'wa-token-store-b', 'wa_app_secret' => 'app-secret-store-b',
        'ai_provider' => 'gemini', 'ai_model' => 'gemini-2.5-flash', 'ai_api_key' => 'test-key-store-b',
    ]);

    Http::fake(function (Request $request) {
        $url = $request->url();

        return match (true) {
            str_starts_with($url, 'https://api.openai.com/v1/audio/transcriptions') => Http::response(['text' => 'hola por audio']),
            str_starts_with($url, 'https://api.openai.com/v1/chat/completions') => Http::response(['choices' => [['message' => ['content' => 'Respuesta A']]]]),
            str_starts_with($url, 'https://generativelanguage.googleapis.com/') => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'Respuesta B']]]]]]),
            str_ends_with($url, '/messages') => Http::response(['messages' => [['id' => 'wamid.out.'.Str::random(8)]]]),
            str_starts_with($url, 'https://graph.facebook.com/v20.0/media-') => Http::response(['url' => 'https://lookaside.fbsbx.com/'.basename($url)]),
            str_starts_with($url, 'https://lookaside.fbsbx.com/') => Http::response('OggS-audio', 200, ['Content-Type' => 'audio/ogg']),
            default => Http::response([], 404),
        };
    });
});

function storeWebhook($test, Store $store, string $secret, array $message)
{
    $raw = json_encode(['entry' => [['changes' => [['value' => [
        'metadata' => ['phone_number_id' => $store->wa_phone_number_id],
        'messages' => [array_merge(['from' => SHARED_CUSTOMER, 'id' => 'wamid.in.'.uniqid()], $message)],
    ]]]]]]);

    return $test->call('POST', '/api/whatsapp/webhook/any-token', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, $secret),
    ], $raw);
}

/** Every outgoing request, as [url, Authorization header, x-goog-api-key header, body]. */
function outgoingRequests(): array
{
    return Http::recorded()->map(function ($pair) {
        try {
            $body = $pair[0]->body();
        } catch (Throwable) {
            $body = null; // multipart upload whose file stream is already closed
        }

        return [
            'url' => $pair[0]->url(),
            'auth' => $pair[0]->header('Authorization')[0] ?? null,
            'google_key' => $pair[0]->header('x-goog-api-key')[0] ?? null,
            'body' => $body,
        ];
    })->values()->all();
}

function requestsTo(string $prefix): array
{
    return array_values(array_filter(outgoingRequests(), fn ($r) => str_starts_with($r['url'], $prefix)));
}

/** True when no request of $prefix carries $needle in its URL, headers or body. */
function noneCarries(string $needle, ?string $prefix = null): bool
{
    foreach (outgoingRequests() as $request) {
        if ($prefix !== null && ! str_starts_with($request['url'], $prefix)) {
            continue;
        }

        if (str_contains(implode(' ', array_filter($request)), $needle)) {
            return false;
        }
    }

    return true;
}

describe('webhook routing and signatures', function () {
    it('accepts each store webhook only with that store App Secret', function () {
        storeWebhook($this, $this->storeA, 'app-secret-store-a', ['type' => 'text', 'text' => ['body' => 'Hola A']])->assertOk();
        storeWebhook($this, $this->storeB, 'app-secret-store-b', ['type' => 'text', 'text' => ['body' => 'Hola B']])->assertOk();

        storeWebhook($this, $this->storeA, 'app-secret-store-b', ['type' => 'text', 'text' => ['body' => 'Falso']])->assertForbidden();
        storeWebhook($this, $this->storeB, 'app-secret-store-a', ['type' => 'text', 'text' => ['body' => 'Falso']])->assertForbidden();

        expect(WhatsAppMessage::where('content', 'Falso')->count())->toBe(0)
            ->and(WhatsAppMessage::where('store_id', $this->storeA->id)->where('role', 'user')->pluck('content')->all())->toBe(['Hola A'])
            ->and(WhatsAppMessage::where('store_id', $this->storeB->id)->where('role', 'user')->pluck('content')->all())->toBe(['Hola B']);
    });
});

describe('one customer, two stores', function () {
    it('answers each store with its own number, token, provider, model and key', function () {
        storeWebhook($this, $this->storeA, 'app-secret-store-a', ['type' => 'text', 'text' => ['body' => 'Hola A']])->assertOk();
        storeWebhook($this, $this->storeB, 'app-secret-store-b', ['type' => 'text', 'text' => ['body' => 'Hola B']])->assertOk();

        $openAi = requestsTo('https://api.openai.com/');
        $gemini = requestsTo('https://generativelanguage.googleapis.com/');
        $sendsA = requestsTo('https://graph.facebook.com/v20.0/700000001/messages');
        $sendsB = requestsTo('https://graph.facebook.com/v20.0/700000002/messages');

        expect($openAi)->toHaveCount(1)
            ->and($openAi[0]['auth'])->toBe('Bearer test-key-store-a')
            ->and(json_decode($openAi[0]['body'], true)['model'])->toBe('gpt-4o-mini')
            ->and($gemini)->toHaveCount(1)
            ->and($gemini[0]['google_key'])->toBe('test-key-store-b')
            ->and($gemini[0]['url'])->toContain('/models/gemini-2.5-flash:generateContent')
            ->and($sendsA)->toHaveCount(1)
            ->and($sendsA[0]['auth'])->toBe('Bearer wa-token-store-a')
            ->and(json_decode($sendsA[0]['body'], true)['text']['body'])->toBe('Respuesta A')
            ->and($sendsB)->toHaveCount(1)
            ->and($sendsB[0]['auth'])->toBe('Bearer wa-token-store-b')
            ->and(json_decode($sendsB[0]['body'], true)['text']['body'])->toBe('Respuesta B');

        // No credential of one store ever travels with the other's requests.
        expect(noneCarries('test-key-store-b', 'https://api.openai.com/'))->toBeTrue()
            ->and(noneCarries('test-key-store-a', 'https://generativelanguage.googleapis.com/'))->toBeTrue()
            ->and(noneCarries('wa-token-store-b', 'https://graph.facebook.com/v20.0/700000001'))->toBeTrue()
            ->and(noneCarries('wa-token-store-a', 'https://graph.facebook.com/v20.0/700000002'))->toBeTrue();
    });
});

describe('audio transcription', function () {
    it('transcribes on the store own provider with its own key', function () {
        storeWebhook($this, $this->storeA, 'app-secret-store-a', ['type' => 'audio', 'audio' => ['id' => 'media-a']])->assertOk();

        $download = requestsTo('https://graph.facebook.com/v20.0/media-a');
        $whisper = requestsTo('https://api.openai.com/v1/audio/transcriptions');

        expect($download)->toHaveCount(1)
            ->and($download[0]['auth'])->toBe('Bearer wa-token-store-a')
            ->and($whisper)->toHaveCount(1)
            ->and($whisper[0]['auth'])->toBe('Bearer test-key-store-a')
            ->and(WhatsAppMessage::where('store_id', $this->storeA->id)->where('role', 'user')->value('content'))->toContain('hola por audio');
    });

    it('never sends a store key to another provider when its own cannot transcribe', function () {
        storeWebhook($this, $this->storeB, 'app-secret-store-b', ['type' => 'audio', 'audio' => ['id' => 'media-b']])->assertOk();

        $sendsB = requestsTo('https://graph.facebook.com/v20.0/700000002/messages');

        expect(requestsTo('https://api.openai.com/'))->toBe([])
            ->and(requestsTo('https://graph.facebook.com/v20.0/media-b'))->toBe([])
            ->and(noneCarries('test-key-store-b', 'https://api.openai.com/'))->toBeTrue()
            ->and($sendsB)->toHaveCount(1)
            ->and($sendsB[0]['auth'])->toBe('Bearer wa-token-store-b')
            ->and(json_decode($sendsB[0]['body'], true)['text']['body'])->toBe('Por ahora no puedo escuchar audios. Por favor escríbeme tu pregunta.')
            ->and(AIServiceFactory::makeTranscriber($this->storeB))->toBeNull()
            ->and(AIServiceFactory::makeTranscriber($this->storeA))->not->toBeNull();
    });
});

describe('Chat Center', function () {
    beforeEach(function () {
        foreach ([$this->storeA, $this->storeB] as $store) {
            WhatsAppMessage::create(['store_id' => $store->id, 'customer_phone' => SHARED_CUSTOMER, 'role' => 'user', 'content' => 'Hola']);
        }
        $this->admin = User::factory()->create(['store_id' => null, 'is_super_admin' => true]);
    });

    it('refuses a super admin send to a phone shared by two stores until a store is chosen', function () {
        Livewire::actingAs($this->admin)
            ->test(WhatsAppChatCenter::class)
            ->call('selectConversation', SHARED_CUSTOMER)
            ->assertSet('selectedStoreId', null)
            ->set('newMessage', 'Mensaje ambiguo')
            ->call('sendMessage')
            ->call('sendTemplate', 999);

        expect(requestsTo('https://graph.facebook.com/'))->toBe([])
            ->and(WhatsAppMessage::where('content', 'Mensaje ambiguo')->count())->toBe(0);
    });

    it('sends from the store the super admin selected, with its own credentials', function () {
        Livewire::actingAs($this->admin)
            ->test(WhatsAppChatCenter::class)
            ->set('filterStoreId', $this->storeB->id)
            ->call('selectConversation', SHARED_CUSTOMER)
            ->set('newMessage', 'Hola desde B')
            ->call('sendMessage');

        $sends = requestsTo('https://graph.facebook.com/');

        expect($sends)->toHaveCount(1)
            ->and($sends[0]['url'])->toBe('https://graph.facebook.com/v20.0/700000002/messages')
            ->and($sends[0]['auth'])->toBe('Bearer wa-token-store-b')
            ->and(WhatsAppMessage::where('content', 'Hola desde B')->sole()->store_id)->toBe($this->storeB->id);
    });

    it('uses the only store of a phone for a super admin without filter', function () {
        WhatsAppMessage::create(['store_id' => $this->storeA->id, 'customer_phone' => '573009999999', 'role' => 'user', 'content' => 'Solo A']);

        Livewire::actingAs($this->admin)
            ->test(WhatsAppChatCenter::class)
            ->call('selectConversation', '573009999999')
            ->assertSet('selectedStoreId', $this->storeA->id)
            ->set('newMessage', 'Hola desde A')
            ->call('sendMessage');

        expect(requestsTo('https://graph.facebook.com/')[0]['auth'])->toBe('Bearer wa-token-store-a');
    });

    it('always sends an operator message from the operator own store', function () {
        $operator = User::factory()->create(['store_id' => $this->storeA->id, 'is_super_admin' => false]);

        Livewire::actingAs($operator)
            ->test(WhatsAppChatCenter::class)
            ->call('selectConversation', SHARED_CUSTOMER)
            ->set('newMessage', 'Hola desde A')
            ->call('sendMessage');

        $sends = requestsTo('https://graph.facebook.com/');

        expect($sends)->toHaveCount(1)
            ->and($sends[0]['url'])->toBe('https://graph.facebook.com/v20.0/700000001/messages')
            ->and($sends[0]['auth'])->toBe('Bearer wa-token-store-a');
    });

    it('never lets the client choose the sending store', function () {
        Livewire::actingAs($this->admin)
            ->test(WhatsAppChatCenter::class)
            ->set('selectedStoreId', $this->storeB->id);
    })->throws(CannotUpdateLockedPropertyException::class);
});

describe('AI models', function () {
    it('rejects a model the configuration does not enable, without replacing it or exposing the key', function () {
        $this->storeA->update(['ai_model' => 'gpt-3.5-turbo']);

        try {
            AIServiceFactory::make($this->storeA->fresh());
            $this->fail('An invalid model must be rejected.');
        } catch (InvalidArgumentException $e) {
            expect($e->getMessage())->toContain('AI model [gpt-3.5-turbo] is not enabled for provider [openai]')
                ->not->toContain('test-key-store-a');
        }

        expect(AIServiceFactory::make($this->storeB))->toBeInstanceOf(GeminiService::class);
    });

    it('answers nothing with a model that is not enabled', function () {
        $this->storeA->update(['ai_model' => 'gpt-3.5-turbo']);

        expect(fn () => ProcessWhatsAppMessage::dispatchSync($this->storeA->fresh(), SHARED_CUSTOMER, 'Hola', 'wamid.in.'.uniqid()))
            ->toThrow(InvalidArgumentException::class, 'is not enabled');

        expect(requestsTo('https://api.openai.com/'))->toBe([]);
    });

    it('validates the model against the same list in the store form', function () {
        $operator = User::factory()->create(['store_id' => $this->storeA->id, 'is_super_admin' => false]);

        Livewire::actingAs($operator)
            ->test(EditStore::class, ['record' => $this->storeA->getRouteKey()])
            ->fillForm(['ai_provider' => 'openai', 'ai_model' => 'gemini-2.5-flash'])
            ->call('save')
            ->assertHasFormErrors(['ai_model']);

        expect($this->storeA->fresh()->ai_model)->toBe('gpt-4o-mini')
            ->and(AIServiceFactory::enabledModels('openai'))->toBe(config('ai.models.openai'));
    });
});

describe('WhatsApp number uniqueness', function () {
    it('is enforced by the database', function () {
        makeStore(['wa_phone_number_id' => '700000001']);
    })->throws(UniqueConstraintViolationException::class);

    it('lets several stores have no number yet', function () {
        makeStore(['wa_phone_number_id' => null]);
        makeStore(['wa_phone_number_id' => null]);

        expect(Store::whereNull('wa_phone_number_id')->count())->toBe(2);
    });

    it('is validated in the store form', function () {
        $operator = User::factory()->create(['store_id' => $this->storeB->id, 'is_super_admin' => false]);

        Livewire::actingAs($operator)
            ->test(EditStore::class, ['record' => $this->storeB->getRouteKey()])
            ->fillForm(['wa_phone_number_id' => '700000001'])
            ->call('save')
            ->assertHasFormErrors(['wa_phone_number_id']);

        expect($this->storeB->fresh()->wa_phone_number_id)->toBe('700000002');
    });

    it('stops the migration on existing duplicates without changing data', function () {
        // A separate in-memory database: no DDL touches the test database.
        config(['database.connections.uniqueness_check' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        $default = DB::getDefaultConnection();
        DB::setDefaultConnection('uniqueness_check');

        try {
            Schema::create('stores', function ($table) {
                $table->id();
                $table->string('wa_phone_number_id')->nullable();
            });
            DB::table('stores')->insert([['wa_phone_number_id' => '111'], ['wa_phone_number_id' => '111'], ['wa_phone_number_id' => null]]);

            $migration = require database_path('migrations/2026_10_19_000000_add_unique_phone_number_id_to_stores_table.php');

            expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'store ids: 1, 2')
                ->and(DB::table('stores')->pluck('wa_phone_number_id')->all())->toBe(['111', '111', null])
                ->and(collect(Schema::getIndexes('stores'))->pluck('unique')->filter()->count())->toBe(1); // only the primary key
        } finally {
            DB::setDefaultConnection($default);
        }
    });
});
