<?php

namespace App\Observers;

use App\Jobs\RecordPaymentLead;
use App\Models\Payment;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A confirmed subscription payment (knowledge mode) becomes a Lead through
 * RecordPaymentLead, queued here. It lives outside the Core: App\Platform
 * never touches the sales flow (see PlatformArchitectureTest).
 *
 * - Only the effective transition to "confirmed" counts, and only after
 *   the review's transaction committed: a rolled-back confirmation queues
 *   nothing.
 * - Never fails the confirmation: queuing problems (or, with the sync
 *   queue, the job itself) are logged.
 */
class PaymentObserver implements ShouldHandleEventsAfterCommit
{
    public function updated(Payment $payment): void
    {
        if (! $payment->wasChanged('status') || $payment->status !== Payment::STATUS_CONFIRMED) {
            return;
        }

        try {
            RecordPaymentLead::dispatch($payment->id);
        } catch (Throwable $e) {
            Log::error('PAYMENT_LEAD_NOT_QUEUED', ['store_id' => $payment->store_id, 'payment_id' => $payment->id, 'error' => $e::class]);
        }
    }
}
