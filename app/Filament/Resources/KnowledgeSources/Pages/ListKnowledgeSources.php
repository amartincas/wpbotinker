<?php

namespace App\Filament\Resources\KnowledgeSources\Pages;

use App\Filament\Resources\KnowledgeSources\KnowledgeSourceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListKnowledgeSources extends ListRecords
{
    protected static string $resource = KnowledgeSourceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Subir fuente'),
        ];
    }
}
