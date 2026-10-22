<?php

namespace App\Platform\Conversation;

/** Outcome of TopicClassifier::classify(). */
final class TopicClassification
{
    private function __construct(
        /** The classification could not be made (provider error or invalid answer). */
        public readonly bool $failed,
        /** A topic key of the store's catalog, or null when the message is about none. */
        public readonly ?string $topicKey,
        /** Whether the message asks for something; false for a greeting, thanks or small talk. */
        public readonly bool $request,
    ) {}

    public static function topic(string $key): self
    {
        return new self(false, $key, true);
    }

    public static function none(bool $request): self
    {
        return new self(false, null, $request);
    }

    public static function failed(): self
    {
        return new self(true, null, false);
    }
}
