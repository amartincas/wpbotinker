<?php

namespace App\Platform\Commerce;

use App\Models\Customer;
use App\Models\Product;
use App\Models\SampleDelivery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Reserves each free reply of a product before it is sent, so a customer
 * gets at most limit() of them per product even under concurrent requests:
 * the unique customer/product row is created for the first one and its
 * counter is advanced under a row lock for the next ones. A reservation
 * that could not be delivered is released: it gives back its reply, and
 * removes the row when it was the only one. The release touches only that
 * same row, never one created later by another operation.
 */
class SampleLedger
{
    /** The Core's global number of free replies per customer and product (0: none). */
    public static function limit(): int
    {
        return max(0, (int) config('botinker.free_interactions.max', 3));
    }

    /**
     * The reservation, its turns_used being the reply now reserved; null
     * when every free reply was already given or reserved.
     */
    public function reserve(Customer $customer, Product $product): ?SampleDelivery
    {
        if (! $product->offersSample()) {
            return null;
        }

        return DB::transaction(function () use ($customer, $product) {
            $delivery = SampleDelivery::createOrFirst(
                ['customer_id' => $customer->id, 'product_id' => $product->id],
                ['capability_key' => $product->sample_capability_key, 'turns_used' => 1],
            );

            if ($delivery->wasRecentlyCreated) {
                return $delivery;
            }

            $locked = SampleDelivery::whereKey($delivery->id)->lockForUpdate()->first();

            if ($locked === null || $locked->turns_used >= self::limit()) {
                return null;
            }

            SampleDelivery::whereKey($locked->id)->update(['turns_used' => $locked->turns_used + 1, 'updated_at' => now()]);

            return $locked->setRawAttributes(['turns_used' => $locked->turns_used + 1] + $locked->getAttributes(), sync: true);
        });
    }

    public function release(SampleDelivery $reservation): void
    {
        $released = DB::transaction(function () use ($reservation) {
            $current = SampleDelivery::whereKey($reservation->id)
                ->where('customer_id', $reservation->customer_id)
                ->where('product_id', $reservation->product_id)
                ->lockForUpdate()
                ->first();

            if ($current === null) {
                return false;
            }

            return $current->turns_used <= 1
                ? SampleDelivery::whereKey($current->id)->delete() === 1
                : SampleDelivery::whereKey($current->id)->update(['turns_used' => $current->turns_used - 1, 'updated_at' => now()]) === 1;
        });

        Log::warning('SAMPLE_RESERVATION_RELEASED', [
            'store_id' => $reservation->store_id,
            'customer_id' => $reservation->customer_id,
            'product_id' => $reservation->product_id,
            'turn' => $reservation->turns_used,
            'released' => $released,
        ]);
    }
}
