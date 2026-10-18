<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Payment;
use App\Platform\Memory\CustomerMemory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Records a confirmed subscription payment (knowledge mode) as a Lead, so
 * the existing LeadObserver reports it to Meta's Conversions API under its
 * own conditions (the conversation's ctwa_clid and the store's
 * credentials). Queued by PaymentObserver once the review committed, so
 * neither the confirmation nor the customer's notification waits for Meta.
 *
 * Idempotent: one lead per payment (unique leads.payment_id), whatever the
 * retries or a concurrent run. A failure is retried with backoff; once the
 * tries are exhausted it stays in failed_jobs (php artisan queue:failed)
 * and is logged as PAYMENT_LEAD_NOT_RECORDED.
 */
class RecordPaymentLead implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    /** Below the database queue's retry_after (90 s): a slow run is never taken twice. */
    public int $timeout = 80;

    public function __construct(public readonly int $paymentId) {}

    /** @return list<int> seconds before each retry */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(CustomerMemory $memory): void
    {
        $payment = Payment::with(['customer', 'product'])->find($this->paymentId);

        if ($payment === null || $payment->status !== Payment::STATUS_CONFIRMED || Lead::where('payment_id', $payment->id)->exists()) {
            return;
        }

        $phone = $payment->customer->customer_phone;
        $name = trim((string) $memory->personValue($payment->customer, 'name'));

        try {
            Lead::create([
                'store_id' => $payment->store_id,
                'product_id' => $payment->product_id,
                'payment_id' => $payment->id,
                'customer_phone' => $phone,
                'customer_name' => $name !== '' ? $name : $phone,
                'product_service_name' => $payment->product->name,
                'summary' => "Pago P-{$payment->id} confirmado: «{$payment->product->name}», plan {$payment->plan_name}, {$payment->amount} {$payment->currency}.",
                'sale_value' => $payment->amount,
                'ctwa_clid' => Conversation::where('store_id', $payment->store_id)->where('customer_phone', $phone)->value('ctwa_clid'),
                // A paid subscription has nothing left to process (no shipping).
                'is_processed' => true,
            ]);
        } catch (UniqueConstraintViolationException) {
            // A concurrent run recorded it first.
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('PAYMENT_LEAD_NOT_RECORDED', ['payment_id' => $this->paymentId, 'error' => $exception::class]);
    }
}
