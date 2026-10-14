<?php

namespace App\Filament\Resources\KnowledgeSources\Tables;

use App\Models\KnowledgeSource;
use App\Platform\Knowledge\KnowledgeIngestor;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class KnowledgeSourcesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('store.name')
                    ->label('Tienda')
                    ->visible(Auth::user()?->is_super_admin),
                TextColumn::make('title')
                    ->label('Título')
                    ->searchable(),
                TextColumn::make('domain_key')
                    ->label('Dominio o tema'),
                TextColumn::make('product.name')
                    ->label('Producto')
                    ->placeholder('Todo el dominio'),
                TextColumn::make('version')
                    ->label('Versión'),
                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->color(fn (string $state) => $state === KnowledgeSource::STATUS_ACTIVE ? 'success' : 'gray'),
                TextColumn::make('chunks_count')
                    ->label('Fragmentos')
                    ->counts('chunks'),
                TextColumn::make('ingested_at')
                    ->label('Cargada')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('ingested_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label('Estado')
                    ->options([KnowledgeSource::STATUS_ACTIVE => 'Activa', KnowledgeSource::STATUS_RETIRED => 'Retirada']),
            ])
            ->recordActions([
                // Retired sources stop being retrieved but keep their history.
                Action::make('retire')
                    ->label('Retirar')
                    ->icon('heroicon-o-archive-box')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (KnowledgeSource $record) => $record->status === KnowledgeSource::STATUS_ACTIVE)
                    ->action(fn (KnowledgeSource $record) => app(KnowledgeIngestor::class)->retire($record)),
            ]);
    }
}
