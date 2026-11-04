<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Platform\Commerce\OpeningMessage;
use App\Platform\Funnel\FunnelDefinition;
use App\Platform\Funnel\FunnelStage;
use Closure;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;

/**
 * The fields of a funnel's definition, in the shape FunnelDefinitionFormMapper
 * gives them. They validate what they can in the panel's words; each stage
 * type offers only what the engine uses of it. FunnelDefinition stays the
 * final validation.
 */
class FunnelDefinitionForm
{
    private const NEW_STAGE_KEY = '/^[a-z0-9][a-z0-9_-]*$/';

    private const INTENT_KEY = '/^[a-z][a-z0-9_]*$/';

    private const MAX_KEY_LENGTH = 100;

    private const CONDITIONS = [
        FunnelDefinition::WHEN_PHRASE => 'El mensaje es exactamente una frase',
        FunnelDefinition::WHEN_PLAN_NAMED => 'El cliente elige un plan por su nombre',
        FunnelDefinition::WHEN_INTERACTIONS => 'Se alcanza un número de interacciones',
        FunnelDefinition::WHEN_FREE_EXHAUSTED => 'Se agotaron las respuestas gratuitas',
        FunnelDefinition::WHEN_INTENT => 'La IA reconoce una intención',
        FunnelDefinition::WHEN_ANY_REPLY => 'Después de cualquier respuesta',
    ];

    private const ACTIONS = [
        FunnelDefinition::ACTION_GO_TO => 'Ir a una etapa',
        FunnelDefinition::ACTION_SHOW_OFFER => 'Mostrar la oferta',
        FunnelDefinition::ACTION_START_PAYMENT => 'Iniciar el pago',
        FunnelDefinition::ACTION_STAY => 'Permanecer en la etapa',
    ];

    /**
     * @param  list<string>  $existingKeys  the stages of the version it starts from: their conversations depend on those keys
     * @param  int  $activeSampleSources  what a stage choosing sample content reads
     * @return list<Section>
     */
    public static function components(array $existingKeys, int $activeSampleSources = 0): array
    {
        return [self::general(), self::freeReplies(), self::intents(), self::stages($existingKeys, $activeSampleSources)];
    }

    private static function general(): Section
    {
        return Section::make('Información general')
            ->columns(2)
            ->schema([
                TextInput::make('name')
                    ->label('Nombre')
                    ->required()
                    ->validationMessages(['required' => 'El embudo necesita un nombre.']),
                Select::make('start_stage')
                    ->label('Etapa inicial')
                    ->helperText('Sin elegir, empieza en la primera etapa de la lista.')
                    ->options(fn (Get $get) => self::stageOptions($get('stages')))
                    ->validationMessages(['in' => 'La etapa inicial elegida ya no existe. Elige otra o déjala vacía.']),
                Textarea::make('goal')
                    ->label('Objetivo')
                    ->rows(2)
                    ->required()
                    ->validationMessages(['required' => 'El embudo necesita un objetivo.'])
                    ->columnSpanFull(),
                Textarea::make('offer_cta')
                    ->label('Llamado a la acción de la oferta')
                    ->rows(2)
                    ->maxLength(FunnelDefinition::MAX_TEXT_LENGTH)
                    ->validationMessages(['max' => 'El llamado a la acción es demasiado largo.'])
                    ->columnSpanFull(),
            ]);
    }

    private static function freeReplies(): Section
    {
        return Section::make('Respuestas gratuitas')
            ->columns(2)
            ->schema([
                self::wholeNumber('free_replies.limit')
                    ->label('Cantidad')
                    ->helperText('Sin indicar, este embudo no da respuestas gratuitas.')
                    ->minValue(0)
                    ->maxValue(255)
                    ->live(onBlur: true)
                    ->validationMessages([
                        'numeric' => 'Indica un número entero.',
                        'min' => 'Indica un número de 0 a 255.',
                        'max' => 'Indica un número de 0 a 255.',
                    ]),
                Select::make('free_replies.on_exhausted')
                    ->label('Al agotarse, pasar a la etapa')
                    ->options(fn (Get $get) => self::stageOptions($get('stages')))
                    ->validationMessages(['in' => 'La etapa elegida para cuando se agoten las respuestas gratuitas ya no existe. Elige otra o déjala vacía.']),
                Toggle::make('opening_consumes_free_reply')
                    ->label('El mensaje inicial gasta una respuesta gratuita')
                    ->validationMessages(['boolean' => 'Indica si el mensaje inicial gasta una respuesta gratuita.'])
                    ->columnSpanFull(),
            ]);
    }

