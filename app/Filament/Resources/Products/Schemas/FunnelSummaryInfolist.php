<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Filament\Resources\KnowledgeSources\KnowledgeSourceResource;
use App\Filament\Resources\Plans\PlanResource;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Stores\StoreResource;
use App\Models\ConversationFunnelState;
use App\Models\KnowledgeSource;
use App\Models\Plan;
use App\Models\Product;
use App\Models\SalesFunnel;
use App\Platform\Funnel\GuidedFunnel;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;

/**
 * What a product's funnel page shows above its history. It reads the stored
 * definition as it is, never through the engine's validation, so an old
 * version can always be consulted.
 */
class FunnelSummaryInfolist
{
    /**
     * @return list<Section>
     */
    public static function components(Product $product): array
    {
        return [self::activeVersion($product), self::productConfiguration($product)];
    }

    private static function activeVersion(Product $product): Section
    {
        $active = $product->activeFunnel();
        $section = Section::make('Versión activa')->columns(3);

        if (! $product->requiresSubscription()) {
            $section->description('Este producto es de acceso libre: los embudos solo se ejecutan en productos con suscripción.');
        }

        if ($active === null) {
            return $section
                ->description($product->requiresSubscription() ? 'Este producto funciona sin embudo.' : $section->getDescription())
                ->schema([]);
        }

        $definition = $active->definition;
        $free = $definition['free_replies'] ?? [];

        if (GuidedFunnel::isGuided($definition)) {
            return $section->schema([
                TextEntry::make('active_version')->label('Versión')->state("v{$active->version}")
                    ->url(ProductResource::getUrl('funnel-version', ['record' => $product, 'version' => $active->version])),
                TextEntry::make('active_published_at')->label('Publicada')->state($active->published_at)->dateTime(),
                TextEntry::make('active_creator')->label('Publicada por')->state($active->creator?->name)->placeholder('—'),
                TextEntry::make('active_free_limit')->label('Respuestas gratuitas')->state((string) ($free['limit'] ?? 0)),
                TextEntry::make('active_conversations')->label('Conversaciones')->state((string) ConversationFunnelState::where('product_id', $product->id)->count()),
                TextEntry::make('active_instructions')->label('Instrucciones para la IA')->state(GuidedFunnel::instructions($definition))
                    ->extraAttributes(['style' => 'white-space: pre-wrap'])->columnSpanFull(),
            ]);
        }

        return $section->schema([
            TextEntry::make('active_version')->label('Versión')->state("v{$active->version}")
                ->url(ProductResource::getUrl('funnel-version', ['record' => $product, 'version' => $active->version])),
            TextEntry::make('active_name')->label('Nombre')->state($definition['name'] ?? null)->placeholder('—'),
            TextEntry::make('active_published_at')->label('Publicada')->state($active->published_at)->dateTime(),
            TextEntry::make('active_creator')->label('Publicada por')->state($active->creator?->name)->placeholder('—'),
            TextEntry::make('active_start')->label('Etapa inicial')->state($definition['start_stage'] ?? ($definition['stages'][0]['key'] ?? null))->placeholder('—'),
            TextEntry::make('active_stages')->label('Etapas')->state(count($definition['stages'] ?? [])),
            TextEntry::make('active_free_limit')->label('Respuestas gratis')->state((string) ($free['limit'] ?? 0)),
            TextEntry::make('active_on_exhausted')->label('Al agotarse, etapa')->state($free['on_exhausted'] ?? null)->placeholder('—'),
            TextEntry::make('active_goal')->label('Objetivo')->state($definition['goal'] ?? null)->placeholder('—')->columnSpanFull(),
            TextEntry::make('active_conversations')->label('Conversaciones por etapa')
                ->state(self::conversationsByStage($product, $active))
                ->listWithLineBreaks()
                ->placeholder('Ninguna conversación ha entrado al embudo')
                ->columnSpanFull(),
        ]);
    }

    /**
     * @return list<string>
     */
    private static function conversationsByStage(Product $product, SalesFunnel $active): array
    {
        return ConversationFunnelState::where('product_id', $product->id)
            ->selectRaw('stage, COUNT(*) AS total')
            ->groupBy('stage')
            ->orderByDesc('total')
            ->orderBy('stage')
            ->get()
            ->map(fn (ConversationFunnelState $state) => "{$state->stage}: {$state->total}".($active->hasStage($state->stage) ? '' : ' (esta versión no tiene esa etapa: vuelven al inicio)'))
            ->all();
    }

    /**
     * Data of the product and the store, not of the version: a change here
     * applies at once and publishes nothing.
     */
    private static function productConfiguration(Product $product): Section
    {
        $triggers = $product->opening_triggers ?? [];
        $plans = Plan::where('product_id', $product->id)->where('is_active', true)->count();

        return Section::make('Configuración del producto que usa el embudo')
            ->description('Se lee del producto al ejecutar: cambiarla no crea una versión. Con un embudo activo no se usan «Instrucciones de las respuestas gratuitas» ni «Datos que necesita».'
                .(GuidedFunnel::isGuided($product->activeFunnel()->definition ?? []) ? ' Tampoco se usan sus «Instrucciones comerciales» para redactar: prevalece la instrucción del embudo.' : ''))
            ->columns(2)
            ->schema([
                TextEntry::make('opening_message')->label('Mensaje inicial')->state($product->opening_message)->placeholder('Sin configurar')
                    ->url(ProductResource::getUrl('edit', ['record' => $product]))->columnSpanFull(),
                TextEntry::make('opening_triggers')->label('Activadores del mensaje inicial')->state($triggers === [] ? null : implode(', ', $triggers))->placeholder('Sin configurar')->columnSpanFull(),
                TextEntry::make('active_plans')->label('Planes activos')->state((string) $plans)->url(PlanResource::getUrl('index')),
                TextEntry::make('payment_instructions')->label('Instrucciones de pago de la tienda')
                    ->state(filled($product->store?->payment_instructions) ? 'Configuradas' : 'Sin configurar')
                    ->url(StoreResource::getUrl('edit', ['record' => $product->store_id])),
                TextEntry::make('commercial_sources')->label('Fuentes comerciales activas')
                    ->state((string) self::activeSources($product, KnowledgeSource::PURPOSE_COMMERCIAL))->url(KnowledgeSourceResource::getUrl('index')),
                TextEntry::make('sample_sources')->label('Fuentes de muestra activas')
                    ->state((string) self::activeSources($product, KnowledgeSource::PURPOSE_SAMPLE))->url(KnowledgeSourceResource::getUrl('index')),
            ]);
    }

    /** The sources a stage of this product could read: its own and those of its whole topic. */
    public static function activeSources(Product $product, string $purpose): int
    {
        return KnowledgeSource::where('store_id', $product->store_id)
            ->where('domain_key', $product->domain_key)
            ->where('purpose', $purpose)
            ->where('status', KnowledgeSource::STATUS_ACTIVE)
            ->where(fn ($query) => $query->whereNull('product_id')->orWhere('product_id', $product->id))
            ->count();
    }
}
