<?php

namespace App\Filament\Resources\Topics;

use App\Filament\Resources\Topics\Pages\CreateTopic;
use App\Filament\Resources\Topics\Pages\EditTopic;
use App\Filament\Resources\Topics\Pages\ListTopics;
use App\Filament\Resources\Topics\Schemas\TopicForm;
use App\Filament\Resources\Topics\Tables\TopicsTable;
use App\Models\Topic;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Topics: domains configured from the panel (instructions, vocabulary and
 * declared facts), each one private to its store.
 */
class TopicResource extends Resource
{
    protected static ?string $model = Topic::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLightBulb;

    protected static ?string $navigationLabel = 'Temas';

    protected static ?string $modelLabel = 'tema';

    protected static ?string $pluralModelLabel = 'temas';

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        // Superusers see all topics, regular users only those of their store
        if (! Auth::user()?->is_super_admin) {
            $query->where('store_id', Auth::user()?->store_id);
        }

        return $query;
    }

    /**
     * Form data → topic attributes, checked by the model's own rules so the
     * panel shows exactly what a save would reject. A regular user's topic
     * always belongs to their store.
     *
     * @throws ValidationException
     */
    public static function prepare(array $data, ?Topic $record = null): array
    {
        $user = Auth::user();

        if (! $user?->is_super_admin) {
            $data['store_id'] = $record?->store_id ?? $user?->store_id;
        }

        $data['facts'] = collect($data['facts'] ?? [])
            ->map(function (array $fact) {
                if (($fact['scope'] ?? null) === 'person') {
                    return ['scope' => 'person', 'key' => $fact['person_key'] ?? null];
                }

                unset($fact['person_key']);

                return $fact;
            })
            ->values()
            ->all();

        $data['updated_by'] = $user?->id;

        $topic = $record ? (clone $record)->fill($data) : new Topic($data);

        if ($errors = $topic->validationErrors()) {
            throw ValidationException::withMessages(collect($errors)->mapWithKeys(fn ($message, $field) => ["data.{$field}" => $message])->all());
        }

        return $data;
    }

    public static function form(Schema $schema): Schema
    {
        return TopicForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TopicsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTopics::route('/'),
            'create' => CreateTopic::route('/create'),
            'edit' => EditTopic::route('/{record}/edit'),
        ];
    }
}
