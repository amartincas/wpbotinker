<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'store_id',
    'name',
    'description',
    'price',
    'stock',
    'type',
    'ai_sales_strategy',
    'faq_context',
    'required_customer_info',
    'meta_ad_ids',
    'domain_key',
    'aliases',
    'required_facts',
])]
class Product extends Model
{
    use HasFactory;

    /**
     * Stock interpretation depends on product type:
     * - 'product': stock is the quantity available
     * - 'service': stock is a boolean (1 = accepting clients, 0 = fully booked)
     */

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'stock' => 'integer',
            'type' => 'string',
            'meta_ad_ids' => 'array',
            'aliases' => 'array',
            'required_facts' => 'array',
        ];
    }

    /**
     * Check if the product is accepting orders (for services: accepting clients).
     */
    public function isAvailable(): bool
    {
        if ($this->type === 'service') {
            return $this->stock === 1;
        }

        return $this->stock > 0;
    }

    /**
     * Get stock label based on product type.
     */
    public function getStockLabel(): string
    {
        if ($this->type === 'service') {
            return $this->stock === 1 ? 'Accepting Clients' : 'Fully Booked';
        }

        if ($this->stock <= 0) {
            return 'Out of Stock';
        }

        if ($this->stock < 5) {
            return 'Low Stock';
        }

        return 'In Stock';
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * Get all images for this product.
     */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class);
    }

    /**
     * Get the primary image for this product, or the first available.
     */
    public function getPrimaryImage(): ?ProductImage
    {
        return $this->images()
            ->where('is_primary', true)
            ->first() ?? $this->images()->first();
    }
}
