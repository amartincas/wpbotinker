<?php

namespace App\Filament\Resources\Leads\Tables;

use App\Models\Lead;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class LeadsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // 'Unknown' rows are bot on/off toggle control records and
            // proactive-template placeholders (see WhatsAppChatCenter.php) —
            // not real leads, so they shouldn't clutter this list.
            ->modifyQueryUsing(fn (Builder $query) => $query->where(
                fn (Builder $q) => $q->whereNull('customer_name')->orWhere('customer_name', '!=', 'Unknown')
            ))
            ->columns([
                TextColumn::make('store.name')
                    ->label('Tienda')
                    ->searchable()
                    ->sortable()
                    ->visible(Auth::user()?->is_super_admin),
                TextColumn::make('customer_name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('customer_phone')
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                TextColumn::make('product_service_name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('summary')
                    ->limit(50)
                    ->tooltip(function (TextColumn $column): ?string {
                        return $column->getState();
                    })
                    ->wrap(),
                TextColumn::make('created_at')
                    ->since()
                    ->sortable(),
                TextColumn::make('order_status')
                    ->label('Estado del pedido')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => $state ? (Lead::ORDER_STATUSES[$state] ?? $state) : '—')
                    ->color(fn (?string $state) => match ($state) {
                        'confirmado' => 'info',
                        'enviado' => 'warning',
                        'entregado' => 'success',
                        'devuelto' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
                IconColumn::make('needs_address_review')
                    ->label('Dirección')
                    ->boolean()
                    ->trueIcon('heroicon-o-exclamation-triangle')
                    ->falseIcon('heroicon-o-check-circle')
                    ->trueColor('warning')
                    ->falseColor('success')
                    ->tooltip(fn (bool $state) => $state ? 'Falta ciudad/región — confirmar con el cliente' : 'Dirección completa'),
                ToggleColumn::make('is_processed')
                    ->label('Procesado')
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_processed')
                    ->label('Estado de procesamiento')
                    ->placeholder('Todos')
                    ->trueLabel('Procesado')
                    ->falseLabel('Sin procesar'),
                SelectFilter::make('store_id')
                    ->relationship('store', 'name')
                    ->label('Tienda'),
                SelectFilter::make('order_status')
                    ->label('Estado del pedido')
                    ->options(Lead::ORDER_STATUSES),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
