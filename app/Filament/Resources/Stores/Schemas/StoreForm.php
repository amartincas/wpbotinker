<?php

namespace App\Filament\Resources\Stores\Schemas;

use App\Factories\AIServiceFactory;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Validation\Rule;

class StoreForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->schema([
                TextInput::make('name')
                    ->required(),
                Select::make('personality_type')
                    ->options(['vendedor' => 'Vendedor', 'soporte' => 'Soporte', 'asesor' => 'Asesor'])
                    ->required(),
                Textarea::make('system_prompt')
                    ->required()
                    ->columnSpanFull(),
                Select::make('ai_provider')
                    ->options(['openai' => 'Openai', 'grok' => 'Grok', 'gemini' => 'Gemini'])
                    ->required()
                    ->rule('in:openai,grok,gemini')
                    ->reactive(),
                Select::make('ai_model')
                    ->label('Modelo de IA')
                    ->required()
                    ->rule('string')
                    ->options(function (Get $get) {
                        $models = AIServiceFactory::enabledModels($get('ai_provider'));

                        return array_combine($models, $models);
                    })
                    // Same list the AI factory accepts: checked on the server.
                    ->rule(fn (Get $get) => Rule::in(AIServiceFactory::enabledModels($get('ai_provider'))))
                    ->reactive(),
                TextInput::make('ai_api_key')
                    ->label('Clave de API de IA')
                    ->password()
                    ->revealable()
                    ->required()
                    ->rule('string')
                    ->rule('min:20')
                    ->columnSpanFull()
                    ->helperText('Clave de API del proveedor de IA elegido (cifrada). Debe tener al menos 20 caracteres.'),
                TextInput::make('wa_phone_number_id')
                    ->label('ID del número de teléfono')
                    ->required()
                    // A number routes to exactly one store (also a unique index).
                    ->unique(ignoreRecord: true)
                    ->columnSpanFull(),
                TextInput::make('wa_business_account_id')
                    ->label('ID de WABA (cuenta de negocio)')
                    ->columnSpanFull(),
                TextInput::make('wa_access_token')
                    ->label('Token de acceso')
                    ->password()
                    ->revealable()
                    ->required()
                    ->columnSpanFull()
                    ->helperText('Token de acceso de la API de WhatsApp Business, de Meta'),
                TextInput::make('wa_verify_token')
                    ->label('Token de verificación')
                    ->required()
                    ->columnSpanFull()
                    ->helperText('Token de verificación para configurar el webhook'),
                TextInput::make('wa_app_secret')
                    ->label('Secreto de la aplicación (App Secret)')
                    ->password()
                    ->revealable()
                    ->columnSpanFull()
                    ->helperText('From Meta for Developers > this store\'s App > Settings > Basic > App Secret. Required to verify incoming webhook requests are really from Meta (X-Hub-Signature-256) — without it, webhooks from this store are rejected.'),
                TextInput::make('meta_dataset_id')
                    ->label('ID del conjunto de datos de Meta')
                    ->columnSpanFull()
                    ->helperText('ID del conjunto de datos de la API de Conversiones, en Meta Events Manager. Déjalo vacío para desactivar CAPI en esta tienda.'),
                TextInput::make('meta_capi_access_token')
                    ->label('Token de acceso de la API de Conversiones')
                    ->password()
                    ->revealable()
                    ->columnSpanFull()
                    ->helperText('Token de acceso generado en Meta Events Manager para este conjunto de datos (cifrado).'),
                TextInput::make('meta_capi_currency')
                    ->label('Moneda')
                    ->placeholder('e.g. COP, USD')
                    ->required(fn (Get $get) => filled($get('meta_dataset_id')))
                    ->helperText('Código ISO de la moneda en que vende esta tienda (ej.: COP, USD). Es necesario para enviar el evento de compra; no se asume ninguna por defecto, porque cada tienda puede vender en una moneda distinta.'),
                TextInput::make('alert_phone')
                    ->label('Teléfono de alertas del administrador')
                    ->placeholder('e.g. 573001234567')
                    ->columnSpanFull()
                    ->helperText('Número de WhatsApp (con indicativo, sin «+») que recibe un mensaje cada vez que se crea un lead en esta tienda. Déjalo vacío para desactivar la alerta.'),
                Select::make('conversation_mode')
                    ->label('Modo de conversación')
                    ->options([
                        'sales' => 'Ventas (flujo comercial actual)',
                        'knowledge' => 'Conocimiento (dominios y temas)',
                    ])
                    ->default('sales')
                    ->helperText('En modo conocimiento el bot responde con los temas, productos y fuentes configurados.')
                    ->required()
                    ->columnSpanFull(),
                Textarea::make('payment_instructions')
                    ->label('Instrucciones de pago')
                    ->placeholder("Ej.:\nNequi: 300 000 0000\nDaviplata: 300 000 0000\nLlave: @mitienda")
                    ->helperText('Se envían tal cual al cliente que inicia un pago de una suscripción. Incluye cada método aceptado (Nequi, Daviplata, Llave...).')
                    ->rows(5)
                    ->maxLength(2000)
                    ->columnSpanFull(),
                Textarea::make('welcome_message')
                    ->label('Mensaje de bienvenida (opcional)')
                    ->helperText('Solo en modo conocimiento. Se envía una vez, junto con la primera respuesta, a quien escribe a esta tienda por primera vez: saludo, presentación del asistente y lo que ofrece. Vacío: sin bienvenida.')
                    ->rows(4)
                    ->maxLength(1000)
                    ->columnSpanFull(),
                Textarea::make('topic_prompt_message')
                    ->label('Pregunta cuando no se identifica el tema (opcional)')
                    ->placeholder('Ej.: ¿Qué te gustaría conocer?')
                    ->helperText('Solo en modo conocimiento, con varios productos. Si un mensaje no indica de qué tema se trata (por ejemplo, un saludo), el bot hace esta pregunta en lugar de mostrar la lista de productos. Vacío: se muestra la lista.')
                    ->rows(2)
                    ->maxLength(500)
                    ->columnSpanFull(),
                Textarea::make('topic_unavailable_message')
                    ->label('Respuesta cuando no hay información del tema (opcional)')
                    ->placeholder('Ej.: No tengo información sobre eso. ¿Qué más te gustaría conocer?')
                    ->helperText('Solo en modo conocimiento. Se envía cuando preguntan por un tema que la tienda no tiene o que sus fuentes no cubren; en ese caso la muestra gratuita no se gasta. Vacío: respuestas del sistema.')
                    ->rows(2)
                    ->maxLength(500)
                    ->columnSpanFull(),
            ]);
    }
}
