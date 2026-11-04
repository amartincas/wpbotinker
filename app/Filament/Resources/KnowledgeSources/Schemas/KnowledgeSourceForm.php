<?php

namespace App\Filament\Resources\KnowledgeSources\Schemas;

use App\Filament\Resources\KnowledgeSources\KnowledgeSourceResource;
use App\Models\KnowledgeSource;
use App\Models\Product;
use App\Platform\Domains\DomainRegistry;
use App\Platform\Knowledge\KnowledgeFile;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class KnowledgeSourceForm
{
    /** Where uploads wait (private disk) until they are ingested and deleted. */
    public const UPLOAD_DISK = 'local';

    public const UPLOAD_DIRECTORY = 'knowledge-uploads';

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Select::make('store_id')
                    ->label('Tienda')
                    ->relationship('store', 'name')
                    ->visible(fn () => (bool) Auth::user()?->is_super_admin)
                    ->live()
                    ->required()
                    ->validationMessages(['required' => 'Elige la tienda.', 'in' => 'Esa tienda no existe. Elige otra.', 'exists' => 'Esa tienda no existe. Elige otra.']),
                Select::make('domain_key')
                    ->label('Dominio o tema')
                    ->options(function (Get $get) {
                        $registry = app(DomainRegistry::class);
                        $storeId = KnowledgeSourceResource::storeIdFor(['store_id' => $get('store_id')]);

                        return collect($registry->keys($storeId))
                            ->mapWithKeys(fn (string $key) => [$key => $registry->get($key, $storeId)->label()])
                            ->all();
                    })
                    ->live()
                    ->required()
                    ->validationMessages(['required' => 'Elige el dominio o tema.', 'in' => 'Ese dominio o tema no está activo en esta tienda. Elige otro.']),
                Select::make('purpose')
                    ->label('Uso')
                    ->options([
                        KnowledgeSource::PURPOSE_KNOWLEDGE => 'Conocimiento del tema (respuestas y muestras)',
                        KnowledgeSource::PURPOSE_COMMERCIAL => 'Recursos comerciales (para clientes sin acceso)',
                        KnowledgeSource::PURPOSE_SAMPLE => 'Contenido de muestra (para las etapas de un embudo)',
                    ])
                    ->default(KnowledgeSource::PURPOSE_KNOWLEDGE)
                    ->helperText('Los recursos comerciales (beneficios, objeciones, testimonios, bonos…) nunca se usan para responder ni para la muestra: solo para ofrecer el producto a quien aún no tiene acceso, según sus instrucciones comerciales. El contenido de muestra lo reciben clientes sin acceso, en las etapas de un embudo que lo usen: no subas material exclusivo de suscriptores.')
                    ->live()
                    ->required()
                    ->validationMessages(['required' => 'Elige el uso de la fuente.', 'in' => 'Elige un uso de la lista.']),
                Select::make('delivery')
                    ->label('Entrega')
                    ->options([
                        KnowledgeSource::DELIVERY_REFERENCE => 'Referencia: la IA puede redactarlo, sin ir más allá de lo que dice',
                        KnowledgeSource::DELIVERY_VERBATIM => 'Literal: se envía tal cual está escrito',
                    ])
                    ->default(KnowledgeSource::DELIVERY_REFERENCE)
                    ->helperText('Usa «Literal» para testimonios o textos que no deben reescribirse. Un recurso literal no puede incluir precios, importes, monedas ni porcentajes: los precios los agrega el sistema desde los planes.')
                    ->visible(fn (Get $get) => in_array($get('purpose'), [KnowledgeSource::PURPOSE_COMMERCIAL, KnowledgeSource::PURPOSE_SAMPLE], true))
                    ->required()
                    ->validationMessages(['required' => 'Elige la forma de entrega.', 'in' => 'Elige una forma de entrega de la lista.']),
                Select::make('product_id')
                    ->label('Producto (opcional)')
                    ->helperText('Vacío: sirve a todo el dominio o tema (también a todos sus productos).')
                    ->options(fn (Get $get) => Product::where('store_id', KnowledgeSourceResource::storeIdFor(['store_id' => $get('store_id')]))
                        ->where('domain_key', (string) $get('domain_key'))
                        ->pluck('name', 'id')
                        ->all())
                    ->validationMessages(['in' => 'Ese producto no pertenece a esta tienda y tema. Elige otro.']),
                FileUpload::make('file')
                    ->label('Archivo')
                    ->helperText('Markdown, HTML o texto en UTF-8, máximo '.(KnowledgeFile::MAX_BYTES / 1024 / 1024).' MB. Usa encabezados (# Título) para separar secciones.')
                    ->disk(self::UPLOAD_DISK)
                    ->directory(self::UPLOAD_DIRECTORY)
                    ->visibility('private')
                    ->storeFileNamesIn('file_name')
                    ->maxSize((int) (KnowledgeFile::MAX_BYTES / 1024))
                    ->required()
                    ->validationMessages(['required' => 'Sube el archivo de la fuente.', 'max' => 'El archivo supera el tamaño máximo.'])
                    ->columnSpanFull(),
                TextInput::make('title')
                    ->label('Título (opcional)')
                    ->helperText('Por defecto, el nombre del archivo.')
                    ->maxLength(191)
                    ->validationMessages(['max' => 'El título no puede superar 191 caracteres.']),
                TextInput::make('origin')
                    ->label('Origen (opcional)')
                    ->helperText('Identificador estable: subir otro archivo con el mismo origen crea una nueva versión y retira la anterior. Por defecto, el nombre del archivo.')
                    ->maxLength(191)
                    ->validationMessages(['max' => 'El origen no puede superar 191 caracteres.']),
                TextInput::make('license')
                    ->label('Licencia o procedencia')
                    ->placeholder('Ej.: propio')
                    ->maxLength(191)
                    ->validationMessages(['max' => 'La licencia no puede superar 191 caracteres.']),
                TextInput::make('language')
                    ->label('Idioma')
                    ->default('es')
                    ->maxLength(10)
                    ->required()
                    ->validationMessages(['required' => 'Indica el idioma.', 'max' => 'El idioma no puede superar 10 caracteres.']),
            ]);
    }
}
