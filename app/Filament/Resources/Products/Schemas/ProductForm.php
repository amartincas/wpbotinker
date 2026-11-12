<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Models\Product;
use App\Platform\Commerce\SampleLedger;
use App\Platform\Contracts\DomainModule;
use App\Platform\Domains\DomainRegistry;
use App\Platform\Memory\FactDefinition;
use App\Platform\Memory\PersonFacts;
use Closure;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Select::make('store_id')
                    ->relationship(
                        'store',
                        'name',
                        fn ($query) => $query
                            ->when(
                                !Auth::user()?->is_super_admin,
                                fn ($q) => $q->where('id', Auth::user()?->store_id)
                            )
                    )
                    // Checked on the server too: the options list alone does
                    // not stop a crafted request.
                    ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail) {
                        if (! Auth::user()?->is_super_admin && (int) $value !== (int) Auth::user()?->store_id) {
                            $fail('Solo puedes crear productos en tu tienda.');
                        }
                    })
                    ->live()
                    ->required()
                    ->default(Auth::user()?->store_id),
                TextInput::make('id')
                    ->label('ID de imágenes (para que la IA las referencie)')
                    ->disabled()
                    ->dehydrated(false)
                    ->formatStateUsing(function ($record) {
                        if (!$record?->id) {
                            return '(image IDs will be assigned after you upload and save)';
                        }

                        $imageIds = $record->images()->pluck('id');

                        if ($imageIds->isEmpty()) {
                            return 'No images uploaded yet.';
                        }

                        // [IMG:id] is matched against the product_images
                        // table's own id — NOT this product's id — so each
                        // uploaded image gets its own distinct tag here.
                        return $imageIds->map(fn ($id) => "[IMG:{$id}]")->implode(', ');
                    })
                    ->helperText('Usa una de estas etiquetas exactas en el prompt del sistema o en las respuestas de la IA para mostrar esa imagen. Cada imagen tiene su etiqueta; no es el ID del producto indicado arriba.')
                    ->columnSpanFull(),
                TextInput::make('name')
                    ->required(),
                Textarea::make('description')
                    ->required()
                    ->columnSpanFull(),
                TextInput::make('price')
                    ->required()
                    ->numeric()
                    ->prefix('$'),
                ToggleButtons::make('type')
                    ->options([
                        'product' => 'Producto',
                        'service' => 'Servicio',
                    ])
                    ->default('product')
                    ->required()
                    ->inline(),
                TextInput::make('stock')
                    ->required()
                    ->numeric()
                    ->default(0)
                    ->label(function (Get $get): string {
                        return $get('type') === 'service'
                            ? 'Disponibilidad (1 = recibe clientes, 0 = sin cupo)'
                            : 'Cantidad en existencias';
                    })
                    ->helperText(function (Get $get): string {
                        return $get('type') === 'service'
                            ? 'Escribe 1 si recibe clientes nuevos, 0 si no tiene cupo'
                            : 'Escribe el número de unidades en existencias';
                    }),
                Textarea::make('ai_sales_strategy')
                    ->label('Estrategia de servicio de la IA')
                    ->placeholder('¿Cómo debe atender la IA este producto? Ej.: «Destaca la calidad sobre el precio» o «Enfócate en la disponibilidad limitada»...')
                    ->columnSpanFull(),
                Textarea::make('faq_context')
                    ->label('Preguntas frecuentes y contexto operativo')
                    ->placeholder('Reglas, ciudades atendidas, empleados, preguntas frecuentes de este producto...')
                    ->columnSpanFull(),
                TextInput::make('required_customer_info')
                    ->label('Datos requeridos del lead')
                    ->placeholder('Ej.: nombre completo, teléfono, dirección de entrega, fecha preferida...')
                    ->columnSpanFull(),
                TagsInput::make('meta_ad_ids')
                    ->label('IDs de anuncios de Meta (Click-to-WhatsApp)')
                    ->placeholder('Pega el ID del anuncio y presiona Enter')
                    ->helperText('Copia el ID del anuncio desde Meta Ads Manager (no el texto del mensaje). Así, cuando un cliente escriba desde ese anuncio, el sistema identifica el producto exacto sin depender del texto del mensaje.')
                    ->columnSpanFull(),

                // === DOMAIN OR TOPIC (stores in knowledge mode) ===
                // A product joins a PHP domain module or one of the store's
                // topics. Facts and capabilities are chosen from what that
                // domain declares and checked again on the server.
                Section::make('Dominio o tema')
                    ->description('Solo para tiendas en modo conocimiento. Déjalo vacío para un producto del flujo de ventas.')
                    ->columnSpanFull()
                    ->schema([
                        Select::make('domain_key')
                            ->label('Dominio o tema')
                            ->options(fn (Get $get) => self::domainOptions($get))
                            ->rule(fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get) {
                                if (filled($value) && ! array_key_exists($value, self::domainOptions($get))) {
                                    $fail('Elige un dominio o un tema activo de esta tienda.');
                                }
                            })
                            ->live(),
                        Select::make('required_facts')
                            ->label('Datos que necesita')
                            ->helperText('El bot los pide antes de responder, en este orden.')
                            ->multiple()
                            ->options(fn (Get $get) => self::factOptions($get))
                            ->rule(fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get) {
                                if ($error = self::configurationError($get, requiredFacts: (array) $value)) {
                                    $fail($error);
                                }
                            })
                            ->visible(fn (Get $get) => filled($get('domain_key'))),
                        Select::make('capability_key')
                            ->label('Capacidad determinista (opcional)')
                            ->helperText('Solo si el producto necesita un cálculo exacto registrado en el sistema. Un producto basado en instrucciones y conocimiento no la necesita.')
                            ->options(fn (Get $get) => self::capabilityOptions($get))
                            ->rule(fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get) {
                                if (filled($value) && ($error = self::configurationError($get, capabilityKey: $value))) {
                                    $fail($error);
                                }
                            })
                            ->visible(fn (Get $get) => filled($get('domain_key'))),
                        TagsInput::make('aliases')
                            ->label('Otros nombres')
                            ->helperText('Formas en que los clientes nombran este producto.')
                            ->visible(fn (Get $get) => filled($get('domain_key'))),
                    ]),

                // === SUBSCRIPTION AND FREE SAMPLE (domain products only) ===
                // The sample's content comes from these instructions (and an
                // optional registered capability), never from code.
                Section::make('Suscripción y muestra gratuita')
                    ->visible(fn (Get $get) => filled($get('domain_key')))
                    ->columnSpanFull()
                    ->schema([
                        ToggleButtons::make('access_mode')
                            ->label('Acceso')
                            ->options([
                                Product::ACCESS_OPEN => 'Libre',
                                Product::ACCESS_SUBSCRIPTION => 'Con suscripción',
                            ])
                            ->default(Product::ACCESS_OPEN)
                            ->inline()
                            ->live()
                            ->required(),
                        Textarea::make('sample_instructions')
                            ->label('Instrucciones de las respuestas gratuitas (opcional)')
                            ->helperText('Todo producto con suscripción da a cada cliente las respuestas gratuitas de la plataforma (hoy '.SampleLedger::limit().') antes de la oferta; la estrategia de cada una es global. Aquí solo se adaptan al tema: qué ofrecer gratis y qué reservar para el servicio completo. Vacío: se usan las instrucciones del servicio.')
                            ->requiredWith('sample_capability_key')
                            ->visible(fn (Get $get) => $get('access_mode') === Product::ACCESS_SUBSCRIPTION)
                            ->columnSpanFull(),
                        Select::make('sample_capability_key')
                            ->label('Capacidad de la muestra (opcional)')
                            ->helperText('Cálculo registrado del dominio que la muestra puede usar.')
                            ->options(fn (Get $get) => self::capabilityOptions($get))
                            ->rule(fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get) {
                                if (filled($value) && ($error = self::configurationError($get, sampleCapabilityKey: $value))) {
                                    $fail($error);
                                }
                            })
                            ->visible(fn (Get $get) => $get('access_mode') === Product::ACCESS_SUBSCRIPTION),
                        Textarea::make('sample_closing_message')
                            ->label('Cierre de la muestra (opcional)')
                            ->helperText('Texto que sigue a la última respuesta gratuita, antes de los planes y precios (que se agregan solos): los beneficios reales del servicio completo. Vacío: solo los planes.')
                            ->rows(3)
                            ->maxLength(1000)
                            ->visible(fn (Get $get) => $get('access_mode') === Product::ACCESS_SUBSCRIPTION)
                            ->columnSpanFull(),
                        Textarea::make('opening_message')
                            ->label('Mensaje inicial (opcional)')
                            ->helperText('Se envía exactamente como está escrito, en lugar del saludo de la tienda, cuando el primer mensaje del cliente es uno de los activadores. Con un embudo activo puede ser la presentación comercial completa: escribe [Planes] donde deben ir los planes y el sistema inserta los oficiales, con sus precios; no escribas precios a mano. Sin embudo cuenta como la primera respuesta gratuita. Vacío: sin mensaje inicial.')
                            ->rows(5)
                            // With its plans and their buttons' text it stays within a WhatsApp message (4096 characters).
                            ->maxLength(3500)
                            ->requiredWith('opening_triggers')
                            ->visible(fn (Get $get) => $get('access_mode') === Product::ACCESS_SUBSCRIPTION)
                            ->columnSpanFull(),
                        TagsInput::make('opening_triggers')
                            ->label('Activadores del mensaje inicial')
                            ->helperText('Mensajes que lo activan, por ejemplo el mensaje precargado del anuncio. Se comparan sin tildes, mayúsculas ni signos.')
                            ->visible(fn (Get $get) => $get('access_mode') === Product::ACCESS_SUBSCRIPTION)
                            ->columnSpanFull(),
                        Textarea::make('commercial_instructions')
                            ->label('Instrucciones comerciales (opcional)')
                            ->helperText('Cómo ofrecer este producto a quien aún no tiene acceso: qué recurso comercial usar según lo que diga el cliente y cómo responder. Los recursos se cargan como fuentes de uso «Recursos comerciales». Sin instrucciones o sin recursos, se responde como hasta ahora. Precios y planes los agrega siempre el sistema.')
                            ->rows(5)
                            ->maxLength(4000)
                            ->visible(fn (Get $get) => $get('access_mode') === Product::ACCESS_SUBSCRIPTION)
                            ->columnSpanFull(),
                        Textarea::make('activation_message')
                            ->label('Mensaje de activación (opcional)')
                            ->helperText('Se agrega a la confirmación del pago cuando se envía como texto: cómo usar el servicio, ejemplos de consultas y una invitación a empezar. No se envía cuando la confirmación sale por plantilla de Meta.')
                            ->rows(5)
                            ->maxLength(2000)
                            ->visible(fn (Get $get) => $get('access_mode') === Product::ACCESS_SUBSCRIPTION)
                            ->columnSpanFull(),
                    ]),

                // === PRODUCT GALLERY ===
                FileUpload::make('images')
                    ->label('Imágenes del producto')
                    ->multiple()
                    ->reorderable()
                    ->directory('products')
                    ->disk('public')
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                    ->maxSize(5120) // 5MB max per image
                    ->helperText('Sube varias imágenes (JPG, PNG, WebP). La primera se usará como principal.')
                    ->columnSpanFull()
                    // Load existing images from the relationship
                    ->formatStateUsing(fn ($record) => $record?->images()->pluck('image_path')->toArray() ?? [])
                    ->dehydrated(false),
            ]);
    }

    /**
     * Domains the selected store can use: PHP modules and its active topics.
     *
     * @return array<string, string> key => label
     */
    private static function domainOptions(Get $get): array
    {
        $registry = app(DomainRegistry::class);
        $storeId = (int) $get('store_id') ?: null;
        $options = [];

        foreach ($registry->keys($storeId) as $key) {
            $options[$key] = $registry->get($key, $storeId)->label().($registry->isModule($key) ? '' : ' (tema)');
        }

        return $options;
    }

    /**
     * Facts the selected domain declares.
     *
     * @return array<string, string> key => label
     */
    private static function factOptions(Get $get): array
    {
        $domain = self::selectedDomain($get);

        if ($domain === null) {
            return [];
        }

        return collect($domain->facts())->mapWithKeys(fn (FactDefinition $fact) => [
            $fact->key => $fact->isPerson() ? app(PersonFacts::class)->label($fact->key) : ($fact->label ?? $fact->key),
        ])->all();
    }

    /**
     * Capabilities registered for the selected domain (none for topics).
     *
     * @return array<string, string>
     */
    private static function capabilityOptions(Get $get): array
    {
        if (self::selectedDomain($get) === null) {
            return [];
        }

        $keys = app(DomainRegistry::class)->capabilityKeys((string) $get('domain_key'), (int) $get('store_id') ?: null);

        return array_combine($keys, $keys);
    }

    private static function selectedDomain(Get $get): ?DomainModule
    {
        $registry = app(DomainRegistry::class);
        $storeId = (int) $get('store_id') ?: null;
        $key = (string) $get('domain_key');

        return $key !== '' && $registry->has($key, $storeId) ? $registry->get($key, $storeId) : null;
    }

    /**
     * Why the form's domain configuration would be rejected on save (the
     * same check as the model), with one value taken from the field being
     * validated; null when it is valid or there is no domain to check.
     */
    private static function configurationError(Get $get, ?array $requiredFacts = null, ?string $capabilityKey = null, ?string $sampleCapabilityKey = null): ?string
    {
        if (self::selectedDomain($get) === null) {
            return null;
        }

        $sample = $get('access_mode') === Product::ACCESS_SUBSCRIPTION ? $get('sample_capability_key') : null;

        try {
            app(DomainRegistry::class)->assertProductConfiguration(
                (string) $get('domain_key'),
                $requiredFacts ?? (array) $get('required_facts'),
                $capabilityKey ?? ($get('capability_key') ?: null),
                $sampleCapabilityKey ?? ($sample ?: null),
                (int) $get('store_id') ?: null,
            );
        } catch (InvalidArgumentException $e) {
            return $e->getMessage();
        }

        return null;
    }
}
