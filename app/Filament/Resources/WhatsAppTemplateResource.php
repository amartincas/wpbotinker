<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WhatsAppTemplate\Pages\CreateWhatsAppTemplate;
use App\Filament\Resources\WhatsAppTemplate\Pages\EditWhatsAppTemplate;
use App\Filament\Resources\WhatsAppTemplate\Pages\ListWhatsAppTemplates;
use App\Filament\Resources\WhatsAppTemplate\Schemas\WhatsAppTemplateForm;
use App\Filament\Resources\WhatsAppTemplate\Tables\WhatsAppTemplatesTable;
use App\Models\WhatsAppTemplate;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class WhatsAppTemplateResource extends Resource
{
    protected static ?string $model = WhatsAppTemplate::class;

    protected static ?string $modelLabel = 'plantilla de WhatsApp';

    protected static ?string $pluralModelLabel = 'plantillas de WhatsApp';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::DocumentDuplicate;

    protected static ?string $navigationLabel = 'Plantillas WA';

    protected static string|\UnitEnum|null $navigationGroup = 'WhatsApp';

    protected static ?int $navigationSort = 2;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (! Auth::user()?->is_super_admin) {
            $query->where('store_id', Auth::user()?->store_id);
        }

        return $query;
    }

    public static function form(Schema $schema): Schema
    {
        return WhatsAppTemplateForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WhatsAppTemplatesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListWhatsAppTemplates::route('/'),
            'create' => CreateWhatsAppTemplate::route('/create'),
            'edit'   => EditWhatsAppTemplate::route('/{record}/edit'),
        ];
    }
}