    private static function intents(): Section
    {
        return Section::make('Intenciones')
            ->description('Lo que la IA puede reconocer en un mensaje del cliente.')
            ->schema([
                Repeater::make('intents')
                    ->hiddenLabel()
                    ->defaultItems(0)
                    ->addActionLabel('Agregar intención')
                    ->columns(2)
                    ->schema([
                        TextInput::make('key')
                            ->label('Clave')
                            ->helperText('Letras minúsculas, números y guion bajo; empieza con una letra.')
                            ->required()
                            ->regex(self::INTENT_KEY)
                            ->distinct()
                            ->live(onBlur: true)
                            ->validationMessages([
                                'required' => 'La intención necesita una clave.',
                                'regex' => 'Usa letras minúsculas, números y guion bajo, empezando con una letra.',
                                'distinct' => 'Esta clave de intención ya existe.',
                            ]),
                        TextInput::make('description')
                            ->label('Qué significa')
                            ->required()
                            ->validationMessages(['required' => 'La intención necesita una descripción.']),
                    ]),
            ]);
    }

    /**
     * @param  list<string>  $existingKeys
     */
    private static function stages(array $existingKeys, int $activeSampleSources): Section
    {
        return Section::make('Etapas')
            ->schema([
                Repeater::make('stages')
                    ->hiddenLabel()
                    ->required()
                    ->minItems(1)
                    ->defaultItems(0)
                    ->addActionLabel('Agregar etapa')
                    ->collapsible()
                    ->itemLabel(fn (array $state) => $state['key'] ?? 'Etapa nueva')
                    ->validationMessages([
                        'required' => 'El embudo necesita al menos una etapa.',
                        'min' => 'El embudo necesita al menos una etapa.',
                    ])
                    ->columns(2)
                    ->schema(self::stageFields($existingKeys, $activeSampleSources)),
            ]);
    }

