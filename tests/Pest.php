<?php

use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| Shared helpers for the commercial (sales) flow tests. External services
| (OpenAI, WhatsApp Cloud API, Meta Conversions API) are always faked —
| no test ever reaches a real endpoint.
|
*/

function makeStore(array $overrides = []): Store
{
    return Store::create(array_merge([
        'name' => 'Tienda Test',
        'personality_type' => 'vendedor',
        'system_prompt' => 'Eres el vendedor de Tienda Test.',
        'ai_provider' => 'openai',
        'ai_model' => 'gpt-4o-mini',
        'ai_api_key' => 'sk-test',
        'wa_access_token' => 'wa-token',
        'wa_phone_number_id' => (string) random_int(100000, 999999),
        'wa_verify_token' => Str::random(32),
        'wa_app_secret' => 'app-secret',
    ], $overrides));
}

/**
 * Fakes every outbound HTTP call the sales flow makes. The main chat reply
 * and each auxiliary AI call (lead extraction, address check, order total,
 * order lines) are told apart by the user message the job sends with them.
 */
function fakeExternalApis(string $reply = 'Hola, ¿en qué te ayudo?', array $extraction = [], bool $failChat = false): void
{
    $extraction = array_merge([
        'customer_name' => null,
        'delivery_address_or_location' => null,
        'product_service_name' => null,
        'preferred_date_time' => null,
    ], $extraction);

    Http::fake(function (Request $request) use ($reply, $extraction, $failChat) {
        $url = $request->url();

        if (str_starts_with($url, 'https://api.openai.com/v1/chat/completions')) {
            $userMessage = collect($request->data()['messages'])->last()['content'];

            $content = match ($userMessage) {
                'Extract lead information as JSON' => json_encode($extraction),
                'Analiza la dirección' => 'si',
                'Extrae el valor total' => 'ninguno',
                'Extrae las líneas del pedido' => '[]',
                default => null,
            };

            if ($content === null && $failChat) {
                return Http::response(['error' => 'upstream'], 500);
            }

            return Http::response(['choices' => [['message' => ['content' => $content ?? $reply]]]]);
        }

        if (str_starts_with($url, 'https://graph.facebook.com/') && str_ends_with($url, '/messages')) {
            return Http::response(['messages' => [['id' => 'wamid.out.'.Str::random(8)]]]);
        }

        if (str_starts_with($url, 'https://graph.facebook.com/') && str_ends_with($url, '/media')) {
            return Http::response(['id' => 'media.'.Str::random(8)]);
        }

        if (str_starts_with($url, 'https://graph.facebook.com/') && str_ends_with($url, '/events')) {
            return Http::response(['events_received' => 1]);
        }

        return Http::response([], 404);
    });
}

/**
 * The main chat-completion request (the one that produces the customer
 * reply), excluding the auxiliary extraction/classification calls.
 */
function mainChatRequests(): array
{
    $auxiliary = ['Extract lead information as JSON', 'Analiza la dirección', 'Extrae el valor total', 'Extrae las líneas del pedido'];

    return Http::recorded(function (Request $request) use ($auxiliary) {
        if (! str_starts_with($request->url(), 'https://api.openai.com/v1/chat/completions')) {
            return false;
        }

        return ! in_array(collect($request->data()['messages'])->last()['content'], $auxiliary, true);
    })->map(fn ($pair) => $pair[0])->values()->all();
}

/**
 * Text messages sent to customers through the WhatsApp Cloud API.
 */
function sentWhatsAppTexts(): array
{
    return Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/messages')
        && ($request->data()['type'] ?? null) === 'text')
        ->map(fn ($pair) => $pair[0])->values()->all();
}
