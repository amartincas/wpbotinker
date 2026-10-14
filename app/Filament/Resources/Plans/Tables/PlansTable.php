<?php

namespace App\Filament\Resources\Plans\Tables;

use App\Models\Plan;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class PlansTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('store.name')
                    ->label('Tienda')
                    ->visible(Auth::user()?->is_super_admin),
                TextColumn::make('product.name')
                    ->label('Producto')
                    ->searchable(),
                TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable(),
                TextColumn::make('price')
                    ->label('Precio')
                    ->money(fn (Plan $record) => $record->currency)
                    ->sortable(),
                TextColumn::make('duration_days')
                    ->label('Días')
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('Activo')
                    ->boolean(),
            ])
            ->defaultSort('sort_order')
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