    /**
     * @param  list<string>  $existingKeys
     * @return list<mixed>
     */
    private static function stageFields(array $existingKeys, int $activeSampleSources): array
    {
        $isMessage = fn (Get $get) => $get('type') === FunnelStage::TYPE_MESSAGE;
        $isAi = fn (Get $get) => $get('type') === FunnelStage::TYPE_AI;

        return [
            // Marks a stage of the version it starts from, whose key cannot change.
            Hidden::make('is_existing')->dehydrated(false),

            TextInput::make('key')
                ->label('Clave')
                ->helperText(fn (Get $get) => $get('is_existing') ? 'Las conversaciones dependen de esta clave: no se puede cambiar.' : 'Letras minúsculas, números, guion y guion bajo. Después de publicar no se podrá cambiar.')
                ->required()
                ->maxLength(self::MAX_KEY_LENGTH)
                ->readOnly(fn (Get $get) => (bool) $get('is_existing'))
                ->regex(fn (Get $get) => $get('is_existing') ? null : self::NEW_STAGE_KEY)
                ->distinct()
                ->live(onBlur: true)
                ->rules([self::keepsExistingKey($existingKeys)])
                ->validationMessages([
                    'required' => 'La etapa necesita una clave.',
                    'max' => 'La clave de la etapa es demasiado larga.',
                    'regex' => 'Usa letras minúsculas, números, guion y guion bajo.',
                    'distinct' => 'Esta clave de etapa ya existe.',
                ]),
            Select::make('type')
                ->label('Tipo')
                ->options([
                    FunnelStage::TYPE_AI => 'IA: conversa hacia un objetivo',
                    FunnelStage::TYPE_MESSAGE => 'Mensaje: se envía tal como está escrito',
                ])
                ->default(FunnelStage::TYPE_AI)
                ->selectablePlaceholder(false)
                ->required()
                ->live()
                ->validationMessages(['required' => 'Elige el tipo de la etapa.', 'in' => 'Elige un tipo de etapa válido: IA o mensaje.']),
            Textarea::make('text')
                ->label('Texto')
                ->helperText('Puedes usar {producto} y {planes}.')
                ->rows(4)
                ->required()
                ->maxLength(FunnelDefinition::MAX_TEXT_LENGTH)
                ->visible($isMessage)
                ->validationMessages(['required' => 'El mensaje necesita su texto.', 'max' => 'El texto es demasiado largo.'])
                ->columnSpanFull(),
            Select::make('next')
                ->label('Etapa siguiente')
                ->options(fn (Get $get) => array_diff_key(self::stageOptions($get('../../stages')), [(string) $get('key') => true]))
                ->required()
                ->visible($isMessage)
                ->validationMessages(['required' => 'El mensaje necesita la etapa que le sigue.', 'in' => 'La etapa siguiente elegida ya no existe. Elige otra.']),
            Textarea::make('goal')
                ->label('Objetivo')
                ->rows(2)
                ->required()
                ->visible($isAi)
                ->validationMessages(['required' => 'La etapa necesita un objetivo.'])
                ->columnSpanFull(),
            Textarea::make('instructions')
                ->label('Instrucciones para la IA')
                ->rows(4)
                ->required()
                ->visible($isAi)
                ->validationMessages(['required' => 'La etapa necesita instrucciones.'])
                ->columnSpanFull(),
            Textarea::make('restrictions')
                ->label('Restricciones')
                ->rows(2)
                ->visible($isAi)
                ->columnSpanFull(),
            CheckboxList::make('context')
                ->label('Contexto que puede usar')
                ->options(FunnelVersionInfolist::CONTEXT_LABELS)
                ->helperText("«Contenido de muestra» usa todas las fuentes de muestra activas de este producto y de su tema ({$activeSampleSources} ahora). Se administran en Fuentes de conocimiento.")
                ->validationMessages(['array' => 'Elige un contexto de la lista.', '*.in' => 'Elige un contexto de la lista.'])
                ->visible($isAi)
                ->rules([fn () => function (string $attribute, mixed $value, Closure $fail) {
                    if (array_diff((array) $value, FunnelDefinition::CONTEXTS) !== []) {
                        $fail('Elige un contexto de la lista.');
                    }
                }])
                ->columnSpanFull(),
            self::wholeNumber('max_interactions')
                ->label('Máximo de interacciones')
                ->minValue(1)
                ->live(onBlur: true)
                ->visible($isAi)
                ->validationMessages(['numeric' => 'Indica un número entero.', 'min' => 'Indica un número de 1 en adelante.']),
            Select::make('on_limit')
                ->label('Al alcanzar el máximo, pasar a la etapa')
                ->options(fn (Get $get) => self::stageOptions($get('../../stages')))
                ->required(fn (Get $get) => filled($get('max_interactions')))
                ->visible($isAi)
                ->validationMessages(['required' => 'Con un máximo de interacciones, elige a qué etapa pasar.', 'in' => 'La etapa elegida para cuando se alcance el máximo ya no existe. Elige otra.']),
            Toggle::make('uses_free_replies')
                ->label('Gasta respuestas gratuitas')
                ->rules([self::needsFreeReplies()])
                ->validationMessages(['boolean' => 'Indica si la etapa gasta respuestas gratuitas.'])
                ->columnSpanFull(),
            Repeater::make('rules')
                ->label('Reglas')
                ->helperText('Se evalúan en este orden. Las que no necesitan a la IA se revisan antes de responder; una intención o «cualquier respuesta», después.')
                ->visible($isAi)
                ->defaultItems(0)
                ->addActionLabel('Agregar regla')
                ->collapsible()
                ->itemLabel(fn (array $state) => self::CONDITIONS[$state['condition'] ?? null] ?? 'Regla nueva')
                ->columns(2)
                ->columnSpanFull()
                ->schema(self::ruleFields()),
        ];
    }

