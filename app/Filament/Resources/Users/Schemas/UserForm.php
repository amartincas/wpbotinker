<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->schema([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('email')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->unique('users', 'email', ignoreRecord: true),
                TextInput::make('password')
                    ->password()
                    ->revealable()
                    ->required(fn (string $operation) => $operation === 'create')
                    ->hidden(fn (string $operation) => $operation === 'edit')
                    ->helperText('Contraseña de la cuenta del usuario'),
                Select::make('store_id')
                    ->relationship('store', 'name')
                    ->required()
                    ->label('Asignar tienda')
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->disabled(fn (string $operation) => $operation === 'edit'),
                Checkbox::make('is_super_admin')
                    ->label('Conceder acceso de superadministrador')
                    ->helperText('Los superadministradores pueden administrar todas las tiendas, usuarios, productos y leads.'),
            ]);
    }
}
