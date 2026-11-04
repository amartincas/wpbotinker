<?php

namespace App\Platform\Funnel;

/**
 * What the model answered in a conversational stage: the text for the
 * customer and, in one single call, which of the funnel's intents it read in
 * the message. A proposal: the Runner validates it, and a reply that cannot be
 * told from a broken answer is no reply at all.
 */
final class StageReply
{
    private function __construct(
        public readonly ?string $reply,
        public readonly ?string $intent,
        public readonly float $confidence,
    ) {}

    /**
     * @param  array<string, string>  $intents  the intents of the funnel
     */
    public static function parse(string $raw, bool $structured, array $intents): self
    {
        $raw = trim($raw);

        if (! $structured) {
            return new self($raw !== '' ? $raw : null, null, 0.0);
        }

        $text = trim((string) preg_replace(['/^```(?:json)?/i', '/```$/'], '', $raw));
        $decoded = json_decode($text, true);

        if (! is_array($decoded)) {
            // Plain text is a reply without an intent; broken JSON never reaches the customer.
            return new self($raw !== '' && ! str_starts_with($text, '{') ? $raw : null, null, 0.0);
        }

        $reply = is_string($decoded['reply'] ?? null) ? trim($decoded['reply']) : '';
        $intent = $decoded['intent'] ?? null;
        $known = is_string($intent) && isset($intents[$intent]);
        $confidence = is_numeric($decoded['confidence'] ?? null) ? max(0.0, min(1.0, (float) $decoded['confidence'])) : 0.0;

        return new self($reply !== '' ? $reply : null, $known ? $intent : null, $known ? $confidence : 0.0);
    }

    public static function none(): self
    {
        return new self(null, null, 0.0);
    }
}
