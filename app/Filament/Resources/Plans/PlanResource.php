<?php

namespace App\Filament\Resources\Plans;

use App\Filament\Resources\Plans\Pages\CreatePlan;
use App\Filament\Resources\Plans\Pages\EditPlan;
use App\Filament\Resources\Plans\Pages\ListPlans;
use App\Filament\Resources\Plans\Schemas\PlanForm;
use App\Filament\Resources\Plans\Tables\PlansTable;
use App\Models\Plan;
use App\Models\Product;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class PlanResource extends Resource
{
    protected static ?string $model = Plan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static ?string $navigationLabel = 'Planes';

    protected static ?string $modelLabel = 'plan';

    protected static ?string $pluralModelLabel = 'planes';

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        // Superusers see all plans, regular users only those of their store
        if (! Auth::user()?->is_super_admin) {
            $query->where('store_id', Auth::user()?->store_id);
        }

        return $query;
    }

    /**
     * Products the current user may attach a plan to: subscription products
     * of their own store (any store for superusers).
     */
    public static function selectableProducts(): Builder
    {
        return Product::query()
            ->where('access_mode', Product::ACCESS_SUBSCRIPTION)
            ->when(! Auth::user()?->is_super_admin, fn (Builder $query) => $query->where('store_id', Auth::user()?->store_id));
    }

    public static function form(Schema $schema): Schema
    {
        return PlanForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PlansTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlans::route('/'),
            'create' => CreatePlan::route('/create'),
            'edit' => EditPlan::route('/{record}/edit'),
        ];
    }
}
