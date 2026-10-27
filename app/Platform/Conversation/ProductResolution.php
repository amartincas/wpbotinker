<?php

namespace App\Platform\Conversation;

use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * Outcome of ProductResolver: a product to talk about, a set of options the
 * customer must choose from, or nothing (the store has no domain products).
 *
 * "Unidentified" is a set of options too (the whole catalog), but nothing in
 * the message pointed to any of them: the pipeline may ask what the
 * customer wants instead of listing them.
 */
final class ProductResolution
{
    /**
     * @param  Collection<int, Product>  $options
     */
    private function __construct(
        public readonly ?Product $product,
        public readonly Collection $options,
        public readonly bool $unidentified = false,
        public readonly bool $answersClarification = false,
    ) {}

    /**
     * The customer picked this product from the options offered (by number
     * or by name alone): the message is the choice, not a query.
     */
    public static function chosen(Product $product): self
    {
        return new self($product, collect(), answersClarification: true);
    }

    /**
     * @param  Collection<int, Product>  $catalog
     */
    public static function unidentified(Collection $catalog): self
    {
        return new self(null, $catalog->values(), true);
    }

    public static function selected(Product $product): self
    {
        return new self($product, collect());
    }

    /**
     * @param  Collection<int, Product>  $options
     */
    public static function clarify(Collection $options): self
    {
        return new self(null, $options->values());
    }

    public static function none(): self
    {
        return new self(null, collect());
    }

    public function needsClarification(): bool
    {
        return $this->options->isNotEmpty();
    }
}
