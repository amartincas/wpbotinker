<?php

/*
 * C.1 — knowledge retrieval by exact keys named by a capability result.
 */

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\ComputedResult;
use App\Models\Product;
use App\Models\Store;
use App\Platform\Capabilities\CapabilityResult;
use App\Platform\Domains\DomainRegistry;
use App\Platform\Knowledge\KnowledgeIngestor;
use App\Platform\Knowledge\KnowledgeRetriever;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Tests\Support\Domains\Capabilities\LuckyNumberCapability;
use Tests\Support\Domains\FinanceTestDomain;
use Tests\Support\Domains\OracleTestDomain;

beforeEach(function () {
    config(['botinker.domains' => ['oracle' => OracleTestDomain::class, 'finance' => FinanceTestDomain::class]]);

    $this->ingestor = app(KnowledgeIngestor::class);
    $this->retriever = app(KnowledgeRetriever::class);
    $this->store = makeStore(['conversation_mode' => Store::MODE_KNOWLEDGE]);
    $this->product = Product::create([
        'store_id' => $this->store->id, 'domain_key' => 'oracle', 'name' => 'Lectura', 'description' => 'x', 'price' => 1, 'stock' => 1,
        'required_facts' => ['birth_date'], 'capability_key' => 'oracle.lucky_number',
    ]);
});

function keyedText($chunks): array
{
    return $chunks->map(fn ($chunk) => "{$chunk->heading}: {$chunk->content}")->all();
}

describe('CapabilityResult', function () {
    it('keeps the two-argument constructor and defaults to no keys', function () {
        expect((new CapabilityResult(['a' => 1], 'resumen'))->knowledgeKeys)->toBe([]);
    });

    it('keeps keys in order, trimmed, without blanks or duplicates', function () {
        expect((new CapabilityResult([], '', [' Clave B ', 'Clave A', '', 'Clave B', 42]))->knowledgeKeys)->toBe(['Clave B', 'Clave A']);
    });
});

describe('retrieveByKeys', function () {
    beforeEach(function () {
        $this->ingestor->ingest($this->store, 'oracle', null, 'Guía', 'markdown',
            "# Clave Alfa\n\nTexto alfa uno.\n\n# Clave Alfa extendida\n\nTexto alfa extendido.\n\n# Clave Beta\n\nTexto beta.");
    });

    it('returns only exact heading matches, ignoring case, accents and spacing', function () {
        $chunks = $this->retriever->retrieveByKeys($this->store, 'oracle', null, ['  CLAVE   álfa ']);

        expect(keyedText($chunks))->toBe(['Clave Alfa: Texto alfa uno.']);
    });

    it('orders by key priority and returns every chunk of a long section in document order', function () {
        $long = implode(' ', array_fill(0, 90, 'Frase larga de la sección gamma.'));
        $this->ingestor->ingest($this->store, 'oracle', null, 'Gamma', 'markdown', "# Clave Gamma\n\n{$long}");

        $chunks = $this->retriever->retrieveByKeys($this->store, 'oracle', null, ['Clave Gamma', 'Clave Beta']);
        $headings = $chunks->pluck('heading')->all();

        expect($headings[0])->toBe('Clave Gamma')
            ->and(end($headings))->toBe('Clave Beta')
            ->and(count(array_filter($headings, fn ($h) => $h === 'Clave Gamma')))->toBeGreaterThan(1)
            ->and($chunks->where('heading', 'Clave Gamma')->pluck('position')->all())
            ->toBe($chunks->where('heading', 'Clave Gamma')->pluck('position')->sort()->values()->all());
    });

    it('returns nothing without keys or without a matching heading', function () {
        expect($this->retriever->retrieveByKeys($this->store, 'oracle', null, []))->toBeEmpty()
            ->and($this->retriever->retrieveByKeys($this->store, 'oracle', null, ['', '   ']))->toBeEmpty()
            ->and($this->retriever->retrieveByKeys($this->store, 'oracle', null, ['Clave Inexistente']))->toBeEmpty();
    });

    it('never returns chunks of another store or domain', function () {
        $other = makeStore(['conversation_mode' => Store::MODE_KNOWLEDGE]);
        $this->ingestor->ingest($other, 'oracle', null, 'Ajena', 'markdown', "# Clave Beta\n\nBeta de otra tienda.");
        $this->ingestor->ingest($this->store, 'finance', null, 'Finanzas', 'markdown', "# Clave Beta\n\nBeta de otro dominio.");

        expect(keyedText($this->retriever->retrieveByKeys($this->store, 'oracle', null, ['Clave Beta'])))->toBe(['Clave Beta: Texto beta.']);
    });

    it('includes the current product chunks first, then the domain ones, never another product', function () {
        $otherProduct = Product::create(['store_id' => $this->store->id, 'domain_key' => 'oracle', 'name' => 'Otra', 'description' => 'x', 'price' => 1, 'stock' => 1]);
        $this->ingestor->ingest($this->store, 'oracle', $this->product, 'Propia', 'markdown', "# Clave Beta\n\nBeta del producto.");
        $this->ingestor->ingest($this->store, 'oracle', $otherProduct, 'De otro', 'markdown', "# Clave Beta\n\nBeta de otro producto.");

        expect(keyedText($this->retriever->retrieveByKeys($this->store, 'oracle', $this->product, ['Clave Beta'])))
            ->toBe(['Clave Beta: Beta del producto.', 'Clave Beta: Texto beta.'])
            ->and(keyedText($this->retriever->retrieveByKeys($this->store, 'oracle', null, ['Clave Beta'])))
            ->toBe(['Clave Beta: Texto beta.']);
    });

    it('ignores retired sources and serves the current version', function () {
        $v1 = $this->ingestor->ingest($this->store, 'oracle', null, 'Delta', 'markdown', "# Clave Delta\n\nDelta antigua.", 'delta.md');
        $this->ingestor->ingest($this->store, 'oracle', null, 'Delta', 'markdown', "# Clave Delta\n\nDelta nueva.", 'delta.md');

        expect($v1->fresh()->status)->toBe('retired')
            ->and(keyedText($this->retriever->retrieveByKeys($this->store, 'oracle', null, ['Clave Delta'])))->toBe(['Clave Delta: Delta nueva.']);

        $this->ingestor->retire($v1->fresh()->newQuery()->where('origin', 'delta.md')->where('status', 'active')->sole());
        expect($this->retriever->retrieveByKeys($this->store, 'oracle', null, ['Clave Delta']))->toBeEmpty();
    });

    it('caps the number of keyed fragments', function () {
        $markdown = collect(range(1, 15))->map(fn ($i) => "# Clave {$i}\n\nTexto {$i}.")->implode("\n\n");
        $this->ingestor->ingest($this->store, 'oracle', null, 'Muchas', 'markdown', $markdown);

        expect($this->retriever->retrieveByKeys($this->store, 'oracle', null, collect(range(1, 15))->map(fn ($i) => "Clave {$i}")->all()))->toHaveCount(12);
    });
});

