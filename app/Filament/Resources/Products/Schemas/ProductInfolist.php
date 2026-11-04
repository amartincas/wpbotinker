<?php

namespace App\Filament\Resources\Products\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class ProductInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('store.name')
                    ->label('Tienda'),
                TextEntry::make('name'),
                TextEntry::make('description')
                    ->columnSpanFull(),
                TextEntry::make('type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'product' => 'blue',
                        'service' => 'green',
                        default => 'gray',
                    }),
                TextEntry::make('price')
                    ->money(),
                TextEntry::make('stock')
                    ->numeric()
                    ->label(function ($record) {
                        return $record?->type === 'service'
                            ? 'Disponibilidad'
                            : 'Cantidad en existencias';
                    }),
                TextEntry::make('ai_sales_strategy')
                    ->label('Estrategia de servicio de la IA')
                    ->columnSpanFull()
                    ->placeholder('Sin definir'),
                TextEntry::make('faq_context')
                    ->label('Preguntas frecuentes y contexto operativo')
                    ->columnSpanFull()
                    ->placeholder('Sin definir'),
                TextEntry::make('required_customer_info')
                    ->label('Datos requeridos del lead')
                    ->columnSpanFull()
                    ->placeholder('Sin definir'),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }
}
