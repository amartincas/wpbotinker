<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Platform\Funnel\FunnelDefinition;

/**
 * Converts a funnel's definition, as FunnelDefinition reads it, to the state
 * of a form and back. It validates nothing: what the form cannot normalize
 * stays as it came, so FunnelDefinition rejects it.
 *
 * In the state, null means the definition does not have that value: an empty
 * optional value goes back to being absent, which is how the contract reads
 * it. False and 0 are values and are kept. Texts, keys and destinations are
 * never altered, and lists keep their order.
 */
final class FunnelDefinitionFormMapper
{
    private const CONDITIONS = [
        FunnelDefinition::WHEN_PHRASE,
        FunnelDefinition::WHEN_PLAN_NAMED,
        FunnelDefinition::WHEN_INTENT,
        FunnelDefinition::WHEN_ANY_REPLY,
        FunnelDefinition::WHEN_INTERACTIONS,
        FunnelDefinition::WHEN_FREE_EXHAUSTED,
    ];

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    public static function toFormState(array $definition): array
    {
        $free = $definition['free_replies'] ?? [];

        return [
            'name' => $definition['name'] ?? null,
            'goal' => $definition['goal'] ?? null,
            'start_stage' => $definition['start_stage'] ?? null,
            'offer_cta' => $definition['offer_cta'] ?? null,
            'free_replies' => [
                'limit' => $free['limit'] ?? null,
                'on_exhausted' => $free['on_exhausted'] ?? null,
            ],
            'opening_consumes_free_reply' => $definition['opening_consumes_free_reply'] ?? null,
            'intents' => array_map(fn (array $intent) => [
                'key' => $intent['key'] ?? null,
                'description' => $intent['description'] ?? null,
            ], array_values($definition['intents'] ?? [])),
            'stages' => array_map(self::stageToState(...), array_values($definition['stages'] ?? [])),
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public static function toDefinition(array $state): array
    {
        $free = self::withoutAbsent([
            'limit' => self::whole($state['free_replies']['limit'] ?? null),
            'on_exhausted' => self::optional($state['free_replies']['on_exhausted'] ?? null),
        ]);

        return self::withoutAbsent([
            'name' => self::optional($state['name'] ?? null),
            'goal' => self::optional($state['goal'] ?? null),
            'start_stage' => self::optional($state['start_stage'] ?? null),
            'offer_cta' => self::optional($state['offer_cta'] ?? null),
            'free_replies' => $free ?: null,
            'opening_consumes_free_reply' => self::boolean($state['opening_consumes_free_reply'] ?? null),
            'intents' => array_map(fn (array $intent) => self::withoutAbsent([
                'key' => self::optional($intent['key'] ?? null),
                'description' => self::optional($intent['description'] ?? null),
            ]), array_values($state['intents'] ?? [])) ?: null,
            'stages' => array_map(self::stageToDefinition(...), array_values($state['stages'] ?? [])),
        ]);
    }

    /**
     * @param  array<string, mixed>  $stage
     * @return array<string, mixed>
     */
    private static function stageToState(array $stage): array
    {
        return [
            'key' => $stage['key'] ?? null,
            'type' => $stage['type'] ?? null,
            'goal' => $stage['goal'] ?? null,
            'instructions' => $stage['instructions'] ?? null,
            'restrictions' => $stage['restrictions'] ?? null,
            'text' => $stage['text'] ?? null,
            'context' => array_values($stage['context'] ?? []),
            'uses_free_replies' => $stage['uses_free_replies'] ?? null,
            'max_interactions' => $stage['max_interactions'] ?? null,
            'on_limit' => $stage['on_limit'] ?? null,
            'next' => $stage['next'] ?? null,
            'rules' => array_map(self::ruleToState(...), array_values($stage['rules'] ?? [])),
        ];
    }

    /**
     * @param  array<string, mixed>  $stage
     * @return array<string, mixed>
     */
    private static function stageToDefinition(array $stage): array
    {
        return self::withoutAbsent([
            'key' => self::optional($stage['key'] ?? null),
            'type' => self::optional($stage['type'] ?? null),
            'goal' => self::optional($stage['goal'] ?? null),
            'instructions' => self::optional($stage['instructions'] ?? null),
            'restrictions' => self::optional($stage['restrictions'] ?? null),
            'text' => self::optional($stage['text'] ?? null),
            'context' => self::list($stage['context'] ?? null) ?: null,
            'uses_free_replies' => self::boolean($stage['uses_free_replies'] ?? null),
            'max_interactions' => self::whole($stage['max_interactions'] ?? null),
            'on_limit' => self::optional($stage['on_limit'] ?? null),
            'next' => self::optional($stage['next'] ?? null),
            'rules' => array_map(self::ruleToDefinition(...), array_values($stage['rules'] ?? [])) ?: null,
        ]);
    }

    /**
     * A rule's condition and value become separate fields, so a form can show
     * only the value that condition needs.
     *
     * @param  array<string, mixed>  $rule
     * @return array<string, mixed>
     */
    private static function ruleToState(array $rule): array
    {
        $when = $rule['when'] ?? [];
        $then = $rule['then'] ?? [];

        return [
            'condition' => array_values(array_intersect(self::CONDITIONS, array_keys($when)))[0] ?? null,
            'phrases' => array_values($when[FunnelDefinition::WHEN_PHRASE] ?? []),
            'intent' => $when[FunnelDefinition::WHEN_INTENT] ?? null,
            'interactions' => $when[FunnelDefinition::WHEN_INTERACTIONS] ?? null,
            'min_confidence' => $when['min_confidence'] ?? null,
            'action' => $then['action'] ?? null,
            'stage' => $then['stage'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $rule
     * @return array<string, mixed>
     */
    private static function ruleToDefinition(array $rule): array
    {
        $condition = self::optional($rule['condition'] ?? null);

        $value = match ($condition) {
            null => null,
            FunnelDefinition::WHEN_PHRASE => self::list($rule['phrases'] ?? null),
            FunnelDefinition::WHEN_INTENT => self::optional($rule['intent'] ?? null),
            FunnelDefinition::WHEN_INTERACTIONS => self::whole($rule['interactions'] ?? null),
            default => true,
        };

        return [
            'when' => self::withoutAbsent([
                ...($condition !== null ? [$condition => $value] : []),
                'min_confidence' => self::decimal($rule['min_confidence'] ?? null),
            ]),
            'then' => self::withoutAbsent([
                'action' => self::optional($rule['action'] ?? null),
                'stage' => self::optional($rule['stage'] ?? null),
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function withoutAbsent(array $values): array
    {
        return array_filter($values, fn (mixed $value) => $value !== null);
    }

    /** Empty means absent; anything else stays exactly as it came. */
    private static function optional(mixed $value): mixed
    {
        return is_string($value) && trim($value) === '' ? null : $value;
    }

    /**
     * A numeric field of a form may give 2, 2.0, "2" or "2.0". Anything that
     * is not exactly a whole number is never truncated: it stays for the
     * contract to reject. A float past 2^53 no longer holds an exact whole
     * number, so it is not taken for one.
     */
    private static function whole(mixed $value): mixed
    {
        $value = self::optional($value);

        if (is_string($value) && ($whole = filter_var(trim($value), FILTER_VALIDATE_INT)) !== false) {
            return $whole;
        }

        $number = is_string($value) && is_numeric(trim($value)) ? (float) trim($value) : $value;

        if (is_float($number) && is_finite($number) && floor($number) === $number && abs($number) <= 2 ** 53) {
            return (int) $number;
        }

        return $value;
    }

    private static function decimal(mixed $value): mixed
    {
        $value = self::optional($value);

        return is_string($value) && is_numeric(trim($value)) ? (float) trim($value) : $value;
    }

    private static function boolean(mixed $value): ?bool
    {
        $value = self::optional($value);

        return $value === null ? null : filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    /**
     * @return list<mixed>
     */
    private static function list(mixed $values): array
    {
        return array_values(array_filter(is_array($values) ? $values : [], fn (mixed $value) => self::optional($value) !== null));
    }
}
