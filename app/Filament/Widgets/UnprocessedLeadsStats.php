<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Leads\LeadResource;
use App\Models\Lead;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

class UnprocessedLeadsStats extends BaseWidget
{
    protected function getStats(): array
    {
        $storeId = Auth::user()->store_id;

        $unprocessedCount = Lead::where('store_id', $storeId)
            ->where('is_processed', false)
            ->count();

        $totalCount = Lead::where('store_id', $storeId)->count();
        $processedCount = $totalCount - $unprocessedCount;

        return [
            Stat::make('Leads sin procesar', $unprocessedCount)
                ->description('Leads pendientes de seguimiento')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('warning')
                ->url(LeadResource::getUrl('index', [
                    'tableFilters' => ['is_processed' => ['value' => false]],
                ])),

            Stat::make('Leads procesados', $processedCount)
                ->description('Leads atendidos con éxito')
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('success'),

            Stat::make('Total de leads', $totalCount)
                ->description('Todos los leads')
                ->descriptionIcon('heroicon-m-document')
                ->color('info'),
        ];
    }
}
