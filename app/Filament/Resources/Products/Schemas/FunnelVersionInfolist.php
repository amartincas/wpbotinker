<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Models\Product;
use App\Models\SalesFunnel;
use App\Platform\Funnel\FunnelDefinition;
use App\Platform\Funnel\FunnelStage;
use App\Platform\Funnel\GuidedFunnel;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;

/**
 * A published version as stored, in the words of the panel. It reads the
 * definition directly, never through the engine's validation, so an old
 * version can always be consulted.
 */
class FunnelVersionInfolist
{
    public const CONTEXT_LABELS = [
        FunnelDefinition::CONTEXT_DESCRIPTION => 'Descripción del producto',
        FunnelDefinition::CONTEXT_FAQ => 'Información operativa',
        FunnelDefinition::CONTEXT_TOPIC => 'Reglas del tema',
        FunnelDefinition::CONTEXT_COMMERCIAL => 'Recursos comerciales',
        FunnelDefinition::CONTEXT_SAMPLE => 'Contenido de muestra',
    ];

    /**
     * @return list<Section>
     */
    public static function components(Product $product, SalesFunnel $version): array
    {
        $definition = $version->definition;

        if (GuidedFunnel::isGuided($definition)) {
            return [
                self::publication($product, $version),
                Section::make('Configuración')->columns(2)->schema([
                    self::longText('definition_instructions', 'Instrucciones para la IA', GuidedFunnel::instructions($definition)),
                    TextEntry::make('definition_free_limit')->label('Respuestas gratuitas')->state((string) ($definition['free_replies']['limit'] ?? 0)),
                ]),
                self::snapshot($version->product_snapshot ?? []),
            ];
        }

        return [
            self::publication($product, $version),
            self::general($definition),
            ...array_map(self::stage(...), array_keys($definition['stages'] ?? []), $definition['stages'] ?? []),
            self::snapshot($version->product_snapshot ?? []),
        ];
    }

