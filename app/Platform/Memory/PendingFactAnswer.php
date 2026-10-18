<?php

namespace App\Platform\Memory;

/**
 * What FactCollector made of a message given its own pending question.
 */
final class PendingFactAnswer
{
    private function __construct(
        public readonly ?int $answeredProductId,
        public readonly ?int $rejectedProductId,
        public readonly ?string $rejectionReason,
        public readonly ?string $reply,
        public readonly ?string $replyDomainKey,
        /** The customer's message that led to the question (see FactCollector::require()). */
        public readonly ?string $originMessage = null,
    ) {}

    /** No pending question of FactCollector: resolve the message normally. */
    public static function none(): self
    {
        return new self(null, null, null, null, null);
    }

    /** The pending question was answered: continue with that product. */
    public static function answered(int $productId, ?string $originMessage = null): self
    {
        return new self($productId, null, null, null, null, $originMessage);
    }

    /**
     * Not a valid answer. The message is resolved normally (the customer
     * may have changed topic); if it stays on the same product, the
     * question is asked again with the reason.
     */
    public static function rejected(int $productId, string $reason, ?string $originMessage = null): self
    {
        return new self(null, $productId, $reason, null, null, $originMessage);
    }

    /** Send this text and stop (e.g. permission declined). */
    public static function reply(string $text, string $domainKey): self
    {
        return new self(null, null, null, $text, $domainKey);
    }

    public function rejectionFor(int $productId): ?string
    {
        return $this->rejectedProductId === $productId ? $this->rejectionReason : null;
    }

    /**
     * The customer's original query while the conversation stays on the
     * product whose question this message answered (or failed to answer).
     */
    public function originFor(int $productId): ?string
    {
        return in_array($productId, [$this->answeredProductId, $this->rejectedProductId], true) ? $this->originMessage : null;
    }
}
