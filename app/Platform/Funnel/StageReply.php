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
    /** What the message asks about the product, as a purchase. */
    public const QUESTION_COMMERCIAL = 'comercial';

    /** What the message asks of the service's subject itself. */
    public const QUESTION_CONTENT = 'contenido';

    /** Only a greeting, a thanks or another courtesy: answered as one, never as a request. */
    public const QUESTION_COURTESY = 'cortesia';

    /**
     * @param  string|null  $question  how the model read the message after the free replies: one of the QUESTION_ values, or null
     */
    private function __construct(
        public readonly ?string $reply,
        public readonly ?string $intent,
        public readonly float $confidence,
        public readonly ?string $question = null,
    ) {}

    /**
     * The model's own way to ask for the plans, written in its reply: the
     * Core takes it out of the text and reads it as presentar_oferta.
     */
    public const PLANS_MARKER = '/'.self::PLANS_TOKEN.'/iu';

    private const PLANS_TOKEN = '\[\s*planes\s*\]';

    /**
     * The [Planes] marker never reaches the customer, whatever the answer's
     * shape. In a reply with intents, it is the offer when the model proposed
     * no other intent (a purchase stays a purchase); the Core validates it
     * like any proposal.
     *
     * @param  array<string, string>  $intents  the intents of the funnel
     */
    public static function parse(string $raw, bool $structured, array $intents): self
    {
        $raw = trim($raw);
        $marked = preg_match(self::PLANS_MARKER, $raw) === 1;

        if (! $structured) {
            $raw = self::unmarked($raw);

            return new self($raw !== '' ? $raw : null, null, 0.0);
        }

        $text = trim((string) preg_replace(['/^```(?:json)?/i', '/```$/'], '', $raw));
        $decoded = json_decode($text, true);

        if (! is_array($decoded)) {
            // Plain text is a reply without an intent; broken JSON never reaches the customer.
            $plain = self::unmarked($raw);
            $asksForPlans = $marked && $plain !== '' && ! str_starts_with($text, '{') && isset($intents[GuidedFunnel::INTENT_OFFER]);

            return $asksForPlans
                ? new self($plain, GuidedFunnel::INTENT_OFFER, 1.0)
                : new self($plain !== '' && ! str_starts_with($text, '{') ? $plain : null, null, 0.0);
        }

        $reply = is_string($decoded['reply'] ?? null) ? self::unmarked($decoded['reply']) : '';
        $intent = $decoded['intent'] ?? null;
        $known = is_string($intent) && isset($intents[$intent]);
        $confidence = is_numeric($decoded['confidence'] ?? null) ? max(0.0, min(1.0, (float) $decoded['confidence'])) : 0.0;
        // "Contenido" or " contenido " still says content: never read as no question.
        $question = is_string($decoded['question'] ?? null) ? mb_strtolower(trim($decoded['question'])) : null;
        $question = in_array($question, [self::QUESTION_COMMERCIAL, self::QUESTION_CONTENT, self::QUESTION_COURTESY], true) ? $question : null;

        if (! $known && $marked && isset($intents[GuidedFunnel::INTENT_OFFER])) {
            return new self($reply !== '' ? $reply : null, GuidedFunnel::INTENT_OFFER, 1.0, $question);
        }

        return new self($reply !== '' ? $reply : null, $known ? $intent : null, $known ? $confidence : 0.0, $question);
    }

    /** The text without the marker, and without the blank space it leaves. */
    private static function unmarked(string $text): string
    {
        return trim((string) preg_replace(['/[ \t]*'.self::PLANS_TOKEN.'[ \t]*/iu', "/\n{3,}/"], [' ', "\n\n"], $text));
    }

    public static function none(): self
    {
        return new self(null, null, 0.0);
    }
}
