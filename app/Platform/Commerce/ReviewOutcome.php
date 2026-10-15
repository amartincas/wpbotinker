<?php

namespace App\Platform\Commerce;

use App\Models\Payment;
use App\Models\Subscription;

/** Result of a payment decision (PaymentReview::decide()). */
final class ReviewOutcome
{
    public function __construct(
        /** Whether this call changed the payment; false for a repeated decision. */
        public readonly bool $changed,
        public readonly Payment $payment,
        /** The subscription granted or extended, when the payment is confirmed. */
        public readonly ?Subscription $subscription,
    ) {}
}
