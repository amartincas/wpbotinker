<?php

namespace App\Platform\Knowledge;

use App\Models\KnowledgeSource;
use App\Models\Product;
use App\Models\Store;
use App\Platform\Domains\DomainRegistry;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Loads knowledge for a store and domain (optionally one product): keeps
 * the original, normalizes it to text and splits it into chunks.
 *
 * - Same content already active in the same scope → nothing new is stored.
 * - Same origin with different content → a new version; the previous one
 *   is retired, so it stops being retrieved.
 *
 * PDF is not supported yet (no parser dependency on purpose).
 */
class KnowledgeIngestor
{
    private const CHUNK_MAX_CHARS = 1200;

    public function __construct(private readonly DomainRegistry $domains) {}

    public function ingest(
        Store $store,
        string $domainKey,
        ?Product $product,
        string $title,
        string $sourceType,
        string $content,
        ?string $origin = null,
        ?string $license = null,
        string $language = 'es',
    ): KnowledgeSource {
        $this->domains->get($domainKey); // must be a registered, valid domain

        if (! in_array($sourceType, KnowledgeSource::TYPES, true)) {
            throw new InvalidArgumentException("Unsupported knowledge source type [{$sourceType}].");
        }

        if ($product && ($product->store_id !== $store->id || $product->domain_key !== $domainKey)) {
            throw new InvalidArgumentException("Product [{$product->id}] does not belong to store [{$store->id}] and domain [{$domainKey}].");
        }

        $text = $this->normalize($sourceType, $content);

        if ($text === '') {
            throw new InvalidArgumentException('Knowledge source has no text content.');
        }

        $hash = hash('sha256', $text);

        return DB::transaction(function () use ($store, $domainKey, $product, $title, $sourceType, $content, $origin, $license, $language, $text, $hash) {
            // One writer at a time per store, so versions cannot collide.
            Store::whereKey($store->id)->lockForUpdate()->first();

            $scope = fn () => KnowledgeSource::where('store_id', $store->id)
                ->where('domain_key', $domainKey)
                ->where('product_id', $product?->id);

            $duplicate = $scope()->where('status', KnowledgeSource::STATUS_ACTIVE)->where('content_hash', $hash)->first();

            if ($duplicate) {
                return $duplicate;
            }

            $previous = $origin !== null
                ? $scope()->where('origin', $origin)->where('status', KnowledgeSource::STATUS_ACTIVE)->first()
                : null;

            $previous?->update(['status' => KnowledgeSource::STATUS_RETIRED, 'retired_at' => now()]);

            $source = KnowledgeSource::create([
                'store_id' => $store->id,
                'domain_key' => $domainKey,
                'product_id' => $product?->id,
                'title' => $title,
                'source_type' => $sourceType,
                'origin' => $origin,
                'license' => $license,
                'language' => $language,
                'version' => $origin !== null ? (int) $scope()->where('origin', $origin)->max('version') + 1 : 1,
                'content_hash' => $hash,
                'original_content' => $content,
                'status' => KnowledgeSource::STATUS_ACTIVE,
                'ingested_at' => now(),
            ]);

            foreach ($this->chunk($text) as $position => [$heading, $body]) {
                $source->chunks()->create([
                    'store_id' => $store->id,
                    'domain_key' => $domainKey,
                    'product_id' => $product?->id,
                    'position' => $position,
                    'heading' => $heading,
                    'content' => $body,
                ]);
            }

            return $source;
        });
    }

    /** Retired sources keep their content but are never retrieved again. */
    public function retire(KnowledgeSource $source): void
    {
        $source->update(['status' => KnowledgeSource::STATUS_RETIRED, 'retired_at' => now()]);
    }

    /** HTML → text with "#" headings; Markdown and text are kept as they are. */
    private function normalize(string $sourceType, string $content): string
    {
        if ($sourceType === 'html') {
            $content = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $content);
            $content = preg_replace_callback('#<h([1-6])\b[^>]*>(.*?)</h\1>#is', fn ($m) => "\n\n".str_repeat('#', (int) $m[1]).' '.strip_tags($m[2])."\n\n", $content);
            $content = preg_replace('#<(br|/p|/li|/div|/tr)\b[^>]*>#i', "\n", $content);
            $content = html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        // \h also covers non-breaking spaces (e.g. a decoded &nbsp;).
        $lines = array_map(fn ($line) => trim(preg_replace('/\h+/u', ' ', $line)), preg_split('/\R/u', $content));

        return trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)));
    }

    /**
     * Sections start at Markdown headings; paragraphs are grouped up to
     * CHUNK_MAX_CHARS; each chunk keeps the heading of its section.
     *
     * @return list<array{0: ?string, 1: string}>
     */
    private function chunk(string $text): array
    {
        $chunks = [];
        $heading = null;
        $buffer = '';

        $flush = function () use (&$chunks, &$heading, &$buffer) {
            if (trim($buffer) !== '') {
                $chunks[] = [$heading, trim($buffer)];
            }
            $buffer = '';
        };

        foreach (preg_split("/\n{2,}/", $text) as $block) {
            $block = trim($block);

            if (preg_match('/^#{1,6}\s+(.+)$/u', $block, $m) && ! str_contains($block, "\n")) {
                $flush();
                $heading = mb_substr(trim($m[1]), 0, 255);

                continue;
            }

            foreach ($this->splitLong($block) as $piece) {
                if ($buffer !== '' && mb_strlen($buffer) + mb_strlen($piece) + 2 > self::CHUNK_MAX_CHARS) {
                    $flush();
                }
                $buffer .= ($buffer === '' ? '' : "\n\n").$piece;
            }
        }

        $flush();

        return $chunks;
    }

    /**
     * @return list<string>
     */
    private function splitLong(string $block): array
    {
        if (mb_strlen($block) <= self::CHUNK_MAX_CHARS) {
            return [$block];
        }

        $pieces = [];
        $current = '';

        foreach (preg_split('/(?<=[.!?])\s+/u', $block) as $sentence) {
            if ($current !== '' && mb_strlen($current) + mb_strlen($sentence) + 1 > self::CHUNK_MAX_CHARS) {
                $pieces[] = $current;
                $current = '';
            }
            $current .= ($current === '' ? '' : ' ').$sentence;
        }

        if ($current !== '') {
            $pieces[] = $current;
        }

        return array_merge(...array_map(fn ($piece) => mb_str_split($piece, self::CHUNK_MAX_CHARS), $pieces));
    }
}
