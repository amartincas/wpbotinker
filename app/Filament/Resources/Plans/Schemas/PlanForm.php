<?php

namespace App\Filament\Resources\Plans\Schemas;

use App\Filament\Resources\Plans\PlanResource;
use App\Models\Plan;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class PlanForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Select::make('product_id')
                    ->label('Producto')
                    ->relationship('product', 'name', fn ($query) => $query->whereIn('products.id', PlanResource::selectableProducts()->select('id')))
                    // Checked on the server too: the options list alone
                    // does not stop a crafted request.
                    ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail) {
                        if (! PlanResource::selectableProducts()->whereKey($value)->exists()) {
                            $fail('Elige un producto con suscripción de tu tienda.');
                        }
                    })
                    ->helperText('Solo productos con acceso por suscripción.')
                    ->required(),
                TextInput::make('name')
                    ->label('Nombre')
                    ->placeholder('Ej.: Mensual')
                    ->maxLength(191)
                    ->required(),
                TextInput::make('price')
                    ->label('Precio')
                    ->numeric()
                    ->minValue(0.01)
                    ->prefix('$')
                    ->required(),
                TextInput::make('currency')
                    ->label('Moneda')
                    ->default('COP')
                    ->length(3)
                    ->alpha()
                    ->required(),
                TextInput::make('duration_days')
                    ->label('Duración (días)')
                    ->helperText('Días de acceso que concede cada pago confirmado.')
                    ->integer()
                    ->minValue(1)
                    ->maxValue(Plan::MAX_DURATION_DAYS)
                    ->default(30)
                    ->required(),
                Toggle::make('is_active')
                    ->label('Activo')
                    ->helperText('Solo los planes activos se ofrecen a los clientes.')
                    ->default(true),
                TextInput::make('sort_order')
                    ->label('Orden')
                    ->integer()
                    ->minValue(0)
                    ->default(0),
            ]);
    }
}
