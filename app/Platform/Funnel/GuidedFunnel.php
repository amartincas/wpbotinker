<?php

namespace App\Platform\Funnel;

/**
 * A funnel configured with one instruction in natural language: the definition
 * it publishes is an ordinary one, with a single stage and a fixed set of
 * rules, so versions, states and the Runner work as with any other. The model
 * proposes what comes next among those rules; the Core validates and executes.
 */
final class GuidedFunnel
{
    public const STAGE = 'conversacion';

    public const INTENT_BUY = 'comprar';

    public const INTENT_OFFER = 'presentar_oferta';

    public const MAX_INSTRUCTIONS_LENGTH = 20000;

    /**
     * Every context a stage can read: KnowledgeAccess keeps the subscribers'
     * knowledge out of reach whatever this lists.
     *
     * @return array<string, mixed>
     */
    public static function definition(string $instructions, int $freeReplies): array
    {
        $texts = config('botinker.sales_funnels.guided');

        return [
            'guided' => true,
            'name' => $texts['name'],
            'goal' => $texts['goal'],
            'start_stage' => self::STAGE,
            'free_replies' => ['limit' => $freeReplies],
            'opening_consumes_free_reply' => false,
            'intents' => [
                ['key' => self::INTENT_BUY, 'description' => $texts['intents'][self::INTENT_BUY]],
                ['key' => self::INTENT_OFFER, 'description' => $texts['intents'][self::INTENT_OFFER]],
            ],
            'stages' => [[
                'key' => self::STAGE,
                'type' => FunnelStage::TYPE_AI,
                'goal' => $texts['stage_goal'],
                'instructions' => trim($instructions),
                'context' => FunnelDefinition::CONTEXTS,
                'uses_free_replies' => true,
                'rules' => [
                    ['when' => [FunnelDefinition::WHEN_PLAN_NAMED => true], 'then' => ['action' => FunnelDefinition::ACTION_START_PAYMENT]],
                    ['when' => [FunnelDefinition::WHEN_INTENT => self::INTENT_BUY], 'then' => ['action' => FunnelDefinition::ACTION_START_PAYMENT]],
                    ['when' => [FunnelDefinition::WHEN_INTENT => self::INTENT_OFFER], 'then' => ['action' => FunnelDefinition::ACTION_SHOW_OFFER]],
                ],
            ]],
        ];
    }

    /**
     * @param  array<string, mixed>  $definition  as stored, unvalidated
     */
    public static function isGuided(array $definition): bool
    {
        return ($definition['guided'] ?? false) === true;
    }

    /**
     * @param  array<string, mixed>  $definition  as stored, unvalidated
     */
    public static function instructions(array $definition): ?string
    {
        return self::isGuided($definition) ? ($definition['stages'][0]['instructions'] ?? null) : null;
    }
}
