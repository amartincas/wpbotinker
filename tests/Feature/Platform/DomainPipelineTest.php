<?php

/*
 * Domain conversation pipeline (stores in 'knowledge' mode): product and
 * domain resolution, clarification on ambiguity, and isolation between
 * domains and between stores. The two domains used here exist only in
 * tests/Support and are registered through configuration alone.
 */

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Product;
use App\Models\Store;
use App\Models\WhatsAppMessage;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Tests\Support\Domains\OracleTestDomain;
use Tests\Support\Domains\WellnessTestDomain;

const DOMAIN_CUSTOMER = '573004445566';

beforeEach(function () {
    config(['botinker.domains' => [
        'oracle' => OracleTestDomain::class,
        'wellness' => WellnessTestDomain::class,
    ]]);

    $this->store = makeStore([
        'system_prompt' => 'Eres el asistente de BoTinker.',
        'conversation_mode' => Store::MODE_KNOWLEDGE,
    ]);

    $this->chart = makeDomainProduct($this->store, 'oracle', 'Carta astral', [
        'aliases' => ['carta natal'],
        'ai_sales_strategy' => 'INSTRUCCION-CARTA: explica la carta paso a paso.',
    ]);
    $this->sign = makeDomainProduct($this->store, 'oracle', 'Tu signo', [
        'ai_sales_strategy' => 'INSTRUCCION-SIGNO: describe el signo.',
    ]);
    $this->diet = makeDomainProduct($this->store, 'wellness', 'Plan de dieta', [
        'ai_sales_strategy' => 'INSTRUCCION-DIETA: arma un plan semanal.',
    ]);
});

function makeDomainProduct(Store $store, string $domainKey, string $name, array $attributes = []): Product
{
    return Product::create(array_merge([
        'store_id' => $store->id, 'domain_key' => $domainKey, 'name' => $name,
        'description' => "Descripción de {$name}", 'price' => 20000, 'stock' => 1, 'type' => 'service',
    ], $attributes));
}

function runDomainJob(Store $store, string $body, ?int $adProductId = null): void
{
    ProcessWhatsAppMessage::dispatchSync($store, DOMAIN_CUSTOMER, $body, 'wamid.in.'.uniqid(), 'text', null, $adProductId);
}

function lastSentText(): string
{
    $sent = sentWhatsAppTexts();

    return end($sent)->data()['text']['body'];
}

function domainPrompt(): string
{
    $requests = mainChatRequests();

    return end($requests)->data()['messages'][0]['content'];
}

function domainConversation(Store $store): Conversation
{
    return Conversation::where('store_id', $store->id)->where('customer_phone', DOMAIN_CUSTOMER)->sole();
}

describe('product and domain resolution', function () {
    it('answers about a named product with only its domain rules and instructions', function () {
        fakeExternalApis('Tu carta natal muestra...');

        runDomainJob($this->store, 'Quiero mi carta natal');

        expect(domainPrompt())
            ->toStartWith('Eres el asistente de BoTinker.')
            ->toContain('### DOMINIO: Oráculo')
            ->toContain('REGLA-ORACULO')
            ->toContain('### PRODUCTO: Carta astral')
            ->toContain('INSTRUCCION-CARTA')
            ->not->toContain('REGLA-BIENESTAR')
            ->not->toContain('INSTRUCCION-DIETA')
            ->not->toContain('INSTRUCCION-SIGNO');

        expect(lastSentText())->toBe('Tu carta natal muestra...')
            ->and(domainConversation($this->store)->current_product_id)->toBe($this->chart->id)
            ->and(WhatsAppMessage::where('store_id', $this->store->id)->pluck('role')->all())->toBe(['user', 'assistant']);
    });

    it('uses the product of a Click-to-WhatsApp ad directly', function () {
        fakeExternalApis();

        runDomainJob($this->store, 'Hola', $this->diet->id);

        expect(domainPrompt())->toContain('### PRODUCTO: Plan de dieta')->not->toContain('REGLA-ORACULO');
    });

    it('keeps the active product on a follow-up and switches on an explicit mention', function () {
        fakeExternalApis();

        runDomainJob($this->store, 'Cuéntame de tu signo');
        runDomainJob($this->store, '¿y qué más me puedes decir?');
        expect(domainPrompt())->toContain('### PRODUCTO: Tu signo');

        runDomainJob($this->store, 'Ahora quiero el plan de dieta');
        expect(domainPrompt())
            ->toContain('### PRODUCTO: Plan de dieta')
            ->toContain('REGLA-BIENESTAR')
            ->not->toContain('REGLA-ORACULO');
    });

    it('picks the single product of a domain identified by its vocabulary', function () {
        fakeExternalApis();

        runDomainJob($this->store, 'Me interesa la nutrición');

        expect(domainPrompt())->toContain('### PRODUCTO: Plan de dieta');
    });

    it('answers with the store persona alone when the store has no domain products', function () {
        fakeExternalApis('Hola, ¿en qué te ayudo?');
        $emptyStore = makeStore(['system_prompt' => 'Persona de la tienda vacía.', 'conversation_mode' => Store::MODE_KNOWLEDGE]);

        runDomainJob($emptyStore, 'Hola');

        expect(domainPrompt())->toBe('Persona de la tienda vacía.')
            ->and(lastSentText())->toBe('Hola, ¿en qué te ayudo?');
    });

    it('matches whole words only', function () {
        fakeExternalApis();
        makeDomainProduct($this->store, 'oracle', 'Luna');

        // "lunares" contains "luna" but does not name the product.
        runDomainJob($this->store, 'Tengo lunares en la cara');

        expect(mainChatRequests())->toBeEmpty()
            ->and(lastSentText())->toContain('¿Sobre cuál de estas opciones');
    });
});

