<?php

namespace App\Platform\Knowledge;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeSource;
use App\Models\Product;
use App\Models\Store;
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

        return KnowledgeChunk::query()
            ->where('store_id', $store->id)
            ->where('domain_key', $domainKey)
            ->where(fn ($query) => $query->whereNull('product_id')->when($product, fn ($q) => $q->orWhere('product_id', $product->id)))
            ->whereHas('source', fn ($query) => $query->where('status', KnowledgeSource::STATUS_ACTIVE))
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
