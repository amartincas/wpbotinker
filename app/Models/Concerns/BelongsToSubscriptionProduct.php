<?php

namespace App\Models\Concerns;

use App\Models\Customer;
use App\Models\Product;
use InvalidArgumentException;

/**
 * For commercial records of a customer and a subscription product
 * (payments, subscriptions, sample deliveries): the customer and the
 * product must belong to the same store, and store_id always follows the
 * product, whatever the caller passed.
 */
trait BelongsToSubscriptionProduct
{
    protected static function bootBelongsToSubscriptionProduct(): void
    {
        static::saving(function (self $model) {
            if ($model->exists && ! $model->isDirty(['store_id', 'customer_id', 'product_id'])) {
                return;
            }

            $product = Product::find($model->product_id);
            $customer = Customer::find($model->customer_id);

            if ($product === null || $customer === null) {
                throw new InvalidArgumentException(class_basename($model).' needs an existing customer and product.');
            }

            if ($customer->store_id !== $product->store_id) {
                throw new InvalidArgumentException("Customer [{$customer->id}] and product [{$product->id}] belong to different stores.");
            }

            if ($model->store_id !== null && (int) $model->store_id !== $product->store_id) {
                throw new InvalidArgumentException("Product [{$product->id}] does not belong to store [{$model->store_id}].");
            }

            if (! $product->requiresSubscription()) {
                throw new InvalidArgumentException("Product [{$product->id}] is not a subscription product.");
            }

            $model->store_id = $product->store_id;
        });
    }
}
