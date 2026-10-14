<?php

namespace App\Filament\Resources\Topics\Pages;

use App\Filament\Resources\Topics\TopicResource;
use Filament\Resources\Pages\EditRecord;

class EditTopic extends EditRecord
{
    protected static string $resource = TopicResource::class;

    /** Person facts are stored as {scope, key}; the form shows them as person_key. */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['facts'] = collect($data['facts'] ?? [])
            ->map(fn (array $fact) => ($fact['scope'] ?? null) === 'person' ? ['scope' => 'person', 'person_key' => $fact['key'] ?? null] : $fact)
            ->all();

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return TopicResource::prepare($data, $this->record);
    }
}
