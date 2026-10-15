<?php

namespace App\Platform\Commerce;

use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\SampleDelivery;
use App\Models\Subscription;
use InvalidArgumentException;

/**
 * The single place that decides commercial access to a product's protected
 * service, for every domain and topic alike. It reads persisted state only
 * (product access mode, subscription status and expiry, open payments,
 * sample deliveries): never the LLM, the customer's text or an ad
 * reference. Asked again at every entry to the protected service.
 *
 * A pending or "not reflected" payment never grants access; only a
 * subscription does, and only a confirmed payment creates or extends one
 * (PaymentReview).
 */
class AccessGate
{
    public function decide(Customer $customer, Product $product): AccessDecision
    {
        if ($customer->store_id !== $product->store_id) {
            throw new InvalidArgumentException("Customer [{$customer->id}] and product [{$product->id}] belong to different stores.");
        }

        if (! $product->requiresSubscription()) {
            return AccessDecision::allow(AccessDecision::OPEN);
        }

        $subscription = Subscription::where('customer_id', $customer->id)->where('product_id', $product->id)->first();

        if ($subscription?->isValidAt()) {
            return AccessDecision::allow(AccessDecision::SUBSCRIBED, $subscription);
        }

        $reason = match (true) {
            $subscription === null => AccessDecision::NO_SUBSCRIPTION,
            $subscription->status === Subscription::STATUS_REVOKED => AccessDecision::REVOKED,
            default => AccessDecision::EXPIRED,
        };

        $openPayment = Payment::where('customer_id', $customer->id)
            ->where('product_id', $product->id)
            ->whereIn('status', Payment::OPEN_STATUSES)
            ->latest('id')
            ->first();

        $sampleAvailable = $product->offersSample()
            && SampleDelivery::where('customer_id', $customer->id)->where('product_id', $product->id)->doesntExist();

        return AccessDecision::deny($reason, $subscription, $openPayment, $sampleAvailable);
    }
}
