<?php

namespace App\Filament\Resources\Stores\Schemas;

use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use App\Factories\AIServiceFactory;
use Illuminate\Validation\Rule;

class StoreWizardForm
{
    // Persona templates for system prompts
    private static array $personaTemplates = [
        'vendedor' => 'You are a friendly sales assistant for a WhatsApp Bot Store. Your role is to help customers find the perfect solution for their business needs. Be helpful, positive, and focused on selling products that solve their problems.',
        'soporte' => 'You are a professional customer support agent. Your role is to resolve customer issues quickly and efficiently. Be empathetic, thorough, and always aim to exceed expectations.',
        'asesor' => 'You are a helpful business advisor. Your role is to provide guidance and recommendations to help clients make informed decisions. Be knowledgeable, friendly, and focus on understanding their needs.',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                // === WEBHOOK CONFIGURATION ===
                TextInput::make('wa_verify_token')
                    ->label('Token de verificación')
                    ->helperText('Es el token de verificación de tu webhook. Úsalo en Meta Developers Console > Webhook Settings.')
                    ->reactive()
                    ->columnSpanFull(),

                TextInput::make('wa_app_secret')
                    ->label('Secreto de la aplicación (App Secret)')
                    ->password()
                    ->revealable()
                    ->helperText('From Meta for Developers > this store\'s App > Settings > Basic > App Secret. Required to verify incoming webhook requests are really from Meta — without it, webhooks from this store are rejected.')
                    ->columnSpanFull(),

                TextInput::make('webhook_url')
                    ->label('URL del webhook')
                    //->disabled()
                    ->dehydrated(false)
                    ->formatStateUsing(function ($state, $record) {
                        if (!$record) {
                            return env('APP_URL') . '/api/whatsapp/webhook/{store-token}';
                        }
                        $token = $record->wa_verify_token;
                        $baseUrl = env('APP_URL');
                        return "{$baseUrl}/api/whatsapp/webhook/{$token}";
                    })
                    ->helperText('Copia esta URL en Meta Developers Console > Webhook Settings > Callback URL.')
                    ->columnSpanFull(),

                // === API CREDENTIALS ===
                TextInput::make('wa_phone_number_id')
                    ->label('ID del número de teléfono')
                    ->required()
                    // A number routes to exactly one store (also a unique index).
                    ->unique(ignoreRecord: true)
                    ->helperText('Está en Meta > Settings > Phone Numbers. Formato: 123456789')
                    ->placeholder('e.g., 123456789')
                    ->columnSpan(1),

                TextInput::make('wa_business_account_id')
                    ->label('ID de WABA (cuenta de negocio)')
                    ->helperText('Está en Meta > Settings > Business Accounts. Formato: waid_1234567890')
                    ->placeholder('e.g., waid_1234567890')
                    ->columnSpan(1),

                TextInput::make('wa_access_token')
                    ->label('Token de acceso')
                    ->required()
                    ->password()
                    ->revealable()
                    ->helperText('Se genera en Meta Developers > My Apps > System User Access Tokens.')
                    ->placeholder('eaa...')
                    ->columnSpanFull(),

                // === CONVERSIONS API (META) ===
                TextInput::make('meta_dataset_id')
                    ->label('ID del conjunto de datos de Meta')
                    ->helperText('ID del conjunto de datos de la API de Conversiones para WhatsApp Business. Déjalo vacío para desactivar CAPI en esta tienda.')
                    ->columnSpanFull(),

                TextInput::make('meta_capi_access_token')
                    ->label('Token de acceso de la API de Conversiones')
                    ->password()
                    ->revealable()
                    ->helperText('Token de acceso con permisos whatsapp_business_management y whatsapp_business_manage_events (cifrado).')
                    ->columnSpanFull(),

                TextInput::make('meta_capi_currency')
                    ->label('Moneda')
                    ->placeholder('e.g. COP, USD')
                    ->required(fn (Get $get) => filled($get('meta_dataset_id')))
                    ->helperText('Código ISO de la moneda en que vende esta tienda. Es necesario para enviar el evento de compra; no se asume ninguna por defecto.')
                    ->columnSpan(1),

                TextInput::make('alert_phone')
                    ->label('Teléfono de alertas del administrador')
                    ->placeholder('e.g. 573001234567')
                    ->helperText('Número de WhatsApp (con indicativo, sin «+») que recibe un mensaje cada vez que se crea un lead en esta tienda. Déjalo vacío para desactivar la alerta.')
                    ->columnSpanFull(),

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

                // === AI PERSONA ===
                Select::make('personality_type')
                    ->label('Tipo de personalidad')
                    ->options([
                        'vendedor' => 'Sales Representative (Vendedor)',
                        'soporte' => 'Support Agent (Soporte)',
                        'asesor' => 'Asesor de negocios',
                    ])
                    ->required()
                    ->helperText('Elige la personalidad que mejor se ajuste a tu caso.')
                    ->reactive()
                    ->columnSpan(1),

                Select::make('ai_provider')
                    ->label('Proveedor de IA')
                    ->options([
                        'openai' => 'ChatGPT (OpenAI)',
                        'grok' => 'Grok',
                        'gemini' => 'Gemini',
                    ])
                    ->default('openai')
                    ->required()
                    ->reactive()
                    ->helperText('Elige tu proveedor de IA')
                    ->columnSpan(1),

                Select::make('ai_model')
                    ->label('Modelo de IA')
                    ->options(function (Get $get) {
                        $models = AIServiceFactory::enabledModels($get('ai_provider'));

                        return array_combine($models, $models);
                    })
                    // Same list the AI factory accepts: checked on the server.
                    ->rule(fn (Get $get) => Rule::in(AIServiceFactory::enabledModels($get('ai_provider'))))
                    ->default('gpt-4o')
                    ->required()
                    ->reactive()
                    ->live()
                    ->helperText('Elige el modelo para las respuestas de la IA.')
                    ->columnSpanFull(),

                TextInput::make('ai_api_key')
                    ->label('Clave de API de OpenAI')
                    ->required()
                    ->password()
                    ->revealable()
                    ->helperText('Tu clave de API de OpenAI, de https://platform.openai.com/api-keys (cifrada en la base de datos).')
                    ->placeholder('sk-...')
                    ->columnSpanFull(),

                Textarea::make('system_prompt')
                    ->label('System Prompt')
                    ->required()
                    ->rows(6)
                    ->helperText('Este prompt define cómo se comporta la IA. Se completó automáticamente según la personalidad elegida.')
                    ->columnSpanFull()
                    ->default(function (Get $get) {
                        $personality = $get('personality_type');
                        return self::$personaTemplates[$personality] ?? '';
                    })
                    ->reactive(),
            ]);
    }
}
