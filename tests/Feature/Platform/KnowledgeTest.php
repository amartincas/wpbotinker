<?php

/*
 * Knowledge sources and chunks (phase B): ingestion, versions, retirement
 * and retrieval strictly filtered by store, domain and product.
 */

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeSource;
use App\Models\Product;
use App\Models\Store;
use App\Platform\Knowledge\KnowledgeIngestor;
use App\Platform\Knowledge\KnowledgeRetriever;
use Tests\Support\Domains\FinanceTestDomain;
use Tests\Support\Domains\OracleTestDomain;

beforeEach(function () {
    config(['botinker.domains' => ['oracle' => OracleTestDomain::class, 'finance' => FinanceTestDomain::class]]);

    $this->ingestor = app(KnowledgeIngestor::class);
    $this->retriever = app(KnowledgeRetriever::class);
    $this->store = makeStore(['conversation_mode' => Store::MODE_KNOWLEDGE]);
    $this->chart = knowledgeProduct($this->store, 'oracle', 'Carta astral');
    $this->sign = knowledgeProduct($this->store, 'oracle', 'Tu signo');
});

function knowledgeProduct(Store $store, string $domain, string $name): Product
{
    return Product::create(['store_id' => $store->id, 'domain_key' => $domain, 'name' => $name, 'description' => 'x', 'price' => 1, 'stock' => 1, 'type' => 'service']);
}

function retrievedText($chunks): string
{
    return $chunks->map(fn (KnowledgeChunk $chunk) => $chunk->heading.' '.$chunk->content)->implode(' | ');
}

describe('ingestion', function () {
    it('keeps the original and splits Markdown into chunks under their heading', function () {
        $markdown = "# Planetas\n\nMarte rige la acción.\n\nVenus rige el afecto.\n\n## Casas\n\nLa casa 8 trata de recursos compartidos.";

        $source = $this->ingestor->ingest($this->store, 'oracle', null, 'Guía', 'markdown', $markdown, 'guia.md', 'CC-BY');

        expect($source->only(['version', 'status', 'origin', 'license', 'language']))
            ->toBe(['version' => 1, 'status' => 'active', 'origin' => 'guia.md', 'license' => 'CC-BY', 'language' => 'es'])
            ->and($source->original_content)->toBe($markdown)
            ->and($source->content_hash)->toHaveLength(64)
            ->and($source->chunks()->orderBy('position')->get(['heading', 'content'])->toArray())->toBe([
                ['heading' => 'Planetas', 'content' => "Marte rige la acción.\n\nVenus rige el afecto."],
                ['heading' => 'Casas', 'content' => 'La casa 8 trata de recursos compartidos.'],
            ]);
    });

    it('turns HTML into text with headings and drops scripts', function () {
        $html = '<h2>Lunas</h2><p>La luna&nbsp;llena ilumina.</p><script>alert("x")</script><p>Fin.</p>';

        $source = $this->ingestor->ingest($this->store, 'oracle', null, 'Web', 'html', $html, 'https://example.test/lunas');

        $chunk = $source->chunks()->sole();
        expect($chunk->heading)->toBe('Lunas')
            ->and($chunk->content)->toContain('La luna llena ilumina.')
            ->and($chunk->content)->toContain('Fin.')
            ->and($chunk->content)->not->toContain('alert');
    });

    it('splits a long section into chunks of bounded size', function () {
        $long = implode(' ', array_fill(0, 120, 'Esta es una frase de prueba bastante larga.'));

        $source = $this->ingestor->ingest($this->store, 'oracle', null, 'Larga', 'text', $long);

        expect($source->chunks()->count())->toBeGreaterThan(1)
            ->and($source->chunks()->get()->every(fn ($chunk) => mb_strlen($chunk->content) <= 1200))->toBeTrue();
    });

    it('does not store the same content twice in the same scope', function () {
        $first = $this->ingestor->ingest($this->store, 'oracle', null, 'A', 'text', 'Marte rige la acción.');
        $again = $this->ingestor->ingest($this->store, 'oracle', null, 'B', 'text', "  Marte rige la acción.  \n");

        expect($again->id)->toBe($first->id)
            ->and(KnowledgeSource::count())->toBe(1)
            ->and(KnowledgeChunk::count())->toBe(1);
    });

    it('versions a source by origin and retires the previous version', function () {
        $v1 = $this->ingestor->ingest($this->store, 'oracle', null, 'Guía', 'text', 'Marte rige la guerra antigua.', 'guia.md');
        $v2 = $this->ingestor->ingest($this->store, 'oracle', null, 'Guía', 'text', 'Marte rige la acción moderna.', 'guia.md');

        expect($v2->version)->toBe(2)
            ->and($v1->fresh()->status)->toBe('retired')
            ->and($v1->fresh()->retired_at)->not->toBeNull();

        $found = retrievedText($this->retriever->retrieve($this->store, 'oracle', null, '¿Qué rige Marte?'));
        expect($found)->toContain('acción moderna')->not->toContain('guerra antigua');
    });

    it('stops retrieving a retired source', function () {
        $source = $this->ingestor->ingest($this->store, 'oracle', null, 'Guía', 'text', 'Marte rige la acción.');
        $this->ingestor->retire($source);

        expect($this->retriever->retrieve($this->store, 'oracle', null, 'Marte'))->toBeEmpty();
    });

    it('validates the scope of a source', function () {
        $otherStore = makeStore();
        $financeProduct = knowledgeProduct($this->store, 'finance', 'Presupuesto');

        expect(fn () => $this->ingestor->ingest($this->store, 'astrology', null, 'X', 'text', 'texto'))
            ->toThrow(InvalidArgumentException::class, 'No domain module registered')
            ->and(fn () => $this->ingestor->ingest($otherStore, 'oracle', $this->chart, 'X', 'text', 'texto'))
            ->toThrow(InvalidArgumentException::class, 'does not belong')
            ->and(fn () => $this->ingestor->ingest($this->store, 'oracle', $financeProduct, 'X', 'text', 'texto'))
            ->toThrow(InvalidArgumentException::class, 'does not belong')
            ->and(fn () => $this->ingestor->ingest($this->store, 'oracle', null, 'X', 'pdf', 'texto'))
            ->toThrow(InvalidArgumentException::class, 'Unsupported knowledge source type')
            ->and(fn () => $this->ingestor->ingest($this->store, 'oracle', null, 'X', 'html', '<p> </p>'))
            ->toThrow(InvalidArgumentException::class, 'no text content');
    });
});

