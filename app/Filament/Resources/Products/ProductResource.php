<?php

namespace App\Filament\Resources\Products;

use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\EditGuidedFunnel;
use App\Filament\Resources\Products\Pages\EditProductFunnel;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\Pages\ManageProductFunnel;
use App\Filament\Resources\Products\Pages\ViewFunnelVersion;
use App\Filament\Resources\Products\Pages\ViewProduct;
use App\Filament\Resources\Products\Schemas\ProductForm;
use App\Filament\Resources\Products\Schemas\ProductInfolist;
use App\Filament\Resources\Products\Tables\ProductsTable;
use App\Models\Product;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static ?string $navigationLabel = 'Productos';

    protected static ?string $modelLabel = 'producto';

    protected static ?string $pluralModelLabel = 'productos';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        // Superusers see all products, regular users only see products from their store
        if (!Auth::user()?->is_super_admin) {
            $query->where('store_id', Auth::user()?->store_id);
        }

        return $query;
    }

    public static function table(Table $table): Table
    {
        return ProductsTable::configure($table);
    }

    public static function form(\Filament\Schemas\Schema $schema): \Filament\Schemas\Schema
    {
        return ProductForm::configure($schema);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProducts::route('/'),
            'create' => CreateProduct::route('/create'),
            'view' => ViewProduct::route('/{record}'),
            'edit' => EditProduct::route('/{record}/edit'),
            'funnel' => ManageProductFunnel::route('/{record}/funnel'),
            // Before the version's route, which would take "edit" as a version.
            'funnel-edit' => EditProductFunnel::route('/{record}/funnel/edit'),
            'funnel-guided' => EditGuidedFunnel::route('/{record}/funnel/instruction'),
            'funnel-version' => ViewFunnelVersion::route('/{record}/funnel/{version}'),
        ];
    }
}
