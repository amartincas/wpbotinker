<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Models\Product;
use App\Platform\Domains\DomainRegistry;
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
                    ->required()
                    ->default(Auth::user()?->store_id),
                TextInput::make('id')
                    ->label('Image IDs (for AI image references)')
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
                    ->helperText('Reference one of these exact tags in system prompts or AI responses to display that specific image. Each image has its own tag — this is NOT the product ID above.')
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
                        'product' => 'Product',
                        'service' => 'Service',
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
                            ? 'Availability (1 = Accepting Clients, 0 = Fully Booked)'
                            : 'Stock Quantity';
                    })
                    ->helperText(function (Get $get): string {
                        return $get('type') === 'service'
                            ? 'Enter 1 if accepting new clients, 0 if fully booked'
                            : 'Enter the number of units in stock';
                    }),
                Textarea::make('ai_sales_strategy')
                    ->label('AI Sales Strategy')
                    ->placeholder('How should the AI sell this product? E.g., "Emphasize quality over price" or "Focus on limited availability"...')
                    ->columnSpanFull(),
                Textarea::make('faq_context')
                    ->label('FAQ & Operational Context')
                    ->placeholder('Rules, cities served, employees, FAQs specific to this product...')
                    ->columnSpanFull(),
                TextInput::make('required_customer_info')
                    ->label('Required Lead Data')
                    ->placeholder('E.g., Full name, phone, delivery address, preferred date...')
                    ->columnSpanFull(),
                TagsInput::make('meta_ad_ids')
                    ->label('IDs de anuncios de Meta (Click-to-WhatsApp)')
                    ->placeholder('Pega el ID del anuncio y presiona Enter')
                    ->helperText('Copia el ID del anuncio desde Meta Ads Manager (no el texto del mensaje). Así, cuando un cliente escriba desde ese anuncio, el sistema identifica el producto exacto sin depender del texto del mensaje.')
                    ->columnSpanFull(),

                // === SUBSCRIPTION AND FREE SAMPLE (domain products only) ===
                // The sample's content comes from these instructions (and an
                // optional registered capability), never from code.
                Section::make('Suscripción y muestra gratuita')
                    ->description(fn ($record) => "Dominio: {$record?->domain_key}")
                    ->visible(fn ($record) => filled($record?->domain_key))
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
                            ->label('Instrucciones de la muestra gratuita')
                            ->helperText('Qué ofrece la muestra y cómo responder. Se entrega una sola vez por cliente, antes de la oferta de suscripción. Sin instrucciones, el producto no ofrece muestra.')
                            ->requiredWith('sample_capability_key')
                            ->visible(fn (Get $get) => $get('access_mode') === Product::ACCESS_SUBSCRIPTION)
                            ->columnSpanFull(),
                        Select::make('sample_capability_key')
                            ->label('Capacidad de la muestra (opcional)')
                            ->helperText('Cálculo registrado del dominio que la muestra puede usar.')
                            ->options(function ($record) {
                                $registry = app(DomainRegistry::class);

                                if (! $registry->has((string) $record?->domain_key)) {
                                    return [];
                                }

                                $keys = $registry->capabilityKeys($record->domain_key);

                                return array_combine($keys, $keys);
                            })
                            ->rule(fn ($record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record) {
                                if (blank($value)) {
                                    return;
                                }

                                try {
                                    app(DomainRegistry::class)->assertProductConfiguration((string) $record?->domain_key, $record?->required_facts ?? [], $record?->capability_key, $value);
                                } catch (InvalidArgumentException $e) {
                                    $fail($e->getMessage());
                                }
                            })
                            ->visible(fn (Get $get) => $get('access_mode') === Product::ACCESS_SUBSCRIPTION),
                    ]),

                // === PRODUCT GALLERY ===
                FileUpload::make('images')
                    ->label('Product Images')
                    ->multiple()
                    ->reorderable()
                    ->directory('products')
                    ->disk('public')
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                    ->maxSize(5120) // 5MB max per image
                    ->helperText('Upload multiple images (JPG, PNG, WebP). The first image will be used as primary.')
                    ->columnSpanFull()
                    // Load existing images from the relationship
                    ->formatStateUsing(fn ($record) => $record?->images()->pluck('image_path')->toArray() ?? [])
                    ->dehydrated(false),
            ]);
    }
}
