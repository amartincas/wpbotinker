<?php

namespace App\Filament\Resources\KnowledgeSources;

use App\Filament\Resources\KnowledgeSources\Pages\CreateKnowledgeSource;
use App\Filament\Resources\KnowledgeSources\Pages\ListKnowledgeSources;
use App\Filament\Resources\KnowledgeSources\Schemas\KnowledgeSourceForm;
use App\Filament\Resources\KnowledgeSources\Tables\KnowledgeSourcesTable;
use App\Models\KnowledgeSource;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Knowledge sources of a store: upload (through KnowledgeIngestor, with
 * the same file rules as the console command), list and retire. Sources
 * are never edited in place: a new upload with the same origin is a new
 * version.
 */
class KnowledgeSourceResource extends Resource
{
    protected static ?string $model = KnowledgeSource::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?string $navigationLabel = 'Fuentes de conocimiento';

    protected static ?string $modelLabel = 'fuente';

    protected static ?string $pluralModelLabel = 'fuentes de conocimiento';

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        // Superusers see every store's sources, regular users only their own
        if (! Auth::user()?->is_super_admin) {
            $query->where('store_id', Auth::user()?->store_id);
        }

        return $query;
    }

    /** The store a regular user acts for; superusers choose it in the form. */
    public static function storeIdFor(array $data): ?int
    {
        return Auth::user()?->is_super_admin ? ((int) ($data['store_id'] ?? 0) ?: null) : Auth::user()?->store_id;
    }

    public static function form(Schema $schema): Schema
    {
        return KnowledgeSourceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return KnowledgeSourcesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListKnowledgeSources::route('/'),
            'create' => CreateKnowledgeSource::route('/create'),
        ];
    }
}
