<?php

namespace App\Platform\Conversation;

use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * Outcome of ProductResolver: a product to talk about, a set of options the
 * customer must choose from, or nothing (the store has no domain products).
 */
final class ProductResolution
{
    /**
     * @param  Collection<int, Product>  $options
     */
    private function __construct(
        public readonly ?Product $product,
        public readonly Collection $options,
    ) {}

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
