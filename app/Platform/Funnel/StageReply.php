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

    /** Refusal, frustration or anger: answered calmly, never with an offer or a payment. */
    public const QUESTION_DECLINE = 'rechazo';

    /** Every reading of a commercial turn's message. */
    public const QUESTIONS = [self::QUESTION_COMMERCIAL, self::QUESTION_CONTENT, self::QUESTION_COURTESY, self::QUESTION_DECLINE];

    /** How the answer carried "question", for the logs only: absent, empty (null or blank), invalid or valid. */
    public const FIELD_ABSENT = 'ausente';

    public const FIELD_EMPTY = 'vacio';

    public const FIELD_INVALID = 'invalido';

    public const FIELD_VALID = 'valido';

    /**
     * @param  string|null  $question  how the model read the message after the free replies: one of the QUESTION_ values, or null
     * @param  string  $questionField  how the answer carried it (FIELD_ values), whatever $question ended up being
     * @param  string|null  $fallback  the safer answer the commercial turn writes for when its reply cannot go out; unchecked
     */
    private function __construct(
        public readonly ?string $reply,
        public readonly ?string $intent,
        public readonly float $confidence,
        public readonly ?string $question = null,
        public readonly string $questionField = self::FIELD_ABSENT,
        public readonly ?string $fallback = null,
    ) {}

    /**
     * The JSON schema of a commercial turn's answer (Structured Outputs):
     * every field present, an intent among the funnel's or null, and a
     * question among the QUESTION_ values or null when it cannot be told. It
     * fixes the shape only: parse() still validates every value. With a
     * fallback, its text too: a strict schema has no optional fields, so it is
     * always written, and only used when the reply cannot go out.
     *
     * @param  array<string, string>  $intents  the intents of the funnel
     * @return array{name: string, schema: array<string, mixed>}
     */
    public static function schema(array $intents, bool $fallback = false): array
    {
        $oneOf = fn (array $values) => ['anyOf' => [['type' => 'string', 'enum' => array_values($values)], ['type' => 'null']]];
        $properties = [
            'reply' => ['type' => 'string'],
            'intent' => $intents !== [] ? $oneOf(array_keys($intents)) : ['type' => 'null'],
            'confidence' => ['type' => 'number'],
            'question' => $oneOf(self::QUESTIONS),
            ...($fallback ? ['fallback_reply' => ['type' => 'string']] : []),
        ];

        return ['name' => 'commercial_turn', 'schema' => [
            'type' => 'object',
            'properties' => $properties,
            'required' => array_keys($properties),
            'additionalProperties' => false,
        ]];
    }

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
        // Only the reply asks for the plans: a marker in the fallback is no request.
        $marked = is_string($decoded['reply'] ?? null) && preg_match(self::PLANS_MARKER, $decoded['reply']) === 1;
        $fallback = is_string($decoded['fallback_reply'] ?? null) ? self::unmarked($decoded['fallback_reply']) : '';
        $fallback = $fallback !== '' ? $fallback : null;
        $intent = $decoded['intent'] ?? null;
        $known = is_string($intent) && isset($intents[$intent]);
        $confidence = is_numeric($decoded['confidence'] ?? null) ? max(0.0, min(1.0, (float) $decoded['confidence'])) : 0.0;
        // "Contenido" or " contenido " still says content: never read as no question.
        $given = is_string($decoded['question'] ?? null) ? mb_strtolower(trim($decoded['question'])) : null;
        $question = in_array($given, self::QUESTIONS, true) ? $given : null;
        $field = match (true) {
            ! array_key_exists('question', $decoded) => self::FIELD_ABSENT,
            $question !== null => self::FIELD_VALID,
            $decoded['question'] === null || $given === '' => self::FIELD_EMPTY,
            default => self::FIELD_INVALID,
        };

        if (! $known && $marked && isset($intents[GuidedFunnel::INTENT_OFFER])) {
            return new self($reply !== '' ? $reply : null, GuidedFunnel::INTENT_OFFER, 1.0, $question, $field, $fallback);
        }

        return new self($reply !== '' ? $reply : null, $known ? $intent : null, $known ? $confidence : 0.0, $question, $field, $fallback);
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
