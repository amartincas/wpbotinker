<?php

namespace App\Platform\Funnel;

use App\Platform\Commerce\OpeningMessage;
use InvalidArgumentException;

/**
 * A funnel's configuration, validated as a whole: what the Core can execute
 * is this closed list of stage types, conditions, actions and context
 * sources; everything else about a funnel (its stages, phrases, intents and
 * instructions) is data of the product. Anything else is rejected, so no
 * published version can ask the engine for something it does not support.
 */
final class FunnelDefinition
{
    public const WHEN_PHRASE = 'phrase';

    public const WHEN_PLAN_NAMED = 'plan_named';

    public const WHEN_INTENT = 'intent';

    public const WHEN_ANY_REPLY = 'any_reply';

    public const WHEN_INTERACTIONS = 'interactions_reached';

    public const WHEN_FREE_EXHAUSTED = 'free_replies_exhausted';

    private const CONDITIONS = [self::WHEN_PHRASE, self::WHEN_PLAN_NAMED, self::WHEN_INTENT, self::WHEN_ANY_REPLY, self::WHEN_INTERACTIONS, self::WHEN_FREE_EXHAUSTED];

    public const ACTION_GO_TO = 'go_to';

    public const ACTION_SHOW_OFFER = 'show_offer';

    public const ACTION_START_PAYMENT = 'start_payment';

    public const ACTION_STAY = 'stay';

    private const ACTIONS = [self::ACTION_GO_TO, self::ACTION_SHOW_OFFER, self::ACTION_START_PAYMENT, self::ACTION_STAY];

    public const CONTEXT_DESCRIPTION = 'description';

    public const CONTEXT_FAQ = 'faq';

    public const CONTEXT_TOPIC = 'topic';

    public const CONTEXT_COMMERCIAL = 'commercial_resources';

    public const CONTEXT_SAMPLE = 'sample_content';

    /**
     * Never the service's instructions nor the subscribers' knowledge: no
     * configuration can open them to a customer without access.
     */
    public const CONTEXTS = [self::CONTEXT_DESCRIPTION, self::CONTEXT_FAQ, self::CONTEXT_TOPIC, self::CONTEXT_COMMERCIAL, self::CONTEXT_SAMPLE];

    public const DEFAULT_MIN_CONFIDENCE = 0.8;

    public const MAX_TEXT_LENGTH = 4000;

    private const MAX_KEY_LENGTH = 100;

    /**
     * @param  array<string, FunnelStage>  $stages
     * @param  array<string, string>  $intents  key => what it means
     */
    private function __construct(
        public readonly string $name,
        public readonly string $goal,
        public readonly string $startStage,
        public readonly array $stages,
        public readonly array $intents,
        public readonly int $freeRepliesLimit,
        public readonly ?string $onFreeRepliesExhausted,
        public readonly ?string $offerCta,
        public readonly bool $openingConsumesFreeReply,
        public readonly bool $guided = false,
    ) {}

    public function stage(string $key): ?FunnelStage
    {
        return $this->stages[$key] ?? null;
    }

    public function start(): FunnelStage
    {
        return $this->stages[$this->startStage];
    }