describe('through the conversation', function () {
    beforeEach(function () {
        // The fictitious capability names its knowledge entry: "Clave {lucky number}".
        app()->bind(LuckyNumberCapability::class, fn () => new class extends LuckyNumberCapability
        {
            public function run(array $facts): CapabilityResult
            {
                $result = parent::run($facts);

                return new CapabilityResult($result->data, $result->summary, ['Clave '.$result->data['lucky_number']]);
            }
        });
        // The registry already resolved the original capability (product save validation).
        app()->forgetInstance(DomainRegistry::class);

        $this->ingestor->ingest($this->store, 'oracle', null, 'Guía', 'markdown',
            "# Clave 1\n\nInterpretación del uno.\n\n# Clave 2\n\nInterpretación del dos.\n\n# Clave 1 avanzada\n\nInterpretación avanzada.");
    });

    function keyedJob(Store $store, string $body): void
    {
        ProcessWhatsAppMessage::dispatchSync($store, '573008880000', $body, 'wamid.in.'.uniqid());
        test()->travel(1)->seconds();
    }

    function keyedPrompt(): string
    {
        return collect(mainChatRequests())->last()->data()['messages'][0]['content'];
    }

    it('gives the LLM exactly the entries named by the result, not what the message matches', function () {
        fakeExternalApis();

        keyedJob($this->store, 'Quiero mi Lectura');
        keyedJob($this->store, '15/03/1990'); // lucky number 1
        keyedJob($this->store, '¿Y la clave 2 o la clave 1 avanzada?');

        expect(keyedPrompt())
            ->toContain("Clave 1\nInterpretación del uno.")
            ->not->toContain('Interpretación del dos.')
            ->not->toContain('Interpretación avanzada.');
    });

    it('keeps the keys when the cached result is reused', function () {
        fakeExternalApis();

        keyedJob($this->store, 'Quiero mi Lectura');
        keyedJob($this->store, '15/03/1990');
        keyedJob($this->store, '¿Algo más?');

        expect(ComputedResult::sole()->payload['knowledge_keys'])->toBe(['Clave 1'])
            ->and(keyedPrompt())->toContain('Interpretación del uno.');
    });

    it('searches with the message as before when the result names no keys', function () {
        app()->bind(LuckyNumberCapability::class, fn () => new LuckyNumberCapability);
        app()->forgetInstance(DomainRegistry::class);
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
            if ($event->message === 'PIPELINE_ANSWERED') {
                $logged[] = $event->context['knowledge_retrieval'];
            }
        });
        fakeExternalApis();

        keyedJob($this->store, 'Quiero mi Lectura');
        keyedJob($this->store, '15/03/1990');
        keyedJob($this->store, '¿Qué dice la clave 2?');

        expect(keyedPrompt())->toContain('Interpretación del dos.')
            ->and($logged)->toBe(['message', 'message']);
    });
});
