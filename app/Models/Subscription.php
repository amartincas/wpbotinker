<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSubscriptionProduct;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/**
 * Paid access of a customer to one product. There is no "expired" status:
 * access is valid while the subscription is active and expires_at is in
 * the future, so an expired subscription blocks the paid service without
 * any scheduled job. Renewal extends the same row after a confirmed payment.
 */
#[Fillable(['store_id', 'customer_id', 'product_id', 'status', 'started_at', 'expires_at', 'last_payment_id'])]
class Subscription extends Model
{
    use BelongsToSubscriptionProduct;

    public const STATUS_ACTIVE = 'active';

    /** Withdrawn by an administrator, whatever expires_at says. */
    public const STATUS_REVOKED = 'revoked';

    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $subscription) {
            if (! in_array($subscription->status, [self::STATUS_ACTIVE, self::STATUS_REVOKED], true)) {
                throw new InvalidArgumentException("Unknown subscription status [{$subscription->status}].");
            }

            if ($subscription->started_at === null || $subscription->expires_at === null || $subscription->expires_at->lte($subscription->started_at)) {
                throw new InvalidArgumentException('A subscription needs a start and an expiry after it.');
            }

            if ($subscription->last_payment_id !== null && $subscription->isDirty('last_payment_id')) {
                $granted = Payment::whereKey($subscription->last_payment_id)
                    ->where('customer_id', $subscription->customer_id)
                    ->where('product_id', $subscription->product_id)
                    ->where('status', Payment::STATUS_CONFIRMED)
                    ->exists();

                if (! $granted) {
                    throw new InvalidArgumentException('Only a confirmed payment of the same customer and product grants a subscription.');
                }
            }
        });
    }

    public function isValidAt(?CarbonInterface $moment = null): bool
    {
        return $this->status === self::STATUS_ACTIVE && $this->expires_at->gt($moment ?? now());
    }

    /** Active but past its expiry: the customer must renew. */
    public function hasExpired(?CarbonInterface $moment = null): bool
    {
        return $this->status === self::STATUS_ACTIVE && ! $this->expires_at->gt($moment ?? now());
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function lastPayment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'last_payment_id');
    }
}
