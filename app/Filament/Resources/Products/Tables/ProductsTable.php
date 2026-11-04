<?php

namespace App\Filament\Resources\Products\Tables;

use App\Filament\Resources\Products\ProductResource;
use App\Models\Product;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('activeSalesFunnel:id,version'))
            ->columns([
                TextColumn::make('store.name')
                    ->label('Tienda')
                    ->searchable()
                    ->visible(Auth::user()?->is_super_admin),
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('price')
                    ->money()
                    ->sortable(),
                TextColumn::make('stock')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('funnel_status')
                    ->label('Embudo')
                    ->badge()
                    ->state(fn (Product $record) => match (true) {
                        ! $record->requiresSubscription() => 'No aplica',
                        $record->activeSalesFunnel !== null => "v{$record->activeSalesFunnel->version} activo",
                        default => 'Sin embudo',
                    })
                    ->color(fn (string $state) => str_ends_with($state, 'activo') ? 'success' : 'gray'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('store_id')
                    ->relationship('store', 'name')
                    ->label('Tienda')
                    // Its options name every store: only a super admin may see them.
                    ->visible(fn () => (bool) Auth::user()?->is_super_admin),
                SelectFilter::make('funnel')
                    ->label('Embudo')
                    ->options([
                        'active' => 'Con embudo activo',
                        'none' => 'Sin embudo',
                        'not_applicable' => 'No aplica (acceso libre)',
                    ])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'active' => $query->where('access_mode', Product::ACCESS_SUBSCRIPTION)->whereNotNull('active_sales_funnel_id'),
                        'none' => $query->where('access_mode', Product::ACCESS_SUBSCRIPTION)->whereNull('active_sales_funnel_id'),
                        'not_applicable' => $query->where('access_mode', '!=', Product::ACCESS_SUBSCRIPTION),
                        default => $query,
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                Action::make('funnel')
                    ->label('Embudo')
                    ->icon('heroicon-o-funnel')
                    ->url(fn (Product $record) => ProductResource::getUrl('funnel', ['record' => $record])),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
