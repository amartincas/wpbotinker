<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSubscriptionProduct;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/**
 * Record of the free replies a customer got for a product: created with
 * the first one (delivered_at), its turns_used counting them up to the
 * Core's limit. Unique per customer and product, with no status or
 * expiry: it never grants the paid service, that is what subscriptions
 * are for. Only SampleLedger moves the counter, with row-level queries;
 * the model itself is never updated.
 */
#[Fillable(['store_id', 'customer_id', 'product_id', 'capability_key', 'turns_used', 'delivered_at'])]
class SampleDelivery extends Model
{
    use BelongsToSubscriptionProduct;

    protected function casts(): array
    {
        return [
            'turns_used' => 'integer',
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
