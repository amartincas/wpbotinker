<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Models\ConversationFunnelState;
use App\Models\Product;
use App\Models\SalesFunnel;
use App\Platform\Commerce\SampleLedger;
use App\Platform\Funnel\GuidedFunnel;
use Filament\Infolists\Components\TextEntry;

/**
 * What the administrator reads before publishing: the version, what changes
 * from the active one, who it affects and what is worth a second look. It
 * reads and changes nothing.
 *
 * @see FunnelRestorePreview, whose comparison of definitions it reuses
 */
class FunnelPublicationPreview
{
    /**
     * @param  array<string, mixed>  $definition
     * @param  list<string>  $warnings
     * @return list<TextEntry>
     */
    public static function components(Product $product, array $definition, array $warnings): array
    {
        $active = $product->activeFunnel();
        $next = (int) SalesFunnel::where('product_id', $product->id)->max('version') + 1;

        return [
            TextEntry::make('publish_effect')
                ->hiddenLabel()
                ->state("Se publicará la versión v{$next} y se activará de inmediato para todos los prospectos de este producto. Las versiones anteriores no cambian."),
            TextEntry::make('publish_conversations_warning')
                ->hiddenLabel()
                ->state(GuidedFunnel::isGuided($definition)
                    ? 'Las conversaciones en curso siguen con esta instrucción, sin repetir el mensaje inicial. Las respuestas gratuitas ya usadas no se reinician.'
                    : 'Las conversaciones pueden regresar al inicio si desaparece su etapa. Las respuestas gratuitas ya usadas (turns_used) no se reinician.'),
            TextEntry::make('publish_differences')
                ->label($active !== null ? "Cambios frente a la versión activa (v{$active->version})" : 'Cambios')
                ->state($active !== null ? FunnelRestorePreview::differences($active->definition, $definition) : ['Este producto no tiene embudo activo: esta versión empezará a aplicarse a sus prospectos.'])
                ->listWithLineBreaks(),
            TextEntry::make('publish_displaced')
                ->label('Conversaciones en etapas que desaparecen')
                ->visible(! GuidedFunnel::isGuided($definition))
                ->state(self::displacedConversations($product, $definition))
                ->listWithLineBreaks()
                ->placeholder('Ninguna.'),
            TextEntry::make('publish_free_limit')
                ->label('Límite de respuestas gratuitas')
                ->state(self::freeLimitChange($active, $definition)),
            TextEntry::make('publish_warnings')
                ->label('Advertencias de configuración')
                ->state($warnings)
                ->listWithLineBreaks()
                ->placeholder('Ninguna.'),
        ];
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return list<string>
     */
    public static function displacedConversations(Product $product, array $definition): array
    {
        return ConversationFunnelState::where('product_id', $product->id)
            ->whereNotIn('stage', array_column($definition['stages'] ?? [], 'key'))
            ->selectRaw('stage, COUNT(*) AS total')
            ->groupBy('stage')
            ->orderByDesc('total')
            ->orderBy('stage')
            ->get()
            ->map(fn (ConversationFunnelState $state) => "«{$state->stage}»: {$state->total} conversación(es), que volverán a la etapa inicial.")
            ->all();
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    public static function freeLimitChange(?SalesFunnel $active, array $definition): string
    {
        $from = $active !== null ? (int) ($active->definition['free_replies']['limit'] ?? 0) : SampleLedger::limit();
        $to = (int) ($definition['free_replies']['limit'] ?? 0);
        $origin = $active !== null ? '' : ' (el límite global, sin embudo activo)';

        return match (true) {
            $to === $from => "Se mantiene en {$to}{$origin}.",
            $to < $from => "Baja de {$from}{$origin} a {$to}: algunos clientes pueden quedar sin respuestas gratuitas.",
            default => "Sube de {$from}{$origin} a {$to}.",
        };
    }
}
