<?php

namespace App\Filament\Resources\Topics\Tables;

use App\Models\Topic;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class TopicsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('store.name')
                    ->label('Tienda')
                    ->visible(Auth::user()?->is_super_admin),
                TextColumn::make('label')
                    ->label('Nombre')
                    ->searchable(),
                TextColumn::make('key')
                    ->label('Clave')
                    ->searchable(),
                TextColumn::make('facts')
                    ->label('Datos')
                    ->state(fn (Topic $record) => count($record->facts ?? [])),
                IconColumn::make('is_active')
                    ->label('Activo')
                    ->boolean(),
                TextColumn::make('updated_at')
                    ->label('Actualizado')
                    ->dateTime()
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
