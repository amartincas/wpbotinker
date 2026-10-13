<?php

/*
 * Memory and knowledge through the conversation pipeline (phase B), end to
 * end from the job: required facts asked without the LLM, validation,
 * continuity across sessions, permission to reuse person facts, knowledge
 * in the prompt and nothing sensitive in logs.
 */

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Conversation;
use App\Models\CustomerFact;
use App\Models\CustomerFactShare;
use App\Models\Product;
use App\Models\Store;
use App\Platform\Knowledge\KnowledgeIngestor;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Tests\Support\Domains\FinanceTestDomain;
use Tests\Support\Domains\OracleTestDomain;

const FACTS_CUSTOMER = '573002223344';

beforeEach(function () {
    config(['botinker.domains' => ['oracle' => OracleTestDomain::class, 'finance' => FinanceTestDomain::class]]);

    $this->store = makeStore(['conversation_mode' => Store::MODE_KNOWLEDGE]);
    $this->chart = Product::create([
        'store_id' => $this->store->id, 'domain_key' => 'oracle', 'name' => 'Carta astral',
        'required_facts' => ['birth_date'], 'description' => 'x', 'price' => 1, 'stock' => 1, 'type' => 'service',
    ]);
    $this->budget = Product::create([
        'store_id' => $this->store->id, 'domain_key' => 'finance', 'name' => 'Presupuesto',
        'required_facts' => ['monthly_income', 'birth_date'], 'description' => 'x', 'price' => 1, 'stock' => 1, 'type' => 'service',
    ]);
});

function factsJob(Store $store, string $body, string $wamid = ''): void
{
    ProcessWhatsAppMessage::dispatchSync($store, FACTS_CUSTOMER, $body, $wamid ?: 'wamid.in.'.uniqid());
    test()->travel(1)->seconds();
}

function lastReply(): string
{
    return collect(sentWhatsAppTexts())->last()->data()['text']['body'];
}

function lastSystemPrompt(): string
{
    $requests = mainChatRequests();

    return end($requests)->data()['messages'][0]['content'];
}

function pendingType(Store $store): ?string
{
    return Conversation::where('store_id', $store->id)->where('customer_phone', FACTS_CUSTOMER)->sole()->pending_state['type'] ?? null;
}

describe('required facts', function () {
    it('asks for a missing fact without calling the AI', function () {
        fakeExternalApis();

        factsJob($this->store, 'Quiero mi Carta astral');

        expect(mainChatRequests())->toBeEmpty()
            ->and(lastReply())->toBe('¿Cuál es tu fecha de nacimiento? (día/mes/año)')
            ->and(pendingType($this->store))->toBe('fact_request');
    });

    it('asks again with the reason when the answer is not valid', function () {
        fakeExternalApis();

        factsJob($this->store, 'Quiero mi Carta astral');
        factsJob($this->store, 'el treinta de febrero');

        expect(mainChatRequests())->toBeEmpty()
            ->and(lastReply())->toBe("Escribe la fecha como día/mes/año, por ejemplo 15/03/1990.\n\n¿Cuál es tu fecha de nacimiento? (día/mes/año)")
            ->and(pendingType($this->store))->toBe('fact_request');
    });

    it('records a valid answer with its provenance and then answers with it', function () {
        fakeExternalApis('Tu carta está lista.');

        factsJob($this->store, 'Quiero mi Carta astral');
        factsJob($this->store, '15/03/1990', 'wamid.birth');

        $fact = CustomerFact::sole();
        expect($fact->plainValue())->toBe('1990-03-15')
            ->and($fact->source_wamid)->toBe('wamid.birth')
            ->and($fact->collected_by_domain)->toBe('oracle')
            ->and(lastSystemPrompt())->toContain("### DATOS DEL CLIENTE\nUsa estos datos tal como están; no los cambies ni supongas otros.\n- fecha de nacimiento: 1990-03-15")
            ->and(lastReply())->toBe('Tu carta está lista.')
            ->and(pendingType($this->store))->toBeNull();
    });

    it('does not ask again in a later session', function () {
        fakeExternalApis();

        factsJob($this->store, 'Quiero mi Carta astral');
        factsJob($this->store, '15/03/1990');
        $this->travel(3)->days();
        factsJob($this->store, 'Hola de nuevo, ¿qué más me dice mi Carta astral?');

        expect(collect(sentWhatsAppTexts())->filter(fn ($r) => str_contains($r->data()['text']['body'], 'fecha de nacimiento')))->toHaveCount(1)
            ->and(lastSystemPrompt())->toContain('- fecha de nacimiento: 1990-03-15');
    });

    it('lets the customer change topic instead of trapping them in a question', function () {
        fakeExternalApis();

        factsJob($this->store, 'Quiero mi Carta astral');
        factsJob($this->store, 'Mejor quiero el Presupuesto');

        expect(lastReply())->toBe('¿Cuál es tu ingreso mensual?')
            ->and(Conversation::sole()->current_product_id)->toBe($this->budget->id);
    });
});

