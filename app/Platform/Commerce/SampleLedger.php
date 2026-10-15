<?php

namespace App\Platform\Commerce;

use App\Models\Customer;
use App\Models\Product;
use App\Models\SampleDelivery;
use Illuminate\Support\Facades\Log;

/**
 * Reserves the free sample of a product before it is sent, so it is given
 * once per customer and product even under concurrent requests (the unique
 * customer/product index decides the winner). A reservation that could not
 * be delivered is released; the release removes only that same row, never
 * one created later by another operation.
 */
class SampleLedger
{
    /** The reservation, or null when the sample was already given or reserved. */
    public function reserve(Customer $customer, Product $product): ?SampleDelivery
    {
        if (! $product->offersSample()) {
            return null;
        }

        $delivery = SampleDelivery::createOrFirst(
            ['customer_id' => $customer->id, 'product_id' => $product->id],
            ['capability_key' => $product->sample_capability_key],
        );

        return $delivery->wasRecentlyCreated ? $delivery : null;
    }

    public function release(SampleDelivery $reservation): void
    {
        $released = SampleDelivery::whereKey($reservation->id)
            ->where('customer_id', $reservation->customer_id)
            ->where('product_id', $reservation->product_id)
            ->delete();

        Log::warning('SAMPLE_RESERVATION_RELEASED', [
            'store_id' => $reservation->store_id,
            'customer_id' => $reservation->customer_id,
            'product_id' => $reservation->product_id,
            'released' => $released === 1,
        ]);
    }
}