    public static function fromArray(mixed $definition): self
    {
        if (! is_array($definition)) {
            throw new InvalidArgumentException('A sales funnel definition must be an array.');
        }

        $stagesData = $definition['stages'] ?? null;

        if (! is_array($stagesData) || ! array_is_list($stagesData) || $stagesData === []) {
            throw new InvalidArgumentException('A sales funnel definition needs a list of stages.');
        }

        $name = self::requiredText($definition['name'] ?? null, 'The funnel needs a name.');
        $goal = self::requiredText($definition['goal'] ?? null, 'The funnel needs a goal.');
        $keys = self::stageKeys($stagesData);
        $intents = self::intents($definition['intents'] ?? []);

        $free = $definition['free_replies'] ?? [];

        if (! is_array($free)) {
            throw new InvalidArgumentException('free_replies must be an object.');
        }

        $limit = $free['limit'] ?? 0;

        if (! is_int($limit) || $limit < 0 || $limit > 255) {
            throw new InvalidArgumentException('free_replies.limit must be a whole number from 0 to 255.');
        }

        $onExhausted = self::stageReference($free['on_exhausted'] ?? null, $keys, 'free_replies.on_exhausted');

        $start = $definition['start_stage'] ?? $keys[0];

        if (! is_string($start) || ! in_array($start, $keys, true)) {
            throw new InvalidArgumentException('start_stage must be one of the stages.');
        }

        $stages = [];

        foreach ($stagesData as $data) {
            $stage = self::stageFrom($data, $keys, $intents);

            if ($stage->usesFreeReplies && $limit === 0) {
                throw new InvalidArgumentException("Stage [{$stage->key}] uses free replies, but the funnel gives none.");
            }

            $stages[$stage->key] = $stage;
        }

        $cta = $definition['offer_cta'] ?? null;

        if ($cta !== null && (! is_string($cta) || trim($cta) === '' || mb_strlen($cta) > self::MAX_TEXT_LENGTH)) {
            throw new InvalidArgumentException('offer_cta must be a text.');
        }

        $guided = GuidedFunnel::isGuided($definition);

        if ($guided) {
            self::assertGuided($stages);
        }

        if (array_key_exists('reactivation', $definition)) {
            self::assertReactivation($definition['reactivation'], $guided);
        }

        if (array_key_exists('unclassified_reply', $definition)) {
            $reply = $definition['unclassified_reply'];

            if (! $guided || ! is_string($reply) || trim($reply) === '' || mb_strlen($reply) > GuidedFunnel::MAX_UNCLASSIFIED_REPLY_LENGTH) {
                throw new InvalidArgumentException('unclassified_reply must be a text of up to '.GuidedFunnel::MAX_UNCLASSIFIED_REPLY_LENGTH.' characters, in a guided funnel.');
            }
        }

        return new self(
            $name, $goal, $start, $stages, $intents, $limit, $onExhausted,
            $cta !== null ? trim($cta) : null,
            (bool) ($definition['opening_consumes_free_reply'] ?? true),
            $guided,
        );
    }

    /**
     * Its one stage has to spend free replies: that is what limits the model.
     *
     * @param  array<string, FunnelStage>  $stages
     */
    private static function assertGuided(array $stages): void
    {
        $stage = array_values($stages)[0];

        if (count($stages) !== 1 || $stage->isMessage() || ! $stage->usesFreeReplies) {
            throw new InvalidArgumentException('A guided funnel needs one AI stage that uses free replies.');
        }

        if (mb_strlen($stage->instructions) > GuidedFunnel::MAX_INSTRUCTIONS_LENGTH) {
            throw new InvalidArgumentException('The instructions of a guided funnel are too long.');
        }
    }

    /** Only a guided funnel reactivates, with a literal message inside WhatsApp's window. */
    private static function assertReactivation(mixed $reactivation, bool $guided): void
    {
        if (! $guided) {
            throw new InvalidArgumentException('Only a guided funnel can configure a reactivation.');
        }

        $message = is_array($reactivation) ? ($reactivation['message'] ?? null) : null;
        $hours = is_array($reactivation) ? ($reactivation['after_hours'] ?? null) : null;

        if (! is_string($message) || trim($message) === '' || mb_strlen($message) > GuidedFunnel::MAX_REACTIVATION_LENGTH) {
            throw new InvalidArgumentException('reactivation.message must be a text of up to '.GuidedFunnel::MAX_REACTIVATION_LENGTH.' characters.');
        }

        if (! is_int($hours) || $hours < 1 || $hours > GuidedFunnel::MAX_REACTIVATION_HOURS) {
            throw new InvalidArgumentException('reactivation.after_hours must be a whole number from 1 to '.GuidedFunnel::MAX_REACTIVATION_HOURS.'.');
        }
    }