describe('clarification instead of an arbitrary choice', function () {
    it('asks when the message names products of two domains, without calling the AI', function () {
        fakeExternalApis();

        runDomainJob($this->store, 'Quiero la carta astral y también el plan de dieta');

        expect(mainChatRequests())->toBeEmpty()
            ->and(lastSentText())->toBe("¿Sobre cuál de estas opciones quieres hablar?\n1. Carta astral (Oráculo)\n2. Plan de dieta (Bienestar)\n\nResponde con el número o el nombre.")
            ->and(domainConversation($this->store)->pending_state)->toBe([
                'type' => 'product_clarification',
                'product_ids' => [$this->chart->id, $this->diet->id],
            ]);
    });

    it('accepts a numbered answer to the clarification', function () {
        fakeExternalApis();

        runDomainJob($this->store, 'Quiero la carta astral y también el plan de dieta');
        runDomainJob($this->store, '2');

        expect(domainPrompt())->toContain('### PRODUCTO: Plan de dieta')->not->toContain('REGLA-ORACULO');

        $conversation = domainConversation($this->store);
        expect($conversation->current_product_id)->toBe($this->diet->id)
            ->and($conversation->pending_state)->toBeNull();
    });

    it('asks within the domain when its vocabulary matches several products', function () {
        fakeExternalApis();

        runDomainJob($this->store, 'Quiero saber mi horóscopo');

        expect(mainChatRequests())->toBeEmpty()
            ->and(lastSentText())->toBe("¿Sobre cuál de estas opciones quieres hablar?\n1. Carta astral\n2. Tu signo\n\nResponde con el número o el nombre.");
    });

    it('offers the catalog on a generic first message', function () {
        fakeExternalApis();

        runDomainJob($this->store, 'Hola');

        expect(lastSentText())
            ->toContain('1. Carta astral (Oráculo)')
            ->toContain('2. Tu signo (Oráculo)')
            ->toContain('3. Plan de dieta (Bienestar)');
    });
});

describe('isolation', function () {
    it('ignores products whose domain is not registered', function () {
        fakeExternalApis();
        makeDomainProduct($this->store, 'unregistered', 'Producto Fantasma');

        runDomainJob($this->store, 'Hola, quiero el Producto Fantasma');

        expect(lastSentText())->not->toContain('Producto Fantasma');
    });

    it('never offers or answers with another store products', function () {
        fakeExternalApis();
        $otherStore = makeStore(['conversation_mode' => Store::MODE_KNOWLEDGE]);
        makeDomainProduct($otherStore, 'oracle', 'Tarot Ajeno', ['ai_sales_strategy' => 'INSTRUCCION-AJENA']);

        runDomainJob($this->store, 'Hola, quiero el Tarot Ajeno');

        expect(mainChatRequests())->toBeEmpty()
            ->and(lastSentText())->not->toContain('Tarot Ajeno');
    });

    it('keeps conversation content out of the logs', function () {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
            $logged[] = $event->message.' '.json_encode($event->context, JSON_UNESCAPED_UNICODE);
        });
        fakeExternalApis('SECRETO-RESPUESTA-DOMINIO');

        runDomainJob($this->store, 'Mi carta natal SECRETO-MENSAJE-DOMINIO');

        expect(collect($logged)->filter(fn ($line) => str_contains($line, 'SECRETO-')))->toBeEmpty();
    });
});

describe('error handling and the sales flow', function () {
    it('apologizes once and persists nothing when the AI provider fails', function () {
        fakeExternalApis(failChat: true);

        expect(fn () => runDomainJob($this->store, 'Quiero mi carta natal'))->toThrow(Exception::class);

        expect(sentWhatsAppTexts())->toHaveCount(1)
            ->and(lastSentText())->toStartWith('Lo siento, estoy experimentando dificultades técnicas')
            ->and(WhatsAppMessage::count())->toBe(0);
    });

    it('does not create leads in knowledge mode', function () {
        fakeExternalApis('Listo, tu pedido está confirmado. [LEAD_COMPLETE]', ['customer_name' => 'Ana']);

        runDomainJob($this->store, 'Quiero mi carta natal');

        expect(Lead::count())->toBe(0);
    });

    it('keeps sales stores on the sales flow even when their products carry a domain', function () {
        fakeExternalApis();
        $salesStore = makeStore();
        makeDomainProduct($salesStore, 'oracle', 'Carta astral', ['type' => 'product', 'stock' => 5]);

        expect($salesStore->fresh()->conversation_mode)->toBe(Store::MODE_SALES);

        runDomainJob($salesStore, 'Quiero la Carta astral');

        expect(domainPrompt())
            ->toContain('### PRODUCT CATALOG DATA:')
            ->toContain('Lead Completion Signal: [LEAD_COMPLETE]')
            ->not->toContain('### DOMINIO');
    });
});