    private static function publication(Product $product, SalesFunnel $version): Section
    {
        return Section::make('Publicación')->columns(4)->schema([
            TextEntry::make('version_status')->label('Estado')->badge()
                ->state($version->id === (int) $product->active_sales_funnel_id ? 'Activa' : 'Anterior')
                ->color(fn (string $state) => $state === 'Activa' ? 'success' : 'gray'),
            TextEntry::make('version_published_at')->label('Publicada')->state($version->published_at)->dateTime(),
            TextEntry::make('version_creator')->label('Publicada por')->state($version->creator?->name)->placeholder('—'),
            TextEntry::make('version_restored_from')->label('Restaurada de')
                ->state($version->restoredFrom ? "v{$version->restoredFrom->version}" : null)->placeholder('—'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private static function general(array $definition): Section
    {
        $free = $definition['free_replies'] ?? [];
        $intents = array_map(fn (array $intent) => "{$intent['key']}: {$intent['description']}", $definition['intents'] ?? []);

        return Section::make('General')->columns(2)->schema([
            TextEntry::make('definition_name')->label('Nombre')->state($definition['name'] ?? null)->placeholder('—'),
            TextEntry::make('definition_start')->label('Etapa inicial')->state($definition['start_stage'] ?? ($definition['stages'][0]['key'] ?? null))->placeholder('—'),
            self::longText('definition_goal', 'Objetivo', $definition['goal'] ?? null),
            TextEntry::make('definition_free_limit')->label('Respuestas gratis')->state((string) ($free['limit'] ?? 0)),
            TextEntry::make('definition_on_exhausted')->label('Al agotarse, etapa')->state($free['on_exhausted'] ?? null)->placeholder('—'),
            TextEntry::make('definition_opening_consumes')->label('El mensaje inicial gasta una respuesta gratis')
                ->state(($definition['opening_consumes_free_reply'] ?? true) ? 'Sí' : 'No'),
            self::longText('definition_cta', 'Llamado a la acción de la oferta', $definition['offer_cta'] ?? null),
            TextEntry::make('definition_intents')->label('Intenciones')->state($intents)->listWithLineBreaks()->placeholder('—')->columnSpanFull(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $stage
     */
    private static function stage(int $index, array $stage): Section
    {
        $isMessage = ($stage['type'] ?? null) === FunnelStage::TYPE_MESSAGE;
        $prefix = "stage_{$index}";
        $contexts = array_map(fn (string $context) => self::CONTEXT_LABELS[$context] ?? $context, $stage['context'] ?? []);
        $rules = array_map(self::ruleLine(...), $stage['rules'] ?? []);

        $common = [
            TextEntry::make("{$prefix}_free")->label('Gasta respuestas gratis')->state(($stage['uses_free_replies'] ?? false) ? 'Sí' : 'No'),
        ];

        $entries = $isMessage
            ? [
                self::longText("{$prefix}_text", 'Texto', $stage['text'] ?? null),
                TextEntry::make("{$prefix}_next")->label('Etapa siguiente')->state($stage['next'] ?? null)->placeholder('—'),
                ...$common,
            ]
            : [
                self::longText("{$prefix}_goal", 'Objetivo', $stage['goal'] ?? null),
                self::longText("{$prefix}_instructions", 'Instrucciones para la IA', $stage['instructions'] ?? null),
                self::longText("{$prefix}_restrictions", 'Restricciones', $stage['restrictions'] ?? null),
                TextEntry::make("{$prefix}_context")->label('Contexto que puede usar')->state($contexts)->listWithLineBreaks()->placeholder('Ninguno'),
                ...$common,
                TextEntry::make("{$prefix}_max")->label('Máximo de interacciones')->state(isset($stage['max_interactions']) ? (string) $stage['max_interactions'] : null)->placeholder('Sin límite'),
                TextEntry::make("{$prefix}_on_limit")->label('Al alcanzar el máximo, etapa')->state($stage['on_limit'] ?? null)->placeholder('—'),
            ];

        return Section::make('Etapa: '.($stage['key'] ?? '—'))
            ->description($isMessage ? 'Mensaje: se envía exactamente como está escrito.' : 'IA: conversa hacia su objetivo.')
            ->collapsible()
            ->columns(2)
            ->schema([
                ...$entries,
                TextEntry::make("{$prefix}_rules")
                    ->label($isMessage ? 'Reglas (no se evalúan en etapas de mensaje)' : 'Reglas, en su orden')
                    ->state($rules)
                    ->listWithLineBreaks()
                    ->placeholder('Sin reglas')
                    ->columnSpanFull(),
            ]);
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    public static function ruleLine(array $rule): string
    {
        $when = $rule['when'] ?? [];
        $then = $rule['then'] ?? [];
        $target = $then['stage'] ?? null;

        $condition = match (true) {
            array_key_exists(FunnelDefinition::WHEN_PHRASE, $when) => 'El mensaje es exactamente '.implode(' o ', array_map(fn ($phrase) => "«{$phrase}»", (array) $when[FunnelDefinition::WHEN_PHRASE])),
            array_key_exists(FunnelDefinition::WHEN_PLAN_NAMED, $when) => 'El cliente elige un plan por su nombre',
            array_key_exists(FunnelDefinition::WHEN_INTENT, $when) => "Intención «{$when[FunnelDefinition::WHEN_INTENT]}» con confianza de al menos ".($when['min_confidence'] ?? FunnelDefinition::DEFAULT_MIN_CONFIDENCE),
            array_key_exists(FunnelDefinition::WHEN_ANY_REPLY, $when) => 'Después de cualquier respuesta',
            array_key_exists(FunnelDefinition::WHEN_INTERACTIONS, $when) => "Al llegar a {$when[FunnelDefinition::WHEN_INTERACTIONS]} interacciones",
            array_key_exists(FunnelDefinition::WHEN_FREE_EXHAUSTED, $when) => 'Se agotaron las respuestas gratis',
            default => 'Condición no reconocida',
        };

        $action = match ($then['action'] ?? null) {
            FunnelDefinition::ACTION_GO_TO => "ir a la etapa «{$target}»",
            FunnelDefinition::ACTION_SHOW_OFFER => 'mostrar la oferta'.($target !== null ? " y seguir en «{$target}»" : ''),
            FunnelDefinition::ACTION_START_PAYMENT => 'iniciar el pago'.($target !== null ? " y seguir en «{$target}»" : ''),
            FunnelDefinition::ACTION_STAY => 'permanecer en la etapa',
            default => 'acción no reconocida',
        };

        return "{$condition}: {$action}.";
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private static function snapshot(array $snapshot): Section
    {
        $plans = array_map(
            fn (array $plan) => "{$plan['name']}: {$plan['price']} {$plan['currency']} por {$plan['duration_days']} días",
            $snapshot['plans'] ?? [],
        );

        return Section::make('Producto al publicar')
            ->description('Solo para consulta: el embudo usa los datos actuales del producto, no estos.')
            ->collapsible()
            ->collapsed()
            ->columns(2)
            ->schema([
                TextEntry::make('snapshot_name')->label('Nombre')->state($snapshot['name'] ?? null)->placeholder('—'),
                TextEntry::make('snapshot_access')->label('Acceso')->state($snapshot['access_mode'] ?? null)->placeholder('—'),
                self::longText('snapshot_description', 'Descripción', $snapshot['description'] ?? null),
                self::longText('snapshot_opening', 'Mensaje inicial', $snapshot['opening_message'] ?? null),
                TextEntry::make('snapshot_triggers')->label('Activadores del mensaje inicial')->state(implode(', ', $snapshot['opening_triggers'] ?? []) ?: null)->placeholder('—')->columnSpanFull(),
                self::longText('snapshot_commercial', 'Instrucciones comerciales', $snapshot['commercial_instructions'] ?? null),
                self::longText('snapshot_closing', 'Cierre de las respuestas gratis', $snapshot['sample_closing_message'] ?? null),
                TextEntry::make('snapshot_plans')->label('Planes activos')->state($plans)->listWithLineBreaks()->placeholder('Ninguno')->columnSpanFull(),
            ]);
    }

    private static function longText(string $name, string $label, ?string $text): TextEntry
    {
        return TextEntry::make($name)
            ->label($label)
            ->state($text)
            ->placeholder('—')
            ->extraAttributes(['style' => 'white-space: pre-wrap'])
            ->columnSpanFull();
    }
}
