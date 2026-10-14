<?php

namespace App\Filament\Resources\Topics\Pages;

use App\Filament\Resources\Topics\TopicResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTopic extends CreateRecord
{
    protected static string $resource = TopicResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return TopicResource::prepare($data);
    }
}
