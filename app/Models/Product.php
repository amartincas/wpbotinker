<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

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
    'capability_key',
    'access_mode',
    'sample_instructions',
    'sample_capability_key',
])]
class Product extends Model
{
    use HasFactory;

    /** No access control: anyone may use it. */
    public const ACCESS_OPEN = 'open';

    /** The paid service needs an active subscription of the customer. */
    public const ACCESS_SUBSCRIPTION = 'subscription';

    protected $attributes = [
        'access_mode' => self::ACCESS_OPEN,
    ];

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

    protected static function booted(): void
    {
        // Commercial configuration that needs no domain module. Domain
        // checks (facts, capabilities) run in AppServiceProvider.
        static::saving(function (self $product) {
            if (! in_array($product->access_mode, [self::ACCESS_OPEN, self::ACCESS_SUBSCRIPTION], true)) {
                throw new InvalidArgumentException("Unknown access mode [{$product->access_mode}].");
            }

            if ($product->requiresSubscription() && blank($product->domain_key)) {
                throw new InvalidArgumentException('Only a domain product can require a subscription.');
            }

            // A sample configured on an open product is kept but unused:
            // offersSample() requires a subscription. What the sample offers is defined by its instructions; a
            // capability alone would leave its content undefined.
            if (filled($product->sample_capability_key) && blank($product->sample_instructions)) {
                throw new InvalidArgumentException('A sample capability needs sample instructions.');
            }
        });
    }

    public function requiresSubscription(): bool
    {
        return $this->access_mode === self::ACCESS_SUBSCRIPTION;
    }

    /** The sample exists when its instructions are configured. */
    public function offersSample(): bool
    {
        return $this->requiresSubscription() && filled($this->sample_instructions);
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

    public function plans(): HasMany
    {
        return $this->hasMany(Plan::class);
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
