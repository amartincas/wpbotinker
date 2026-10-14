<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSubscriptionProduct;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/**
 * Record that a customer received the free sample of a product; written
 * only once the sample was actually delivered. Unique per customer and
 * product, append-only, with no status or expiry: it never grants the
 * paid service, that is what subscriptions are for.
 */
#[Fillable(['store_id', 'customer_id', 'product_id', 'capability_key', 'delivered_at'])]
class SampleDelivery extends Model
{
    use BelongsToSubscriptionProduct;

    protected function casts(): array
    {
        return [
            'delivered_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $delivery) {
            if (! Product::find($delivery->product_id)?->offersSample()) {
                throw new InvalidArgumentException("Product [{$delivery->product_id}] offers no sample.");
            }

            $delivery->delivered_at ??= now();
        });

        static::updating(function () {
            throw new InvalidArgumentException('A sample delivery is a record; it never changes.');
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
