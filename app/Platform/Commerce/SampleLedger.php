<?php

namespace App\Platform\Commerce;

use App\Models\Customer;
use App\Models\Product;
use App\Models\SampleDelivery;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reserves each free reply of a product before it is sent, so a customer
 * gets at most limit() of them per product even under concurrent requests:
 * the unique customer/product row is created for the first one and its
 * counter is advanced under a row lock for the next ones. A reservation
 * that could not be delivered is released: it gives back its reply, and
 * removes the row when it was the only one. The release touches only that
 * same row, never one created later by another operation.
 *
 * A reservation made for an inbound message (its WhatsApp id) is kept for
 * that message until it is delivered (settle()) or released: a retry of the
 * same message, even after the worker died before sending, gets the same
 * reservation back instead of spending another free reply. Another message
 * never gets it.
 */
class SampleLedger
{
    /** How long a message's reservation waits for its retry: beyond the job's retry window. */
    private const KEPT_MINUTES = 15;

    /**
     * Free replies per customer and product (0: none): what the product's
     * active funnel configures, or the Core's global number without one.
     */
    public static function limit(?Product $product = null): int
    {
        $funnel = $product?->activeFunnel();

        return $funnel !== null
            ? $funnel->funnelDefinition()->freeRepliesLimit
            : max(0, (int) config('botinker.free_interactions.max', 3));
    }

    /** The free replies the customer has left for the product (0 when it offers none). */
    public function remaining(Customer $customer, Product $product): int
    {
        if (! $product->offersSample()) {
            return 0;
        }

        return max(0, self::limit($product) - (int) SampleDelivery::where('customer_id', $customer->id)->where('product_id', $product->id)->value('turns_used'));
    }

    /**
     * The reservation, its turns_used being the reply now reserved; null
     * when every free reply was already given or reserved. With the inbound
     * message's id, the reservation that message already holds, if any.
     */
    public function reserve(Customer $customer, Product $product, ?string $inboundWamid = null): ?SampleDelivery
    {
        if (! $product->offersSample()) {
            return null;
        }

        if ($inboundWamid !== null && ($kept = $this->keptFor($customer, $product, $inboundWamid)) !== null) {
            return $kept;
        }

        return DB::transaction(function () use ($customer, $product, $inboundWamid) {
            $delivery = SampleDelivery::createOrFirst(
                ['customer_id' => $customer->id, 'product_id' => $product->id],
                ['capability_key' => $product->sample_capability_key, 'turns_used' => 1],
            );

            if (! $delivery->wasRecentlyCreated) {
                $locked = SampleDelivery::whereKey($delivery->id)->lockForUpdate()->first();

                if ($locked === null || $locked->turns_used >= self::limit($product)) {
                    return null;
                }

                SampleDelivery::whereKey($locked->id)->update(['turns_used' => $locked->turns_used + 1, 'updated_at' => now()]);
                $delivery = $locked->setRawAttributes(['turns_used' => $locked->turns_used + 1] + $locked->getAttributes(), sync: true);
            }

            // Kept before the commit: if the process dies in between, the row says it was never taken.
            if ($inboundWamid !== null) {
                Cache::put($this->keptKey($customer->id, $product->id, $inboundWamid), ['id' => $delivery->id, 'turns_used' => (int) $delivery->turns_used], now()->addMinutes(self::KEPT_MINUTES));
            }

            return $delivery;
        });
    }

    /** Whether this message already holds a reservation, still there as it was taken (see reserve()). */
    public function holds(Customer $customer, Product $product, ?string $inboundWamid): bool
    {
        return $inboundWamid !== null && $this->keptFor($customer, $product, $inboundWamid) !== null;
    }

    /**
     * The message's reply went out: its reservation is spent for good, and a
     * later delivery of the same message never reuses it. Called right after
     * the send, it never fails: a forgotten key only expires on its own.
     */
    public function settle(Customer $customer, Product $product, ?string $inboundWamid): void
    {
        if ($inboundWamid === null) {
            return;
        }

        try {
            Cache::forget($this->keptKey($customer->id, $product->id, $inboundWamid));
        } catch (Throwable $e) {
            Log::warning('SAMPLE_RESERVATION_NOT_SETTLED', ['customer_id' => $customer->id, 'product_id' => $product->id, 'error' => $e::class]);
        }
    }

    public function release(SampleDelivery $reservation, ?string $inboundWamid = null): void
    {
        if ($inboundWamid !== null) {
            Cache::forget($this->keptKey($reservation->customer_id, $reservation->product_id, $inboundWamid));
        }

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

    /**
     * The reservation the message holds, if it is still there as it was
     * taken: a row released or gone since (its counter below the turn kept)
     * holds nothing.
     */
    private function keptFor(Customer $customer, Product $product, string $inboundWamid): ?SampleDelivery
    {
        $key = $this->keptKey($customer->id, $product->id, $inboundWamid);
        $kept = Cache::get($key);

        if (! is_array($kept)) {
            return null;
        }

        $delivery = SampleDelivery::whereKey($kept['id'] ?? 0)->where('customer_id', $customer->id)->where('product_id', $product->id)->first();

        if ($delivery === null || (int) $delivery->turns_used < (int) ($kept['turns_used'] ?? 0)) {
            Cache::forget($key);

            return null;
        }

        return $delivery->setRawAttributes(['turns_used' => (int) $kept['turns_used']] + $delivery->getAttributes(), sync: true);
    }

    private function keptKey(int $customerId, int $productId, string $inboundWamid): string
    {
        return "sample-reservation:{$customerId}:{$productId}:".hash('sha256', $inboundWamid);
    }
}
