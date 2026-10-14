<?php

namespace App\Platform\Conversation;

use App\Models\Conversation;
use App\Models\Product;
use App\Models\Store;
use App\Platform\Domains\DomainRegistry;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Decides which product (and therefore which domain) a customer message is
 * about. When the message is ambiguous it never picks one arbitrarily: it
 * returns the options so the pipeline can ask the customer.
 *
 * Order: ad product → answer to a pending clarification → products named in
 * the message → the conversation's active product (unless the message points
 * to another domain) → the only product of the store → domain vocabulary → ask.
 */
class ProductResolver
{
    public const PENDING_CLARIFICATION = 'product_clarification';

    private const MAX_OPTIONS = 10;

    public function __construct(private readonly DomainRegistry $domains) {}

    public function resolve(Store $store, Conversation $conversation, string $message, ?int $adProductId = null): ProductResolution
    {
        $catalog = $this->catalog($store);

        if ($catalog->isEmpty()) {
            return ProductResolution::none();
        }

        if ($adProductId !== null && ($product = $catalog->firstWhere('id', $adProductId))) {
            return ProductResolution::selected($product);
        }

        $text = $this->normalize($message);

        if ($product = $this->chosenOption($conversation, $catalog, $text)) {
            return ProductResolution::selected($product);
        }

        $mentioned = $this->mentioned($catalog, $text);

        if ($mentioned->count() === 1) {
            return ProductResolution::selected($mentioned->first());
        }

        if ($mentioned->count() > 1) {
            return ProductResolution::clarify($mentioned);
        }

        $signalled = $this->signalledDomains($store, $catalog, $text);

        if ($conversation->current_product_id && ($active = $catalog->firstWhere('id', $conversation->current_product_id))) {
            return $this->fromActiveProduct($active, $catalog, $signalled);
        }

        if ($catalog->count() === 1) {
            return ProductResolution::selected($catalog->first());
        }

        $byVocabulary = $catalog->whereIn('domain_key', $signalled->all())->values();

        if ($byVocabulary->count() === 1) {
            return ProductResolution::selected($byVocabulary->first());
        }

        return ProductResolution::clarify(($byVocabulary->isNotEmpty() ? $byVocabulary : $catalog)->take(self::MAX_OPTIONS));
    }

    /** A product of this store's domain catalog, or null (e.g. deleted since). */
    public function catalogProduct(Store $store, int $productId): ?Product
    {
        return $this->catalog($store)->firstWhere('id', $productId);
    }

    /**
     * Products of this store that belong to a registered module or to one
     * of the store's active topics. Any other domain_key is ignored, never
     * guessed.
     *
     * @return Collection<int, Product>
     */
    private function catalog(Store $store): Collection
    {
        return Product::where('store_id', $store->id)
            ->whereIn('domain_key', $this->domains->keys($store->id))
            ->orderBy('id')
            ->get();
    }

    /**
     * A numbered reply ("2") to the options offered in the last clarification.
     */
    private function chosenOption(Conversation $conversation, Collection $catalog, string $text): ?Product
    {
        $pending = $conversation->pending_state;

        if (($pending['type'] ?? null) !== self::PENDING_CLARIFICATION || ! preg_match('/^\s*(\d{1,2})\s*\.?\s*$/', $text, $match)) {
            return null;
        }

        $productId = $pending['product_ids'][(int) $match[1] - 1] ?? null;

        return $productId ? $catalog->firstWhere('id', $productId) : null;
    }

    /**
     * Products whose name or an alias appears in the message. When one match
     * is contained in another ("carta" vs "carta natal"), the longer one wins.
     *
     * @return Collection<int, Product>
     */
    private function mentioned(Collection $catalog, string $text): Collection
    {
        $matches = $catalog
            ->map(function (Product $product) use ($text) {
                $term = collect([$product->name, ...($product->aliases ?? [])])
                    ->map(fn ($term) => $this->normalize((string) $term))
                    ->filter(fn ($term) => mb_strlen($term) >= 3 && $this->containsWords($text, $term))
                    ->sortByDesc(fn ($term) => mb_strlen($term))
                    ->first();

                return $term ? ['product' => $product, 'term' => $term] : null;
            })
            ->filter();

        return $matches
            ->reject(fn ($match) => $matches->contains(fn ($other) => $other['term'] !== $match['term'] && str_contains($other['term'], $match['term'])))
            ->pluck('product')
            ->values();
    }

    /**
     * An active product holds the conversation unless the message clearly
     * points to another domain: then the topic moves there (or the customer
     * is asked, if that is ambiguous). A follow-up with no domain signal, or
     * with only the active domain's signal, stays on the active product.
     *
     * @param  Collection<int, string>  $signalled
     */
    private function fromActiveProduct(Product $active, Collection $catalog, Collection $signalled): ProductResolution
    {
        $otherDomains = $signalled->reject(fn (string $key) => $key === $active->domain_key);

        if ($otherDomains->isEmpty()) {
            return ProductResolution::selected($active);
        }

        $candidates = $catalog->whereIn('domain_key', $otherDomains->all())->values();

        // The message speaks to the active domain AND another one: ask.
        if ($signalled->contains($active->domain_key)) {
            return ProductResolution::clarify(collect([$active])->merge($candidates)->take(self::MAX_OPTIONS));
        }

        return $candidates->count() === 1
            ? ProductResolution::selected($candidates->first())
            : ProductResolution::clarify($candidates->take(self::MAX_OPTIONS));
    }

    /**
     * Domains (among the store's catalog) whose vocabulary appears in the message.
     *
     * @return Collection<int, string>
     */
    private function signalledDomains(Store $store, Collection $catalog, string $text): Collection
    {
        return $catalog->pluck('domain_key')->unique()
            ->filter(fn (string $key) => collect($this->domains->get($key, $store->id)->vocabulary())
                ->contains(fn ($term) => $this->containsWords($text, $this->normalize($term))))
            ->values();
    }

    private function normalize(string $text): string
    {
        return Str::ascii(mb_strtolower(trim($text)));
    }

    /** Whole-word match, so "signo" does not match inside "designo". */
    private function containsWords(string $text, string $term): bool
    {
        return $term !== '' && preg_match('/\b'.preg_quote($term, '/').'\b/', $text) === 1;
    }
}