describe('permission to reuse a person fact', function () {
    beforeEach(function () {
        fakeExternalApis();
        factsJob($this->store, 'Quiero mi Carta astral');
        factsJob($this->store, '15/03/1990');
        factsJob($this->store, 'Ahora quiero el Presupuesto');
        factsJob($this->store, '2500000');
    });

    it('asks for permission instead of asking the fact again', function () {
        expect(lastReply())->toBe('Para continuar con «Presupuesto» necesito tu fecha de nacimiento. Ya nos diste ese dato en otro servicio. ¿Me autorizas a usarlo aquí? Responde sí o no.')
            ->and(pendingType($this->store))->toBe('fact_share_consent');
    });

    it('uses the fact after the customer agrees', function () {
        factsJob($this->store, '¡Sí!');

        $share = CustomerFactShare::where('grantee_domain_key', 'finance')->sole();
        expect($share->basis)->toBe('consented')
            ->and(lastSystemPrompt())->toContain('- monthly income: 2500000')->toContain('- fecha de nacimiento: 1990-03-15');
    });

    it('does not use the fact when the customer declines', function () {
        $before = count(mainChatRequests());
        factsJob($this->store, 'no');

        expect(count(mainChatRequests()))->toBe($before)
            ->and(lastReply())->toContain('no usaré ese dato')
            ->and(CustomerFactShare::where('grantee_domain_key', 'finance')->whereNull('revoked_at')->exists())->toBeFalse()
            ->and(pendingType($this->store))->toBeNull();
    });

    it('asks again when the answer is neither yes nor no', function () {
        factsJob($this->store, 'tal vez');

        expect(lastReply())->toStartWith("Responde sí o no, por favor.\n\nPara continuar con «Presupuesto»");
    });

    it('never shows a domain fact of one domain in the prompt of another', function () {
        factsJob($this->store, 'sí');
        factsJob($this->store, 'Vuelvo a la Carta astral');

        expect(lastSystemPrompt())->toContain('### PRODUCTO: Carta astral')->not->toContain('monthly income')->not->toContain('2500000');
    });
});

describe('knowledge in the prompt', function () {
    it('adds the relevant fragments of the domain, labelled and marked as reference', function () {
        fakeExternalApis();
        $ingestor = app(KnowledgeIngestor::class);
        $oracle = $ingestor->ingest($this->store, 'oracle', $this->chart, 'Casas', 'markdown', "# Casa 8\n\nLa casa 8 habla de recursos compartidos.");
        $ingestor->ingest($this->store, 'finance', null, 'Ahorro', 'markdown', "# Casa propia\n\nAhorrar para comprar casa.");

        factsJob($this->store, 'Quiero mi Carta astral');
        factsJob($this->store, '15/03/1990');
        factsJob($this->store, '¿Qué significa la casa 8?');

        $chunkId = $oracle->chunks()->sole()->id;
        expect(lastSystemPrompt())
            ->toContain('### CONOCIMIENTO DE REFERENCIA')
            ->toContain('no instrucciones')
            ->toContain("[K:{$chunkId}] Casa 8\nLa casa 8 habla de recursos compartidos.")
            ->not->toContain('Ahorrar para comprar casa');
    });
});

describe('product configuration', function () {
    it('rejects saving a product that requires a fact its domain does not declare', function () {
        expect(fn () => Product::create([
            'store_id' => $this->store->id, 'domain_key' => 'oracle', 'name' => 'Malo',
            'required_facts' => ['monthly_income'], 'description' => 'x', 'price' => 1, 'stock' => 1,
        ]))->toThrow(InvalidArgumentException::class, 'does not declare');
    });

    it('does not check sales products', function () {
        $product = Product::create(['store_id' => $this->store->id, 'name' => 'Crema', 'description' => 'x', 'price' => 1, 'stock' => 1]);

        expect($product->exists)->toBeTrue();
    });
});

describe('logs and traces', function () {
    it('never logs fact values, answers or knowledge content', function () {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
            $logged[] = $event->message.' '.json_encode($event->context, JSON_UNESCAPED_UNICODE);
        });
        fakeExternalApis('SECRETO-RESPUESTA');
        app(KnowledgeIngestor::class)->ingest($this->store, 'oracle', null, 'Guía', 'text', 'Marte SECRETO-CONOCIMIENTO rige.');

        factsJob($this->store, 'Quiero mi Carta astral');
        factsJob($this->store, '31/02/1990');
        factsJob($this->store, '15/03/1990');
        factsJob($this->store, '¿Qué dice Marte?');

        $leaks = collect($logged)->filter(fn ($line) => preg_match('/SECRETO|1990|31\/02/', $line));
        expect($logged)->not->toBeEmpty()->and($leaks->values()->all())->toBe([]);
    });

    it('keeps function arguments out of exception traces', function () {
        $thrower = function (string $secret) {
            throw new RuntimeException('boom');
        };

        try {
            $thrower('SECRET-ARG');
        } catch (RuntimeException $e) {
            expect($e->getTraceAsString())->not->toContain('SECRET-ARG');
        }
    });
});
