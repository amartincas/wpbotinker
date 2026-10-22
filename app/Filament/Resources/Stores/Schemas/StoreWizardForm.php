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
                    ->label('Verify Token')
                    ->helperText('This is your webhook verify token. Use this in Meta Developers Console > Webhook Settings.')
                    ->reactive()
                    ->columnSpanFull(),

                TextInput::make('wa_app_secret')
                    ->label('App Secret')
                    ->password()
                    ->revealable()
                    ->helperText('From Meta for Developers > this store\'s App > Settings > Basic > App Secret. Required to verify incoming webhook requests are really from Meta — without it, webhooks from this store are rejected.')
                    ->columnSpanFull(),

                TextInput::make('webhook_url')
                    ->label('Webhook URL')
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
                    ->helperText('Copy this URL to Meta Developers Console > Webhook Settings > Callback URL')
                    ->columnSpanFull(),

                // === API CREDENTIALS ===
                TextInput::make('wa_phone_number_id')
                    ->label('Phone Number ID')
                    ->required()
                    // A number routes to exactly one store (also a unique index).
                    ->unique(ignoreRecord: true)
                    ->helperText('Found in Meta > Settings > Phone Numbers. Format: 123456789')
                    ->placeholder('e.g., 123456789')
                    ->columnSpan(1),

                TextInput::make('wa_business_account_id')
                    ->label('WABA ID (Business Account)')
                    ->helperText('Found in Meta > Settings > Business Accounts. Format: waid_1234567890')
                    ->placeholder('e.g., waid_1234567890')
                    ->columnSpan(1),

                TextInput::make('wa_access_token')
                    ->label('Access Token')
                    ->required()
                    ->password()
                    ->revealable()
                    ->helperText('Generate in Meta Developers > My Apps > System User Access Tokens')
                    ->placeholder('eaa...')
                    ->columnSpanFull(),

                // === CONVERSIONS API (META) ===
                TextInput::make('meta_dataset_id')
                    ->label('Meta Dataset ID')
                    ->helperText('Conversions API dataset id for WhatsApp Business Messaging. Leave empty to disable CAPI for this store.')
                    ->columnSpanFull(),

                TextInput::make('meta_capi_access_token')
                    ->label('Conversions API Access Token')
                    ->password()
                    ->revealable()
                    ->helperText('Access token with whatsapp_business_management / whatsapp_business_manage_events permissions (encrypted)')
                    ->columnSpanFull(),

                TextInput::make('meta_capi_currency')
                    ->label('Currency')
                    ->placeholder('e.g. COP, USD')
                    ->required(fn (Get $get) => filled($get('meta_dataset_id')))
                    ->helperText('ISO currency code this store sells in. Required to send the Purchase conversion event — no default is assumed.')
                    ->columnSpan(1),

                TextInput::make('alert_phone')
                    ->label('Admin Alert Phone')
                    ->placeholder('e.g. 573001234567')
                    ->helperText('WhatsApp number (with country code, no "+") that receives a message whenever a new lead is created for this store. Leave empty to disable the alert.')
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
                    ->label('Persona Type')
                    ->options([
                        'vendedor' => 'Sales Representative (Vendedor)',
                        'soporte' => 'Support Agent (Soporte)',
                        'asesor' => 'Business Advisor (Asesor)',
                    ])
                    ->required()
                    ->helperText('Select the personality that best matches your use case.')
                    ->reactive()
                    ->columnSpan(1),

                Select::make('ai_provider')
                    ->label('AI Provider')
                    ->options([
                        'openai' => 'ChatGPT (OpenAI)',
                        'grok' => 'Grok',
                        'gemini' => 'Gemini',
                    ])
                    ->default('openai')
                    ->required()
                    ->reactive()
                    ->helperText('Select your AI provider')
                    ->columnSpan(1),

                Select::make('ai_model')
                    ->label('AI Model')
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
                    ->helperText('Choose the model for your AI responses.')
                    ->columnSpanFull(),

                TextInput::make('ai_api_key')
                    ->label('OpenAI API Key')
                    ->required()
                    ->password()
                    ->revealable()
                    ->helperText('Your OpenAI API key from https://platform.openai.com/api-keys (encrypted in database)')
                    ->placeholder('sk-...')
                    ->columnSpanFull(),

                Textarea::make('system_prompt')
                    ->label('System Prompt')
                    ->required()
                    ->rows(6)
                    ->helperText('This prompt defines how the AI behaves. It was auto-filled based on your persona selection.')
                    ->columnSpanFull()
                    ->default(function (Get $get) {
                        $personality = $get('personality_type');
                        return self::$personaTemplates[$personality] ?? '';
                    })
                    ->reactive(),
            ]);
    }
}