    /**
     * @param  array<int, mixed>  $stagesData
     * @return list<string>
     */
    private static function stageKeys(array $stagesData): array
    {
        $keys = array_map(fn ($stage) => is_array($stage) ? ($stage['key'] ?? null) : null, $stagesData);

        foreach ($keys as $key) {
            if (! is_string($key) || trim($key) === '' || mb_strlen($key) > self::MAX_KEY_LENGTH) {
                throw new InvalidArgumentException('Every stage of a sales funnel needs a key of up to '.self::MAX_KEY_LENGTH.' characters.');
            }
        }

        if (count(array_unique($keys)) !== count($keys)) {
            throw new InvalidArgumentException('The stages of a sales funnel need different keys.');
        }

        return $keys;
    }

    /**
     * @return array<string, string>
     */
    private static function intents(mixed $data): array
    {
        if (! is_array($data) || ! array_is_list($data)) {
            throw new InvalidArgumentException('intents must be a list.');
        }

        $intents = [];

        foreach ($data as $intent) {
            $key = is_array($intent) ? ($intent['key'] ?? null) : null;

            if (! is_string($key) || preg_match('/^[a-z][a-z0-9_]*$/', $key) !== 1) {
                throw new InvalidArgumentException('Every intent needs a key of lowercase letters, numbers and underscores.');
            }

            if (isset($intents[$key])) {
                throw new InvalidArgumentException("Intent [{$key}] is repeated.");
            }

            $intents[$key] = self::requiredText($intent['description'] ?? null, "Intent [{$key}] needs a description.");
        }

        return $intents;
    }

    /**
     * @param  list<string>  $keys
     * @param  array<string, string>  $intents
     */
    private static function stageFrom(array $data, array $keys, array $intents): FunnelStage
    {
        $key = $data['key'];
        $type = $data['type'] ?? null;

        if (! in_array($type, [FunnelStage::TYPE_MESSAGE, FunnelStage::TYPE_AI], true)) {
            throw new InvalidArgumentException("Stage [{$key}] has an unsupported type.");
        }

        $text = '';
        $goal = trim((string) ($data['goal'] ?? ''));
        $instructions = trim((string) ($data['instructions'] ?? ''));

        if ($type === FunnelStage::TYPE_MESSAGE) {
            $text = self::requiredText($data['text'] ?? null, "Message stage [{$key}] needs its text.");

            if (mb_strlen($text) > self::MAX_TEXT_LENGTH) {
                throw new InvalidArgumentException("The text of stage [{$key}] is too long.");
            }
        } else {
            self::requiredText($goal, "Stage [{$key}] needs a goal.");
            self::requiredText($instructions, "Stage [{$key}] needs instructions.");
        }

        $context = $data['context'] ?? [];

        if (! is_array($context) || ! array_is_list($context) || array_diff($context, self::CONTEXTS) !== []) {
            throw new InvalidArgumentException("Stage [{$key}] asks for a context that is not allowed.");
        }

        $next = self::stageReference($data['next'] ?? null, $keys, "next of stage [{$key}]");

        if ($type === FunnelStage::TYPE_MESSAGE && $next === null) {
            throw new InvalidArgumentException("Message stage [{$key}] needs the stage that follows it.");
        }

        if ($next === $key && $type === FunnelStage::TYPE_MESSAGE) {
            throw new InvalidArgumentException("Message stage [{$key}] cannot follow itself.");
        }

        $max = $data['max_interactions'] ?? null;

        if ($max !== null && (! is_int($max) || $max < 1)) {
            throw new InvalidArgumentException("max_interactions of stage [{$key}] must be a positive whole number.");
        }

        $onLimit = self::stageReference($data['on_limit'] ?? null, $keys, "on_limit of stage [{$key}]");

        if ($max !== null && $onLimit === null) {
            throw new InvalidArgumentException("Stage [{$key}] limits its interactions, so it needs on_limit.");
        }

        $rules = $data['rules'] ?? [];

        if (! is_array($rules) || ! array_is_list($rules)) {
            throw new InvalidArgumentException("The rules of stage [{$key}] must be a list.");
        }

        return new FunnelStage(
            $key, $type, $goal, $instructions, trim((string) ($data['restrictions'] ?? '')), $text, array_values(array_unique($context)),
            (bool) ($data['uses_free_replies'] ?? false), $max, $onLimit, $next,
            array_map(fn ($rule) => self::rule($rule, $key, $keys, $intents), $rules),
        );
    }

