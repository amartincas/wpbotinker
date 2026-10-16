<?php

namespace App\Filament\Resources\Topics\Schemas;

use App\Models\Topic;
use App\Platform\Memory\FactTypes;
use App\Platform\Memory\PersonFacts;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class TopicForm
{
    private const TYPE_LABELS = [
        'text' => 'Texto',
        'date' => 'Fecha (día/mes/año)',
        'time' => 'Hora',
        'number' => 'Número',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Select::make('store_id')
                    ->label('Tienda')
                    ->relationship('store', 'name')
                    ->visible(fn () => (bool) Auth::user()?->is_super_admin)
                    ->disabledOn('edit')
                    ->required(),
                TextInput::make('key')
                    ->label('Clave')
                    ->helperText('Identificador interno: minúsculas, números y guion bajo, p. ej. «tarot». No se puede cambiar después.')
                    ->regex(Topic::KEY_PATTERN)
                    ->maxLength(Topic::MAX_KEY_LENGTH)
                    ->disabledOn('edit')
                    ->required(),
                TextInput::make('label')
                    ->label('Nombre')
                    ->helperText('Se muestra al cliente cuando debe elegir entre temas.')
                    ->maxLength(Topic::MAX_LABEL_LENGTH)
                    ->required(),
                TagsInput::make('vocabulary')
                    ->label('Vocabulario')
                    ->helperText('Palabras que identifican el tema en un mensaje, p. ej. «tarot», «cartas».')
                    ->columnSpanFull(),
                Textarea::make('instructions')
                    ->label('Instrucciones del tema')
                    ->helperText('Propósito del agente y reglas de respuesta. Es texto para la IA: nunca se ejecuta y no habilita herramientas. Las reglas del sistema siempre prevalecen.')
                    ->rows(10)
                    ->maxLength(Topic::MAX_INSTRUCTIONS_LENGTH)
                    ->required()
                    ->columnSpanFull(),
                Textarea::make('retrieval_instructions')
                    ->label('Guía de selección de conocimiento (opcional)')
                    ->helperText('Cómo relacionar los datos y el mensaje del cliente con los títulos (# Título) de las fuentes del tema, para cuando el mensaje solo no encuentra conocimiento. Describe qué dato del cliente apunta a qué títulos y qué hacer según lo que pregunte. Con guía, el bot no responde como si se basara en las fuentes cuando no encuentra la sección. Es texto para la IA: nunca se ejecuta.')
                    ->rows(4)
                    ->maxLength(Topic::MAX_RETRIEVAL_INSTRUCTIONS_LENGTH)
                    ->columnSpanFull(),
                Repeater::make('facts')
                    ->label('Datos que el bot puede pedir')
                    ->helperText('Cada producto del tema elige cuáles necesita. Se guardan en la memoria del cliente.')
                    ->maxItems(Topic::MAX_FACTS)
                    ->defaultItems(0)
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('scope')
                            ->label('Tipo de dato')
                            ->options(['person' => 'Dato personal (catálogo común)', 'domain' => 'Dato propio del tema'])
                            ->default('domain')
                            ->live()
                            ->required(),
                        Select::make('person_key')
                            ->label('Dato personal')
                            ->options(fn () => collect(app(PersonFacts::class)->keys())
                                ->mapWithKeys(fn (string $key) => [$key => app(PersonFacts::class)->label($key)])
                                ->all())
                            ->visible(fn (Get $get) => $get('scope') === 'person')
                            ->required(),
                        TextInput::make('key')
                            ->label('Clave')
                            ->helperText('Minúsculas, números y guion bajo, p. ej. «pregunta_principal».')
                            ->regex(Topic::KEY_PATTERN)
                            ->maxLength(Topic::MAX_KEY_LENGTH)
                            ->visible(fn (Get $get) => $get('scope') === 'domain')
                            ->required(),
                        Select::make('type')
                            ->label('Formato')
                            ->options(array_intersect_key(self::TYPE_LABELS, array_flip(FactTypes::TYPES)))
                            ->visible(fn (Get $get) => $get('scope') === 'domain')
                            ->required(),
                        TextInput::make('question')
                            ->label('Pregunta')
                            ->maxLength(Topic::MAX_QUESTION_LENGTH)
                            ->visible(fn (Get $get) => $get('scope') === 'domain')
                            ->required()
                            ->columnSpanFull(),
                        TextInput::make('label')
                            ->label('Etiqueta')
                            ->helperText('Cómo se nombra el dato ante la IA, p. ej. «pregunta del cliente».')
                            ->maxLength(Topic::MAX_LABEL_LENGTH)
                            ->visible(fn (Get $get) => $get('scope') === 'domain'),
                        Toggle::make('sensitive')
                            ->label('Sensible')
                            ->visible(fn (Get $get) => $get('scope') === 'domain'),
                    ]),
                Toggle::make('is_active')
                    ->label('Activo')
                    ->helperText('Un tema inactivo deja de responder; sus productos, fuentes y datos se conservan.')
                    ->default(true),
            ]);
    }
}
