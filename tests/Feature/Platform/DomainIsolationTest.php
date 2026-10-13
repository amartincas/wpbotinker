<?php

/*
 * Conversational isolation between domains of one store: the conversation
 * history sent to the LLM, and topic changes away from an active product.
 * (Personal facts the customer explicitly authorizes to share across
 * domains are a separate, future mechanism — not conversation history.)
 */

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Conversation;
use App\Models\Product;
use App\Models\Store;
use Tests\Support\Domains\FinanceTestDomain;
use Tests\Support\Domains\OracleTestDomain;
use Tests\Support\Domains\WellnessTestDomain;

const ISOLATION_CUSTOMER = '573007778899';

beforeEach(function () {
    config(['botinker.domains' => [
        'oracle' => OracleTestDomain::class,
        'wellness' => WellnessTestDomain::class,
        'finance' => FinanceTestDomain::class,
    ]]);

    $this->store = makeStore(['conversation_mode' => Store::MODE_KNOWLEDGE]);

    $make = fn (string $domain, string $name) => Product::create([
        'store_id' => $this->store->id, 'domain_key' => $domain, 'name' => $name,
        'description' => "Descripción de {$name}", 'price' => 20000, 'stock' => 1, 'type' => 'service',
    ]);

    $this->chart = $make('oracle', 'Carta astral');
    $this->sign = $make('oracle', 'Tu signo');
    $this->diet = $make('wellness', 'Plan de dieta');
    $this->budget = $make('finance', 'Presupuesto mensual');
});

function isolationJob(Store $store, string $body): void
{
    ProcessWhatsAppMessage::dispatchSync($store, ISOLATION_CUSTOMER, $body, 'wamid.in.'.uniqid());
    test()->travel(1)->seconds();
}

/** User turns of the history sent with the last LLM request. */
function lastHistoryUserTurns(): array
{
    $requests = mainChatRequests();
    $messages = end($requests)->data()['messages'];

    // [0] is the system prompt, the last one is the current message.
    return collect(array_slice($messages, 1, -1))->where('role', 'user')->pluck('content')->values()->all();
}

function lastPromptOf(): string
{
    $requests = mainChatRequests();

    return end($requests)->data()['messages'][0]['content'];
}

describe('conversation history', function () {
    it('does not send turns of another domain to the LLM', function () {
        fakeExternalApis();

        isolationJob($this->store, 'Quiero mi Carta astral');
        isolationJob($this->store, '¿Qué dice de mi ascendente?');
        isolationJob($this->store, 'Ahora quiero el Plan de dieta');

        expect(lastPromptOf())->toContain('### PRODUCTO: Plan de dieta')
            ->and(lastHistoryUserTurns())->toBe([]);
    });

    it('keeps the history of a domain when the customer comes back to it', function () {
        fakeExternalApis();

        isolationJob($this->store, 'Quiero mi Carta astral');
        isolationJob($this->store, 'Ahora quiero el Plan de dieta');
        isolationJob($this->store, '¿Cuántas comidas al día?');
        isolationJob($this->store, 'Volvamos a la Carta astral');

        expect(lastHistoryUserTurns())->toBe(['Quiero mi Carta astral']);
    });

    it('does not send the ambiguous message that triggered a clarification', function () {
        fakeExternalApis();

        isolationJob($this->store, 'Quiero la Carta astral y el Plan de dieta');
        isolationJob($this->store, '2');

        expect(lastPromptOf())->toContain('### PRODUCTO: Plan de dieta')
            ->and(lastHistoryUserTurns())->toBe([]);
    });
});

describe('topic change from an active product', function () {
    it('moves to the domain the question is clearly about', function () {
        fakeExternalApis();

        isolationJob($this->store, 'Quiero mi Carta astral');
        isolationJob($this->store, '¿Cómo puedo ahorrar para pagar mis deudas?');

        expect(lastPromptOf())
            ->toContain('### PRODUCTO: Presupuesto mensual')
            ->toContain('REGLA-FINANZAS')
            ->not->toContain('REGLA-ORACULO');
        expect(Conversation::where('store_id', $this->store->id)->sole()->current_product_id)->toBe($this->budget->id);
    });

    it('asks when the new domain has several products', function () {
        fakeExternalApis();
        Product::create([
            'store_id' => $this->store->id, 'domain_key' => 'finance', 'name' => 'Plan de inversión',
            'description' => 'x', 'price' => 1, 'stock' => 1, 'type' => 'service',
        ]);

        isolationJob($this->store, 'Quiero mi Carta astral');
        $before = count(mainChatRequests());
        isolationJob($this->store, 'Quiero empezar a invertir mis ahorros');

        expect(count(mainChatRequests()))->toBe($before)
            ->and(collect(sentWhatsAppTexts())->last()->data()['text']['body'])
            ->toContain('1. Presupuesto mensual')
            ->toContain('2. Plan de inversión')
            ->not->toContain('Carta astral');
    });

    it('asks when the question mixes the active domain and another one', function () {
        fakeExternalApis();

        isolationJob($this->store, 'Quiero mi Carta astral');
        $before = count(mainChatRequests());
        isolationJob($this->store, '¿Qué dicen los astros sobre mis deudas?');

        expect(count(mainChatRequests()))->toBe($before)
            ->and(collect(sentWhatsAppTexts())->last()->data()['text']['body'])
            ->toContain('Carta astral (Oráculo)')
            ->toContain('Presupuesto mensual (Finanzas)');
    });

    it('stays on the active product for a follow-up with no other domain signal', function () {
        fakeExternalApis();

        isolationJob($this->store, 'Quiero mi Carta astral');
        isolationJob($this->store, '¿Y qué significa la casa 8?');

        expect(lastPromptOf())->toContain('### PRODUCTO: Carta astral');
    });
});
