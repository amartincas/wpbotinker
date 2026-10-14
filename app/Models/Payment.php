<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSubscriptionProduct;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

/**
 * One payment attempt of a customer for a subscription product. It starts
 * from a plan (price and duration are copied and never change) and only a
 * human review closes it as confirmed or rejected. "Not reflected" keeps it
 * open: the money may still show up.
 */
#[Fillable([
    'store_id', 'customer_id', 'product_id', 'plan_id', 'plan_name', 'amount', 'currency', 'duration_days',
    'status', 'reference', 'expires_at', 'receipt_submitted_at', 'review_due_at',
    'reviewed_by', 'reviewed_at', 'review_note',
])]
class Payment extends Model
{
    use BelongsToSubscriptionProduct;

    /** Started, waiting for the customer's receipt until expires_at. */
    public const STATUS_PENDING = 'pending';

    /** A receipt arrived; waiting for a human decision. */
    public const STATUS_UNDER_REVIEW = 'under_review';

    /** The reviewer did not see the money yet. Still open, grants nothing. */
    public const STATUS_NOT_REFLECTED = 'not_reflected';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_REJECTED = 'rejected';

    /** Pending payment whose time ran out without a receipt. */
    public const STATUS_EXPIRED = 'expired';

    public const OPEN_STATUSES = [self::STATUS_PENDING, self::STATUS_UNDER_REVIEW, self::STATUS_NOT_REFLECTED];

    /** Every allowed status change; the rest are rejected. */
    private const TRANSITIONS = [
        self::STATUS_PENDING => [self::STATUS_UNDER_REVIEW, self::STATUS_EXPIRED],
        self::STATUS_UNDER_REVIEW => [self::STATUS_NOT_REFLECTED, self::STATUS_CONFIRMED, self::STATUS_REJECTED],
        // A new receipt sends it back to review; the reviewer may also
        // confirm or reject it directly once the money shows up (or not).
        self::STATUS_NOT_REFLECTED => [self::STATUS_UNDER_REVIEW, self::STATUS_CONFIRMED, self::STATUS_REJECTED],
    ];

    /** Copied from the plan when the payment starts; never changes. */
    private const SNAPSHOT = ['store_id', 'customer_id', 'product_id', 'plan_name', 'amount', 'currency', 'duration_days'];

    protected $attributes = [
        'status' => self::STATUS_PENDING,
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'duration_days' => 'integer',
            'expires_at' => 'datetime',
            'receipt_submitted_at' => 'datetime',
            'review_due_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * Starts a payment for a customer from an active plan of the product,
     * copying its price and duration.
     */
    public static function start(Customer $customer, Plan $plan, CarbonInterface $expiresAt): self
    {
        if (! $plan->is_active) {
            throw new InvalidArgumentException("Plan [{$plan->id}] is not active.");
        }

        return self::create([
            'customer_id' => $customer->id,
            'product_id' => $plan->product_id,
            'plan_id' => $plan->id,
            'plan_name' => $plan->name,
            'amount' => $plan->price,
            'currency' => $plan->currency,
            'duration_days' => $plan->duration_days,
            'expires_at' => $expiresAt,
        ]);
    }

    /** Uppercase letters and digits only, so the same reference written differently matches. */
    public static function normalizeReference(?string $reference): ?string
    {
        $normalized = preg_replace('/[^A-Z0-9]/', '', mb_strtoupper((string) $reference));

        return $normalized === '' ? null : $normalized;
    }

    protected static function booted(): void
    {
        static::creating(function (self $payment) {
            if ($payment->status !== self::STATUS_PENDING) {
                throw new InvalidArgumentException('A payment starts as pending.');
            }

            if (! is_numeric($payment->amount) || (float) $payment->amount <= 0 || $payment->duration_days < 1) {
                throw new InvalidArgumentException('A payment needs a positive amount and duration.');
            }

            if ($payment->plan_id !== null && Plan::whereKey($payment->plan_id)->where('product_id', $payment->product_id)->doesntExist()) {
                throw new InvalidArgumentException("Plan [{$payment->plan_id}] is not a plan of product [{$payment->product_id}].");
            }
        });

        static::updating(function (self $payment) {
            if ($payment->isDirty(self::SNAPSHOT)) {
                throw new InvalidArgumentException('The customer, product, price and duration of a payment never change.');
            }

            if ($payment->isDirty('status')) {
                $from = $payment->getOriginal('status');

                if (! in_array($payment->status, self::TRANSITIONS[$from] ?? [], true)) {
                    throw new InvalidArgumentException("A payment cannot go from [{$from}] to [{$payment->status}].");
                }
            }
        });

        // The unique (customer_id, open_marker) index allows one open
        // payment per customer and product; the marker follows the status.
        static::saving(function (self $payment) {
            $payment->open_marker = $payment->isOpen() ? "product:{$payment->product_id}" : null;
        });
    }

    protected function reference(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => self::normalizeReference($value));
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    /** Confirmed or rejected: the review is over and its receipts' files must go. */
    public function isReviewClosed(): bool
    {
        return in_array($this->status, [self::STATUS_CONFIRMED, self::STATUS_REJECTED], true);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(PaymentReceipt::class);
    }
}
