<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Products\Schemas\FunnelSummaryInfolist;
use App\Filament\Resources\Products\Tables\FunnelVersionsTable;
use App\Models\Product;
use App\Platform\Commerce\SampleLedger;
use App\Platform\Funnel\SalesFunnelVersions;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * A product's sales funnel: its active version, what it depends on and every
 * published version, with what can be done to them. ProductResource resolves
 * the product, so a product of another store is a 404.
 */
class ManageProductFunnel extends ManageRelatedRecords
{
    protected static string $resource = ProductResource::class;

    protected static string $relationship = 'salesFunnels';

    public function getTitle(): string
    {
        return "Embudo de «{$this->product()->name}»";
    }

    public function getBreadcrumb(): string
    {
        return 'Embudo';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('editProduct')
                ->label('Editar producto')
                ->icon('heroicon-o-pencil-square')
                ->color('gray')
                ->url(ProductResource::getUrl('edit', ['record' => $this->product()])),
            Action::make('editFunnel')
                ->label('Editar embudo')
                ->icon('heroicon-o-pencil')
                ->url(ProductResource::getUrl('funnel-edit', ['record' => $this->product()])),
            $this->deactivateAction(),
        ];
    }

    private function deactivateAction(): Action
    {
        $product = $this->product();

        return Action::make('deactivate')
            ->label('Desactivar embudo')
            ->icon('heroicon-o-pause-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn () => $product->active_sales_funnel_id !== null)
            ->modalHeading('Desactivar el embudo')
            ->modalDescription(fn () => 'El producto quedará sin embudo activo de inmediato y se aplicará el límite global de respuestas gratuitas ('.SampleLedger::limit().'). Todas las versiones se conservan. Las conversaciones existentes conservan su estado y progreso: si después se reactiva un embudo compatible, podrán retomar su etapa anterior.')
            ->modalSubmitActionLabel('Desactivar')
            ->action(function () use ($product) {
                $versions = app(SalesFunnelVersions::class);
                $active = $versions->active($product);

                if ($active !== null) {
                    $versions->deactivate($product);

                    Log::info('FUNNEL_DEACTIVATED', ['user_id' => Auth::id(), 'store_id' => $product->store_id, 'product_id' => $product->id, 'version' => $active->version, 'at' => now()->toIso8601String()]);
                }

                Notification::make()->success()->title('El embudo quedó desactivado.')->send();

                $this->redirect(ProductResource::getUrl('funnel', ['record' => $product]));
            });
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            ...FunnelSummaryInfolist::components($this->product()),
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        return FunnelVersionsTable::configure($table, $this->product());
    }

    private function product(): Product
    {
        /** @var Product */
        return $this->getOwnerRecord();
    }
}