describe('retrieval', function () {
    beforeEach(function () {
        $this->ingestor->ingest($this->store, 'oracle', null, 'General', 'markdown', "# Marte\n\nMarte rige la energía del dominio oráculo.");
        $this->ingestor->ingest($this->store, 'oracle', $this->chart, 'Carta', 'markdown', "# Casas\n\nLa energía de la casa 8 en la carta astral.");
        $this->ingestor->ingest($this->store, 'oracle', $this->sign, 'Signo', 'markdown', "# Aries\n\nLa energía de Aries en tu signo.");
        $this->ingestor->ingest($this->store, 'finance', null, 'Finanzas', 'markdown', "# Ahorro\n\nLa energía del ahorro mensual.");
        $other = makeStore(['conversation_mode' => Store::MODE_KNOWLEDGE]);
        $this->ingestor->ingest($other, 'oracle', null, 'Ajena', 'markdown', "# Ajena\n\nLa energía de otra tienda.");
    });

    it('returns domain chunks plus those of the current product only', function () {
        $found = retrievedText($this->retriever->retrieve($this->store, 'oracle', $this->chart, 'energía', 10));

        expect($found)->toContain('dominio oráculo')
            ->toContain('casa 8')
            ->not->toContain('Aries')
            ->not->toContain('ahorro')
            ->not->toContain('otra tienda');
    });

    it('returns only domain-level chunks when no product is active', function () {
        $found = retrievedText($this->retriever->retrieve($this->store, 'oracle', null, 'energía', 10));

        expect($found)->toContain('dominio oráculo')->not->toContain('casa 8')->not->toContain('Aries');
    });

    it('never returns chunks of another store or domain', function () {
        expect(retrievedText($this->retriever->retrieve($this->store, 'finance', null, 'energía', 10)))
            ->toContain('ahorro')->not->toContain('oráculo')->not->toContain('otra tienda');
    });

    it('matches regardless of case and accents and ranks heading matches first', function () {
        $chunks = $this->retriever->retrieve($this->store, 'oracle', $this->chart, 'CASAS energia', 10);

        expect($chunks->first()->heading)->toBe('Casas');
    });

    it('returns nothing for a question without meaningful words', function () {
        expect($this->retriever->retrieve($this->store, 'oracle', $this->chart, '¿qué es?'))->toBeEmpty();
    });
});
