<?php

namespace App\Platform\Knowledge;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeSource;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Chunks relevant to a question, strictly within one store and domain:
 * chunks of the domain (product_id null) plus those of the current product,
 * from active sources only. Never another store, domain or product.
 *
 * Lexical scoring in PHP, accent- and case-insensitive and identical on
 * every database. Enough for small curated corpora; a database full-text
 * index can replace the candidate query when corpora grow.
 */
class KnowledgeRetriever
{
    private const MAX_CANDIDATES = 2000;

    /** Upper bound of keyed fragments, so a result cannot flood the prompt. */
    private const MAX_KEYED_FRAGMENTS = 12;

    private const MIN_TERM_LENGTH = 3;

    private const STOPWORDS = [
        'que', 'como', 'para', 'por', 'con', 'una', 'uno', 'unos', 'unas', 'los', 'las', 'del', 'mis', 'tus',
        'sus', 'este', 'esta', 'ese', 'esa', 'son', 'hay', 'muy', 'mas', 'pero', 'cual', 'cuales', 'quiero',
        'saber', 'puedo', 'puede', 'tengo', 'tiene', 'sobre', 'cuando', 'donde', 'porque',
    ];

    /**
     * @return Collection<int, KnowledgeChunk> most relevant first
     */
    public function retrieve(Store $store, string $domainKey, ?Product $product, string $question, int $limit = 4): Collection
    {
        $terms = $this->terms($question);

        if ($terms === []) {
            return collect();
        }

        return $this->scope($store, $domainKey, $product)
            ->orderBy('id')
            ->limit(self::MAX_CANDIDATES)
            ->get()
            ->map(fn (KnowledgeChunk $chunk) => [$chunk, $this->score($chunk, $terms)])
            ->filter(fn ($scored) => $scored[1] > 0)
            ->sortByDesc(fn ($scored) => $scored[1])
            ->take($limit)
            ->map(fn ($scored) => $scored[0])
            ->values();
    }

    /**
     * Chunks whose heading is exactly one of $keys — ignoring only case,
     * accents and extra whitespace, never partial matches. Same scope as
     * retrieve(). Ordered by key priority; within a key, the current
     * product's chunks before the domain's, each in document order.
     *
     * @param  list<string>  $keys
     * @return Collection<int, KnowledgeChunk>
     */
    public function retrieveByKeys(Store $store, string $domainKey, ?Product $product, array $keys, int $limit = self::MAX_KEYED_FRAGMENTS): Collection
    {
        $priority = [];

        foreach ($keys as $key) {
            $normalized = $this->normalizeHeading((string) $key);

            if ($normalized !== '' && ! isset($priority[$normalized])) {
                $priority[$normalized] = count($priority);
            }
        }

        if ($priority === []) {
            return collect();
        }

        return $this->scope($store, $domainKey, $product)
            ->whereNotNull('heading')
            ->get()
            ->filter(fn (KnowledgeChunk $chunk) => isset($priority[$this->normalizeHeading($chunk->heading)]))
            ->sort(fn (KnowledgeChunk $a, KnowledgeChunk $b) => [
                $priority[$this->normalizeHeading($a->heading)], $a->product_id === null, $a->knowledge_source_id, $a->position,
            ] <=> [
                $priority[$this->normalizeHeading($b->heading)], $b->product_id === null, $b->knowledge_source_id, $b->position,
            ])
            ->take($limit)
            ->values();
    }

    /**
     * This store and domain; domain-level chunks plus the current product's;
     * active sources only.
     */
    private function scope(Store $store, string $domainKey, ?Product $product): Builder
    {
        return KnowledgeChunk::query()
            ->where('store_id', $store->id)
            ->where('domain_key', $domainKey)
            ->where(fn ($query) => $query->whereNull('product_id')->when($product, fn ($q) => $q->orWhere('product_id', $product->id)))
            ->whereHas('source', fn ($query) => $query->where('status', KnowledgeSource::STATUS_ACTIVE));
    }

    private function normalizeHeading(string $heading): string
    {
        return trim(preg_replace('/\s+/', ' ', Str::ascii(mb_strtolower($heading))));
    }

    /**
     * @param  list<string>  $terms
     */
    private function score(KnowledgeChunk $chunk, array $terms): int
    {
        $headingWords = $this->words((string) $chunk->heading);
        $contentWords = $this->words($chunk->content);

        $score = 0;

        foreach ($terms as $term) {
            $score += (isset($contentWords[$term]) ? 1 : 0) + (isset($headingWords[$term]) ? 2 : 0);
        }

        return $score;
    }

    /**
     * @return list<string>
     */
    private function terms(string $text): array
    {
        return array_values(array_filter(
            array_keys($this->words($text)),
            fn ($word) => mb_strlen($word) >= self::MIN_TERM_LENGTH && ! in_array($word, self::STOPWORDS, true),
        ));
    }

    /**
     * @return array<string, true>
     */
    private function words(string $text): array
    {
        $words = preg_split('/[^a-z0-9]+/', Str::ascii(mb_strtolower($text)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_fill_keys($words, true);
    }
}
