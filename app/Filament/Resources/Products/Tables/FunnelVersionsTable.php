<?php

namespace App\Filament\Resources\Products\Tables;

use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Products\Schemas\FunnelRestorePreview;
use App\Models\Product;
use App\Models\SalesFunnel;
use App\Platform\Funnel\FunnelDefinition;
use App\Platform\Funnel\SalesFunnelVersions;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

/** The history of a product's funnel: every version, to consult or restore. */
class FunnelVersionsTable
{
    public static function configure(Table $table, Product $product): Table
    {
        return $table
            ->heading('Historial de versiones')
            ->emptyStateHeading('Este producto aún no tiene versiones publicadas')
            ->defaultSort('version', 'desc')
            ->recordUrl(fn (SalesFunnel $record) => self::versionUrl($product, $record))
            ->columns([
                TextColumn::make('version')
                    ->label('Versión')
                    ->formatStateUsing(fn (int $state) => "v{$state}")
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->state(fn (SalesFunnel $record) => $record->id === (int) $product->active_sales_funnel_id ? 'Activa' : 'Anterior')
                    ->color(fn (string $state) => $state === 'Activa' ? 'success' : 'gray')
                    ->description(fn (SalesFunnel $record) => self::passesValidation($record) ? null : 'No se puede restaurar: su definición no supera la validación actual.'),
                TextColumn::make('name')
                    ->label('Nombre')
                    ->state(fn (SalesFunnel $record) => $record->definition['name'] ?? null)
                    ->placeholder('—'),
                TextColumn::make('published_at')
                    ->label('Publicada')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('creator.name')
                    ->label('Publicada por')
                    ->placeholder('—'),
                TextColumn::make('restoredFrom.version')
                    ->label('Restaurada de')
                    ->formatStateUsing(fn (int $state) => "v{$state}")
                    ->placeholder('—'),
            ])
            ->recordActions([
                Action::make('viewVersion')
                    ->label('Ver')
                    ->icon('heroicon-o-eye')
                    ->url(fn (SalesFunnel $record) => self::versionUrl($product, $record)),
                Action::make('editFromVersion')
                    ->label('Editar a partir de esta')
                    ->icon('heroicon-o-pencil')
                    ->url(fn (SalesFunnel $record) => ProductResource::getUrl('funnel-edit', ['record' => $product, 'from' => $record->version])),
                Action::make('restore')
                    ->label('Restaurar')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(fn (SalesFunnel $record) => self::canRestore($product, $record))
                    ->modalHeading(fn (SalesFunnel $record) => "Restaurar la versión v{$record->version}")
                    ->modalWidth(Width::TwoExtraLarge)
                    ->modalSubmitActionLabel('Crear la versión y activarla')
                    ->schema(fn (SalesFunnel $record) => FunnelRestorePreview::components($product, $record))
                    ->action(function (SalesFunnel $record, $livewire) use ($product) {
                        try {
                            $restored = self::restore($product, $record);
                        } catch (InvalidArgumentException) {
                            Notification::make()->danger()->title('Esta versión ya no se puede restaurar: su configuración no es válida.')->send();

                            return;
                        }

                        Notification::make()->success()->title("Se creó y activó la versión v{$restored->version}.")->send();

                        $livewire->redirect(ProductResource::getUrl('funnel', ['record' => $product]));
                    }),
            ]);
    }

    /**
     * The version must be of the product the page resolved, which is the one
     * this store may administer. The service then adds the new version.
     */
    public static function restore(Product $product, SalesFunnel $version): SalesFunnel
    {
        abort_unless((int) $version->product_id === $product->id, 404);

        return app(SalesFunnelVersions::class)->restore($version, Auth::id());
    }

    /** Not the one running, which would only repeat it, nor one the engine would reject. */
    private static function canRestore(Product $product, SalesFunnel $version): bool
    {
        return $version->id !== (int) $product->active_sales_funnel_id && self::passesValidation($version);
    }

    private static function passesValidation(SalesFunnel $version): bool
    {
        try {
            FunnelDefinition::fromArray($version->definition);
        } catch (InvalidArgumentException) {
            return false;
        }

        return true;
    }

    private static function versionUrl(Product $product, SalesFunnel $version): string
    {
        return ProductResource::getUrl('funnel-version', ['record' => $product, 'version' => $version->version]);
    }
}
