<?php

namespace App\Platform\Commerce;

use App\Models\Payment;
use App\Models\Subscription;

/**
 * Whether a customer may use a product's protected service right now, and
 * why not. Built only from persisted state (see AccessGate).
 */
final class AccessDecision
{
    /** The product has no access control. */
    public const OPEN = 'open';

    /** Active subscription, not expired. */
    public const SUBSCRIBED = 'subscribed';

    /** No subscription ever. */
    public const NO_SUBSCRIPTION = 'no_subscription';

    /** Active subscription past its expiry. */
    public const EXPIRED = 'expired';

    /** Withdrawn by an administrator. */
    public const REVOKED = 'revoked';

    private function __construct(
        public readonly bool $allowed,
        public readonly string $reason,
        public readonly ?Subscription $subscription = null,
        public readonly ?Payment $openPayment = null,
        public readonly bool $sampleAvailable = false,
    ) {}

    public static function allow(string $reason, ?Subscription $subscription = null): self
    {
        return new self(true, $reason, $subscription);
    }

    public static function deny(string $reason, ?Subscription $subscription, ?Payment $openPayment, bool $sampleAvailable): self
    {
        return new self(false, $reason, $subscription, $openPayment, $sampleAvailable);
    }
}