    /**
     * @param  list<string>  $keys
     * @param  array<string, string>  $intents
     * @return array{condition: string, value: mixed, min_confidence: float, action: string, stage: ?string}
     */
    private static function rule(mixed $rule, string $stage, array $keys, array $intents): array
    {
        $when = is_array($rule) ? ($rule['when'] ?? null) : null;
        $then = is_array($rule) ? ($rule['then'] ?? null) : null;

        if (! is_array($when) || ! is_array($then)) {
            throw new InvalidArgumentException("A rule of stage [{$stage}] needs when and then.");
        }

        $conditions = array_values(array_intersect(array_keys($when), self::CONDITIONS));
        $unknown = array_diff(array_keys($when), self::CONDITIONS, ['min_confidence']);

        if (count($conditions) !== 1 || $unknown !== []) {
            throw new InvalidArgumentException("A rule of stage [{$stage}] needs exactly one supported condition.");
        }

        $condition = $conditions[0];
        $value = $when[$condition];

        match ($condition) {
            self::WHEN_PHRASE => self::assertPhrases($value, $stage),
            self::WHEN_INTENT => isset($intents[$value]) ?: throw new InvalidArgumentException("A rule of stage [{$stage}] uses an intent the funnel does not declare."),
            self::WHEN_INTERACTIONS => is_int($value) && $value >= 1 ?: throw new InvalidArgumentException("interactions_reached of stage [{$stage}] must be a positive whole number."),
            default => $value === true ?: throw new InvalidArgumentException("Condition [{$condition}] of stage [{$stage}] must be true."),
        };

        $confidence = $when['min_confidence'] ?? self::DEFAULT_MIN_CONFIDENCE;

        if (! is_numeric($confidence) || $confidence < 0 || $confidence > 1) {
            throw new InvalidArgumentException("min_confidence of stage [{$stage}] must be between 0 and 1.");
        }

        $action = $then['action'] ?? null;

        if (! in_array($action, self::ACTIONS, true)) {
            throw new InvalidArgumentException("A rule of stage [{$stage}] asks for an unsupported action.");
        }

        $target = self::stageReference($then['stage'] ?? null, $keys, "the target of a rule of stage [{$stage}]");

        if ($action === self::ACTION_GO_TO && $target === null) {
            throw new InvalidArgumentException("A go_to rule of stage [{$stage}] needs its target stage.");
        }

        return ['condition' => $condition, 'value' => $value, 'min_confidence' => (float) $confidence, 'action' => $action, 'stage' => $target];
    }

    private static function assertPhrases(mixed $phrases, string $stage): bool
    {
        if (! is_array($phrases) || ! array_is_list($phrases) || $phrases === []) {
            throw new InvalidArgumentException("The phrases of a rule of stage [{$stage}] must be a list.");
        }

        foreach ($phrases as $phrase) {
            if (! is_string($phrase) || OpeningMessage::normalize($phrase) === '') {
                throw new InvalidArgumentException("A phrase of a rule of stage [{$stage}] says nothing to compare.");
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $keys
     */
    private static function stageReference(mixed $value, array $keys, string $where): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value) || ! in_array($value, $keys, true)) {
            throw new InvalidArgumentException("{$where} points to a stage that does not exist.");
        }

        return $value;
    }

    private static function requiredText(mixed $value, string $message): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException($message);
        }

        return trim($value);
    }
}
