<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Models\ConversationFunnelState;
use App\Models\Product;
use App\Models\SalesFunnel;
use App\Platform\Commerce\SampleLedger;
use Filament\Infolists\Components\TextEntry;

/**
 * What the administrator reads before restoring a version: what it does, how
 * it differs from what is running now and who it affects. It reads the stored
 * definitions as they are, and changes nothing.
 */
class FunnelRestorePreview
{
    private const STAGE_FIELDS = [
        'type' => 'tipo',
        'text' => 'texto',
        'goal' => 'objetivo',
        'instructions' => 'instrucciones',
        'restrictions' => 'restricciones',
        'context' => 'contexto',
        'uses_free_replies' => 'uso de respuestas gratis',
        'max_interactions' => 'máximo de interacciones',
        'on_limit' => 'etapa al alcanzar el máximo',
        'next' => 'etapa siguiente',
        'rules' => 'reglas',
    ];

    /**
     * @return list<TextEntry>
     */
    public static function components(Product $product, SalesFunnel $version): array
    {
        $active = $product->activeFunnel();
        $next = (int) SalesFunnel::where('product_id', $product->id)->max('version') + 1;

        return [
            TextEntry::make('restore_effect')
                ->hiddenLabel()
                ->state("Se creará la versión v{$next} como copia de la v{$version->version} y se activará de inmediato para todos los prospectos de este producto. Las versiones anteriores no cambian. Se guardará la configuración actual del producto, no la que tenía la v{$version->version}."),
            TextEntry::make('restore_differences')
                ->label($active !== null ? "Diferencias con la versión activa (v{$active->version})" : 'Diferencias')
                ->state($active !== null ? self::differences($active->definition, $version->definition) : ['Este producto no tiene embudo activo: esta versión empezará a aplicarse a sus prospectos.'])
                ->listWithLineBreaks(),
            TextEntry::make('restore_impact')
                ->label('A quién afecta')
                ->state(self::impact($product, $active, $version))
                ->listWithLineBreaks()
                ->placeholder('No hay efectos sobre conversaciones en curso ni sobre el límite de respuestas gratis.'),
        ];
    }

    /**
     * What changes if the version replaces the active one.
     *
     * @param  array<string, mixed>  $active
     * @param  array<string, mixed>  $version
     * @return list<string>
     */
    public static function differences(array $active, array $version): array
    {
        $lines = [];

        foreach ([
            'Nombre' => fn (array $definition) => $definition['name'] ?? null,
            'Objetivo' => fn (array $definition) => $definition['goal'] ?? null,
            'Etapa inicial' => self::startStage(...),
            'Llamado a la acción de la oferta' => fn (array $definition) => $definition['offer_cta'] ?? null,
            'Respuestas gratis' => fn (array $definition) => $definition['free_replies']['limit'] ?? 0,
            'Etapa al agotarse las respuestas gratis' => fn (array $definition) => $definition['free_replies']['on_exhausted'] ?? null,
            'El mensaje inicial gasta una respuesta gratis' => fn (array $definition) => ($definition['opening_consumes_free_reply'] ?? true) ? 'Sí' : 'No',
        ] as $label => $read) {
            if ($read($active) !== $read($version)) {
                $lines[] = $label.': '.self::shown($read($active)).' → '.self::shown($read($version));
            }
        }

        $lines = [...$lines, ...self::intentDifferences($active, $version), ...self::stageDifferences($active, $version)];

        return $lines !== [] ? $lines : ['Sin diferencias de configuración.'];
    }

    /**
     * @return list<string>
     */
    private static function impact(Product $product, ?SalesFunnel $active, SalesFunnel $version): array
    {
        $lines = [];
        $keys = array_column($version->definition['stages'] ?? [], 'key');
        $displaced = ConversationFunnelState::where('product_id', $product->id)->whereNotIn('stage', $keys)->count();

        if ($displaced > 0) {
            $lines[] = "{$displaced} conversación(es) están en etapas que esta versión no tiene: volverán a la etapa inicial, sin repetir el mensaje inicial.";
        }

        $from = $active !== null ? (int) ($active->definition['free_replies']['limit'] ?? 0) : SampleLedger::limit();
        $to = (int) ($version->definition['free_replies']['limit'] ?? 0);

        if ($to < $from) {
            $lines[] = "El límite de respuestas gratis baja de {$from} a {$to}: las ya usadas no se reinician, así que algunos clientes pueden quedar sin respuestas gratis.";
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $active
     * @param  array<string, mixed>  $version
     * @return list<string>
     */
    private static function intentDifferences(array $active, array $version): array
    {
        $from = array_column($active['intents'] ?? [], 'description', 'key');
        $to = array_column($version['intents'] ?? [], 'description', 'key');
        $lines = [];

        foreach (array_diff_key($to, $from) as $key => $description) {
            $lines[] = "Se agrega la intención «{$key}»";
        }

        foreach (array_diff_key($from, $to) as $key => $description) {
            $lines[] = "Se elimina la intención «{$key}»";
        }

        foreach (array_intersect_key($to, $from) as $key => $description) {
            if ($description !== $from[$key]) {
                $lines[] = "Cambia la descripción de la intención «{$key}»";
            }
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $active
     * @param  array<string, mixed>  $version
     * @return list<string>
     */
    private static function stageDifferences(array $active, array $version): array
    {
        $from = array_column($active['stages'] ?? [], null, 'key');
        $to = array_column($version['stages'] ?? [], null, 'key');
        $lines = [];

        foreach (array_keys(array_diff_key($to, $from)) as $key) {
            $lines[] = "Se agrega la etapa «{$key}»";
        }

        foreach (array_keys(array_diff_key($from, $to)) as $key) {
            $lines[] = "Se elimina la etapa «{$key}»";
        }

        foreach (array_intersect_key($to, $from) as $key => $stage) {
            $changed = array_filter(
                self::STAGE_FIELDS,
                fn (string $field) => self::stageField($from[$key], $field) !== self::stageField($stage, $field),
                ARRAY_FILTER_USE_KEY,
            );

            if ($changed !== []) {
                $lines[] = "Etapa «{$key}»: cambia ".implode(', ', $changed);
            }
        }

        if (array_keys($from) !== array_keys($to) && array_diff_key($to, $from) === [] && array_diff_key($from, $to) === []) {
            $lines[] = 'Cambia el orden de las etapas';
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $stage
     */
    private static function stageField(array $stage, string $field): mixed
    {
        return $stage[$field] ?? match ($field) {
            'context', 'rules' => [],
            'uses_free_replies' => false,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private static function startStage(array $definition): ?string
    {
        return $definition['start_stage'] ?? ($definition['stages'][0]['key'] ?? null);
    }

    private static function shown(mixed $value): string
    {
        return $value === null ? '(sin valor)' : '«'.mb_strimwidth((string) $value, 0, 80, '…').'»';
    }
}