    /**
     * A rule's fields, in the shape FunnelDefinitionFormMapper gives them:
     * each condition asks for its own value, and each action for its stage.
     *
     * @return list<mixed>
     */
    private static function ruleFields(): array
    {
        $when = fn (string $condition) => fn (Get $get) => $get('condition') === $condition;
        $unknownOption = ['in' => 'Ya no existe: elige otra opción.'];

        return [
            Select::make('condition')
                ->label('Cuando')
                ->options(self::CONDITIONS)
                ->required()
                ->live()
                ->validationMessages(['required' => 'Elige la condición de la regla.', ...$unknownOption]),
            Select::make('action')
                ->label('Entonces')
                ->options(self::ACTIONS)
                ->required()
                ->live()
                ->validationMessages(['required' => 'Elige la acción de la regla.', ...$unknownOption]),
            TagsInput::make('phrases')
                ->label('Frases')
                ->helperText('Se compara el mensaje completo, sin tildes ni mayúsculas y sin saludos al inicio o al final.')
                ->required()
                ->rules([self::phrasesSaySomething()])
                ->visible($when(FunnelDefinition::WHEN_PHRASE))
                ->validationMessages(['required' => 'Escribe al menos una frase.'])
                ->columnSpanFull(),
            Select::make('intent')
                ->label('Intención')
                ->options(fn (Get $get) => self::intentOptions($get('../../../../intents')))
                ->required()
                ->visible($when(FunnelDefinition::WHEN_INTENT))
                ->validationMessages(['required' => 'Elige una intención declarada en este embudo.', ...$unknownOption]),
            TextInput::make('min_confidence')
                ->label('Confianza mínima')
                ->helperText('De 0 a 1. Sin indicar: '.FunnelDefinition::DEFAULT_MIN_CONFIDENCE.'.')
                ->numeric()
                ->minValue(0)
                ->maxValue(1)
                ->step(0.01)
                ->visible($when(FunnelDefinition::WHEN_INTENT))
                ->validationMessages([
                    'numeric' => 'Indica un número de 0 a 1.',
                    'min' => 'Indica un número de 0 a 1.',
                    'max' => 'Indica un número de 0 a 1.',
                ]),
            self::wholeNumber('interactions')
                ->label('Interacciones')
                ->minValue(1)
                ->required()
                ->visible($when(FunnelDefinition::WHEN_INTERACTIONS))
                ->validationMessages([
                    'required' => 'Indica cuántas interacciones.',
                    'numeric' => 'Indica un número entero.',
                    'min' => 'Indica un número de 1 en adelante.',
                ]),
            Select::make('stage')
                ->label(fn (Get $get) => $get('action') === FunnelDefinition::ACTION_GO_TO ? 'Etapa de destino' : 'Seguir en la etapa (opcional)')
                ->options(fn (Get $get) => self::stageOptions($get('../../../../stages')))
                ->required(fn (Get $get) => $get('action') === FunnelDefinition::ACTION_GO_TO)
                ->visible(fn (Get $get) => filled($get('action')) && $get('action') !== FunnelDefinition::ACTION_STAY)
                ->validationMessages(['required' => 'Elige la etapa de destino.', ...$unknownOption]),
        ];
    }

    /**
     * A field for a whole number, written as 2 or 2.0 alike: the mapper turns
     * both into the integer the contract needs, and 2.5 is never one.
     */
    private static function wholeNumber(string $name): TextInput
    {
        return TextInput::make($name)
            ->numeric()
            ->step(1)
            ->inputMode('numeric')
            ->rules([fn () => function (string $attribute, mixed $value, Closure $fail) {
                if (is_numeric($value) && floor((float) $value) !== (float) $value) {
                    $fail('Indica un número entero.');
                }
            }]);
    }

    /** The contract refuses a phrase left with nothing to compare. */
    private static function phrasesSaySomething(): Closure
    {
        return fn () => function (string $attribute, mixed $value, Closure $fail) {
            foreach ((array) $value as $phrase) {
                if (OpeningMessage::normalize((string) $phrase) === '') {
                    $fail("La frase «{$phrase}» no dice nada que comparar.");
                }
            }
        };
    }

    /**
     * @param  list<string>  $existingKeys
     */
    private static function keepsExistingKey(array $existingKeys): Closure
    {
        return fn (Get $get) => function (string $attribute, mixed $value, Closure $fail) use ($get, $existingKeys) {
            if ($get('is_existing') && ! in_array($value, $existingKeys, true)) {
                $fail('La clave de una etapa existente no se puede cambiar.');
            }
        };
    }

    private static function needsFreeReplies(): Closure
    {
        return fn (Get $get) => function (string $attribute, mixed $value, Closure $fail) use ($get) {
            if ($value && (int) $get('../../free_replies.limit') === 0) {
                $fail('Esta etapa gasta respuestas gratuitas, pero el embudo no da ninguna.');
            }
        };
    }

    /**
     * @return array<string, string> the intents declared so far, as options
     */
    private static function intentOptions(mixed $intents): array
    {
        $keys = array_filter(array_column(is_array($intents) ? $intents : [], 'key'), fn (mixed $key) => is_string($key) && trim($key) !== '');

        return array_combine($keys, $keys);
    }

    /**
     * @return array<string, string> the keys written so far, as options
     */
    private static function stageOptions(mixed $stages): array
    {
        $keys = array_filter(array_column(is_array($stages) ? $stages : [], 'key'), fn (mixed $key) => is_string($key) && trim($key) !== '');

        return array_combine($keys, $keys);
    }
}
