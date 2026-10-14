<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/**
 * Price and duration of a subscription product, managed in the panel.
 * Payments copy these values when they start, so a later edit never
 * changes what a customer already agreed to pay.
 */
#[Fillable(['store_id', 'product_id', 'name', 'price', 'currency', 'duration_days', 'is_active', 'sort_order'])]
class Plan extends Model
{
    public const MAX_DURATION_DAYS = 3650;

    protected $attributes = [
        'currency' => 'COP',
        'duration_days' => 30,
        'is_active' => true,
        'sort_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'duration_days' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $plan) {
            $product = Product::find($plan->product_id);

            if ($product === null || ! $product->requiresSubscription()) {
                throw new InvalidArgumentException('A plan belongs to an existing subscription product.');
            }

            if ($plan->store_id !== null && (int) $plan->store_id !== $product->store_id) {
                throw new InvalidArgumentException("Product [{$product->id}] does not belong to store [{$plan->store_id}].");
            }

            $plan->store_id = $product->store_id;
            $plan->currency = strtoupper(trim((string) $plan->currency));

            if (! preg_match('/^[A-Z]{3}$/', $plan->currency)) {
                throw new InvalidArgumentException('The plan currency must be a 3-letter ISO code.');
            }

            if (! is_numeric($plan->price) || (float) $plan->price <= 0) {
                throw new InvalidArgumentException('The plan price must be greater than zero.');
            }

            if ($plan->duration_days < 1 || $plan->duration_days > self::MAX_DURATION_DAYS) {
                throw new InvalidArgumentException('The plan duration must be between 1 and '.self::MAX_DURATION_DAYS.' days.');
            }
        });
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
