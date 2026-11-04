<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Models\KnowledgeSource;
use App\Models\Plan;
use App\Models\Product;
use App\Platform\Funnel\FunnelDefinition;
use App\Platform\Funnel\FunnelStage;

/**
 * What FunnelDefinition accepts but is worth a second look: warnings, never a
 * reason to refuse what the contract allows.
 */
class FunnelPublicationChecks
{
    /**
     * @param  array<string, mixed>  $definition
     * @return list<string>
     */
    public static function warnings(Product $product, array $definition): array
    {
        $stages = array_column($definition['stages'] ?? [], null, 'key');
        $edges = self::edges($definition, $stages);

        return [
            ...self::openProduct($product),
            ...self::unreachable($definition, $stages, $edges),
            ...self::cycles($edges),
            ...self::messageRules($stages),
            ...self::freeRepliesWithoutExit($definition, $stages),
            ...self::paymentWithoutMeans($product, $stages),
            ...self::sampleWithoutSources($product, $stages),
        ];
    }

    /**
     * The editor does not keep rules in a message stage, where the engine
     * never evaluates them: whoever starts from such a version should know.
     *
     * @param  array<string, mixed>  $origin
     * @return list<string>
     */
    public static function discardedMessageRules(array $origin): array
    {
        return array_map(
            fn (string $key) => "La etapa «{$key}» de la versión de origen tenía reglas en un mensaje, donde el motor no las evalúa: no se publicarán.",
            self::stagesWithMessageRules(array_column($origin['stages'] ?? [], null, 'key')),
        );
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, array<string, mixed>>  $stages
     * @return array<string, list<string>> where each stage can lead, as the engine moves
     */
    private static function edges(array $definition, array $stages): array
    {
        $edges = [];

        foreach ($stages as $key => $stage) {
            $targets = [];

            if (($stage['type'] ?? null) === FunnelStage::TYPE_MESSAGE) {
                $targets[] = $stage['next'] ?? null;
            } else {
                $targets[] = $stage['on_limit'] ?? null;

                foreach ($stage['rules'] ?? [] as $rule) {
                    if (($rule['then']['action'] ?? null) !== FunnelDefinition::ACTION_STAY) {
                        $targets[] = $rule['then']['stage'] ?? null;
                    }
                }
            }

            if ($stage['uses_free_replies'] ?? false) {
                $targets[] = $definition['free_replies']['on_exhausted'] ?? null;
            }

            $edges[$key] = array_values(array_unique(array_filter($targets, fn (?string $target) => $target !== null && isset($stages[$target]))));
        }

        return $edges;
    }

    /**
     * @return list<string>
     */
    private static function openProduct(Product $product): array
    {
        return $product->requiresSubscription() ? [] : ['Este producto es de acceso libre: los embudos solo se ejecutan en productos con suscripción, así que este no se aplicará.'];
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, array<string, mixed>>  $stages
     * @param  array<string, list<string>>  $edges
     * @return list<string>
     */
    private static function unreachable(array $definition, array $stages, array $edges): array
    {
        $start = $definition['start_stage'] ?? array_key_first($stages);
        $reached = [];
        $pending = $start !== null ? [$start] : [];

        while ($pending !== []) {
            $key = array_pop($pending);

            if (! isset($reached[$key])) {
                $reached[$key] = true;
                array_push($pending, ...($edges[$key] ?? []));
            }
        }

        return array_map(
            fn (string $key) => "La etapa «{$key}» es inalcanzable: ninguna regla ni transición lleva a ella.",
            array_map('strval', array_keys(array_diff_key($stages, $reached))),
        );
    }

    /**
     * @param  array<string, list<string>>  $edges
     * @return list<string>
     */
    private static function cycles(array $edges): array
    {
        $found = [];
        $done = [];

        $visit = function (string $key, array $path) use (&$visit, &$found, &$done, $edges) {
            $path[] = $key;

            foreach ($edges[$key] ?? [] as $target) {
                $position = array_search($target, $path, true);

                if ($position !== false) {
                    $cycle = array_slice($path, $position);
                    $id = $cycle;
                    sort($id);
                    $found[implode("\0", $id)] = [...$cycle, $target];
                } elseif (! isset($done[$target])) {
                    $visit($target, $path);
                }
            }

            $done[$key] = true;
        };

        foreach (array_keys($edges) as $key) {
            if (! isset($done[$key])) {
                $visit((string) $key, []);
            }
        }

        return array_map(
            fn (array $cycle) => 'Ciclo entre etapas: '.implode(' → ', $cycle).'. El motor no se queda atascado, pero la conversación puede dar vueltas: revisa que sea intencional.',
            array_values($found),
        );
    }

    /**
     * @param  array<string, array<string, mixed>>  $stages
     * @return list<string>
     */
    private static function messageRules(array $stages): array
    {
        return array_map(
            fn (string $key) => "La etapa de mensaje «{$key}» tiene reglas: el motor no las evalúa en un mensaje.",
            self::stagesWithMessageRules($stages),
        );
    }

    /**
     * @param  array<string, array<string, mixed>>  $stages
     * @return list<string>
     */
    private static function stagesWithMessageRules(array $stages): array
    {
        $keys = array_keys(array_filter($stages, fn (array $stage) => ($stage['type'] ?? null) === FunnelStage::TYPE_MESSAGE && ($stage['rules'] ?? []) !== []));

        return array_map('strval', $keys);
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, array<string, mixed>>  $stages
     * @return list<string>
     */
    private static function freeRepliesWithoutExit(array $definition, array $stages): array
    {
        if (filled($definition['free_replies']['on_exhausted'] ?? null)) {
            return [];
        }

        return array_map(
            fn (string $key) => "La etapa «{$key}» gasta respuestas gratuitas, pero no hay etapa a la cual pasar al agotarse: el cliente recibirá solo la respuesta comercial del sistema.",
            array_map('strval', array_keys(array_filter($stages, fn (array $stage) => $stage['uses_free_replies'] ?? false))),
        );
    }

    /**
     * @param  array<string, array<string, mixed>>  $stages
     * @return list<string>
     */
    private static function paymentWithoutMeans(Product $product, array $stages): array
    {
        $startsPayment = false;

        foreach ($stages as $stage) {
            if (($stage['type'] ?? null) === FunnelStage::TYPE_AI && in_array(FunnelDefinition::ACTION_START_PAYMENT, array_column(array_column($stage['rules'] ?? [], 'then'), 'action'), true)) {
                $startsPayment = true;
            }
        }

        if (! $startsPayment) {
            return [];
        }

        return [
            ...(Plan::where('product_id', $product->id)->where('is_active', true)->exists() ? [] : ['Hay reglas que inician el pago, pero el producto no tiene planes activos: el cliente no podrá pagar.']),
            ...(filled($product->store?->payment_instructions) ? [] : ['Hay reglas que inician el pago, pero la tienda no tiene instrucciones de pago: el cliente no podrá pagar.']),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $stages
     * @return list<string>
     */
    private static function sampleWithoutSources(Product $product, array $stages): array
    {
        $using = array_keys(array_filter($stages, fn (array $stage) => ($stage['type'] ?? null) === FunnelStage::TYPE_AI && in_array(FunnelDefinition::CONTEXT_SAMPLE, $stage['context'] ?? [], true)));

        if ($using === [] || FunnelSummaryInfolist::activeSources($product, KnowledgeSource::PURPOSE_SAMPLE) > 0) {
            return [];
        }

        return array_map(fn (string $key) => "La etapa «{$key}» usa contenido de muestra, pero el producto no tiene fuentes de muestra activas.", array_map('strval', $using));
    }
}
