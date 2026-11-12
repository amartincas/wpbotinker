<?php

namespace App\Platform\Funnel;

use App\Platform\Commerce\OpeningMessage;
use Illuminate\Support\Str;

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

    public const MAX_REACTIVATION_LENGTH = 1000;

    public const DEFAULT_REACTIVATION_HOURS = 2;

    /** Under WhatsApp's 24-hour window, with StoreMessenger's margin. */
    public const MAX_REACTIVATION_HOURS = 23;

    /**
     * Every context a stage can read: KnowledgeAccess keeps the subscribers'
     * knowledge out of reach whatever this lists.
     *
     * @return array<string, mixed>
     */
    public static function definition(string $instructions, int $freeReplies, ?string $reactivationMessage = null, int $reactivationHours = self::DEFAULT_REACTIVATION_HOURS): array
    {
        $texts = config('botinker.sales_funnels.guided');
        $reactivationMessage = trim((string) $reactivationMessage);

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
            // Without a message there is no reactivation, and no key.
            ...($reactivationMessage !== '' ? ['reactivation' => ['message' => $reactivationMessage, 'after_hours' => $reactivationHours]] : []),
        ];
    }

    /**
     * The reactivation a guided definition configures: its literal message and
     * the hours of silence before it. Null when it is not guided or has none.
     *
     * @param  array<string, mixed>  $definition  as stored
     * @return array{message: string, after_hours: int}|null
     */
    public static function reactivation(array $definition): ?array
    {
        $reactivation = self::isGuided($definition) ? ($definition['reactivation'] ?? null) : null;
        $message = trim((string) ($reactivation['message'] ?? ''));
        $hours = $reactivation['after_hours'] ?? null;

        if ($message === '' || ! is_int($hours) || $hours < 1 || $hours > self::MAX_REACTIVATION_HOURS) {
            return null;
        }

        return ['message' => $message, 'after_hours' => $hours];
    }

    /** The whole message is one of the configured refusals: it ends this episode. */
    public static function declines(string $message): bool
    {
        $text = self::plain($message);

        foreach ((array) config('botinker.sales_funnels.guided.decline_phrases', []) as $phrase) {
            if (self::plain((string) $phrase) === $text) {
                return true;
            }
        }

        return false;
    }

    /**
     * The whole message, greetings and courtesies aside, is a request not to be
     * written to again. Within a longer message the same words may quote
     * someone else or concern another service.
     */
    public static function asksToStop(string $message): bool
    {
        $text = self::withoutCourtesies(self::plain($message));

        foreach ((array) config('botinker.sales_funnels.guided.stop_phrases', []) as $phrase) {
            $phrase = self::plain((string) $phrase);

            if ($phrase !== '' && $phrase === $text) {
                return true;
            }
        }

        return false;
    }

    /** The whole message is greetings and courtesies, nothing else: «Hola, buenas noches», «¡Gracias!». */
    public static function isCourtesy(string $message): bool
    {
        $courtesy = '(?:hola|buenas tardes|buenas noches|buenos dias|buen dia|buenas|saludos|que tal|mucho gusto|un gusto|por favor|muchas gracias|mil gracias|gracias|muy amable)';

        return preg_match("/^(?:{$courtesy}(?:\s+|$))+$/", self::plain($message)) === 1;
    }

    /** "hola por favor no me escribas mas gracias" is "no me escribas mas". */
    private static function withoutCourtesies(string $text): string
    {
        $courtesy = '(?:hola|buenas tardes|buenas noches|buenos dias|buen dia|buenas|saludos|por favor|muchas gracias|gracias)';

        return trim((string) preg_replace(["/^(?:{$courtesy}\s+)+/", "/(?:\s+{$courtesy})+$/"], '', $text));
    }

    /** Lowercase words without accents or punctuation: "No, gracias." is "no gracias". */
    private static function plain(string $text): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', Str::ascii(mb_strtolower($text))));
    }

    /**
     * The operation a whole message asks for, in the configured phrases: what
     * the Core can serve after the free replies without calling the model.
     */
    public static function commercialAction(string $message): ?string
    {
        $text = OpeningMessage::normalize($message);

        foreach ([
            FunnelDefinition::ACTION_START_PAYMENT => 'purchase_phrases',
            FunnelDefinition::ACTION_SHOW_OFFER => 'offer_phrases',
        ] as $action => $phrases) {
            foreach ((array) config("botinker.sales_funnels.guided.{$phrases}", []) as $phrase) {
                if (OpeningMessage::normalize((string) $phrase) === $text) {
                    return $action;
                }
            }
        }

        return null;
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
