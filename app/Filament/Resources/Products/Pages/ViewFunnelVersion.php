<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Products\Schemas\FunnelVersionInfolist;
use App\Models\Product;
use App\Models\SalesFunnel;
use Filament\Actions\Action;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Schema;
use Livewire\Attributes\Locked;

/**
 * One published version, read only. It is looked up among the versions of the
 * product ProductResource resolved, so another product's version is a 404.
 */
class ViewFunnelVersion extends Page
{
    use InteractsWithRecord;

    protected static string $resource = ProductResource::class;

    #[Locked]
    public int $versionNumber;

    public function mount(int|string $record, int|string $version): void
    {
        $this->record = $this->resolveRecord($record);
        $this->versionNumber = (int) $version;

        $this->funnelVersion();
    }

    public function getTitle(): string
    {
        return "Embudo de «{$this->product()->name}»: versión {$this->versionNumber}";
    }

    public function getBreadcrumb(): string
    {
        return "Versión {$this->versionNumber}";
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToFunnel')
                ->label('Volver al embudo')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(ProductResource::getUrl('funnel', ['record' => $this->product()])),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components(FunnelVersionInfolist::components($this->product(), $this->funnelVersion()));
    }

    private function funnelVersion(): SalesFunnel
    {
        return $this->product()->salesFunnels()->where('version', $this->versionNumber)->firstOrFail();
    }

    private function product(): Product
    {
        /** @var Product */
        return $this->getRecord();
    }
}
