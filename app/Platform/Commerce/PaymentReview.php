<?php

namespace App\Platform\Commerce;

use App\Models\Customer;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

/**
 * The human review of a payment: confirm, "not reflected" or reject. The
 * only path that creates or extends a subscription.
 *
 * Every operation locks the customer row first (one review at a time per
 * customer, whatever the payment) and then re-reads the payment inside the
 * transaction, so concurrent or repeated calls are serialized and a repeated
 * operation is a no-op: a payment is never confirmed (or a subscription
 * extended) twice.
 *
 * Receipt files follow the C1 rules: kept while the payment is open
 * (including "not reflected"), deleted after commit once it is confirmed or
 * rejected. A file the disk refuses to delete stays marked and is retried
 * by a later purge.
 */
class PaymentReview
{
    /** Confirms the payment and returns the subscription it created or extended. */
    public function confirm(Payment $payment, ?User $reviewer = null, ?string $note = null): Subscription
    {
        return $this->decide($payment, true, $reviewer, $note)->subscription;
    }

    /**
     * Confirms ($approve) or rejects the payment. The outcome says whether
     * THIS call changed it: a repeated or concurrent decision gets
     * changed=false, so whoever reacts to it (e.g. telling the customer)
     * does so exactly once.
     */
    public function decide(Payment $payment, bool $approve, ?User $reviewer = null, ?string $note = null): ReviewOutcome
    {
        $outcome = $this->locked($payment, function (Payment $locked) use ($approve, $reviewer, $note) {
            $target = $approve ? Payment::STATUS_CONFIRMED : Payment::STATUS_REJECTED;

            if ($locked->status === $target) {
                return new ReviewOutcome(false, $locked, $approve ? $this->subscriptionOf($locked) : null);
            }

            $this->assertReviewable($locked, $approve ? 'confirm' : 'reject');

            $locked->update([
                'status' => $target,
                'reviewed_by' => $reviewer?->id,
                'reviewed_at' => now(),
                'review_note' => $note,
            ]);

            return new ReviewOutcome(true, $locked, $approve ? $this->grant($locked) : null);
        });

        Log::info(match (true) {
            ! $outcome->changed => 'PAYMENT_REVIEW_NOOP',
            $approve => 'PAYMENT_CONFIRMED',
            default => 'PAYMENT_REJECTED',
        }, ['store_id' => $payment->store_id, 'payment_id' => $payment->id, 'subscription_id' => $outcome->subscription?->id]);

        $this->purgeReceipts($payment);

        return $outcome;
    }

    /** The money is not visible yet: the payment stays open, grants nothing and keeps its receipts. */
    public function markNotReflected(Payment $payment, ?User $reviewer = null, ?string $note = null): Payment
    {
        return $this->locked($payment, function (Payment $locked) use ($reviewer, $note) {
            if ($locked->status === Payment::STATUS_NOT_REFLECTED) {
                return $locked;
            }

            if ($locked->status !== Payment::STATUS_UNDER_REVIEW) {
                throw new LogicException("Payment [{$locked->id}] is [{$locked->status}]; only a payment under review can be marked as not reflected.");
            }

            $locked->update(['status' => Payment::STATUS_NOT_REFLECTED, 'reviewed_by' => $reviewer?->id, 'reviewed_at' => now(), 'review_note' => $note]);

            Log::info('PAYMENT_NOT_REFLECTED', ['store_id' => $locked->store_id, 'payment_id' => $locked->id]);

            return $locked;
        });
    }

    /** Final rejection: grants nothing and drops its receipt files. */
    public function reject(Payment $payment, ?User $reviewer = null, ?string $reason = null): Payment
    {
        return $this->decide($payment, false, $reviewer, $reason)->payment;
    }

    /**
     * @template T
     *
     * @param  callable(Payment): T  $callback
     * @return T
     */
    private function locked(Payment $payment, callable $callback): mixed
    {
        return DB::transaction(function () use ($payment, $callback) {
            Customer::whereKey($payment->customer_id)->lockForUpdate()->firstOrFail();

            return $callback(Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail());
        });
    }

    private function assertReviewable(Payment $payment, string $operation): void
    {
        if (! in_array($payment->status, [Payment::STATUS_UNDER_REVIEW, Payment::STATUS_NOT_REFLECTED], true)) {
            throw new LogicException("Payment [{$payment->id}] is [{$payment->status}]; it cannot be {$operation}ed.");
        }
    }

    /**
     * Creates the subscription or extends it by the payment's duration: from
     * its current expiry while still valid (no paid day is lost), otherwise
     * from now. A revoked subscription is reactivated by a confirmed payment.
     */
    private function grant(Payment $payment): Subscription
    {
        $subscription = Subscription::where('customer_id', $payment->customer_id)
            ->where('product_id', $payment->product_id)
            ->lockForUpdate()
            ->first();

        $stillValid = $subscription?->isValidAt() ?? false;
        $base = $stillValid ? $subscription->expires_at : now();

        $subscription ??= new Subscription(['customer_id' => $payment->customer_id, 'product_id' => $payment->product_id]);

        $subscription->fill([
            'status' => Subscription::STATUS_ACTIVE,
            'started_at' => $stillValid ? $subscription->started_at : now(),
            'expires_at' => $base->copy()->addDays($payment->duration_days),
            'last_payment_id' => $payment->id,
        ])->save();

        return $subscription;
    }

    private function subscriptionOf(Payment $payment): Subscription
    {
        return Subscription::where('customer_id', $payment->customer_id)->where('product_id', $payment->product_id)->firstOrFail();
    }

    private function purgeReceipts(Payment $payment): void
    {
        foreach (PaymentReceipt::where('payment_id', $payment->id)->get() as $receipt) {
            try {
                $receipt->purgeAfterReview();
            } catch (Throwable $e) {
                Log::warning('RECEIPT_PURGE_FAILED', ['store_id' => $payment->store_id, 'payment_id' => $payment->id, 'receipt_id' => $receipt->id, 'error' => $e::class]);
            }
        }
    }
}
