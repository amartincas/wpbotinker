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
                    ->label('AI Model')
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
                    ->label('AI API Key')
                    ->password()
                    ->revealable()
                    ->required()
                    ->rule('string')
                    ->rule('min:20')
                    ->columnSpanFull()
                    ->helperText('API key for the selected AI provider (encrypted). Must be at least 20 characters'),
                TextInput::make('wa_phone_number_id')
                    ->label('Phone Number ID')
                    ->required()
                    // A number routes to exactly one store (also a unique index).
                    ->unique(ignoreRecord: true)
                    ->columnSpanFull(),
                TextInput::make('wa_business_account_id')
                    ->label('WABA ID (Business Account)')
                    ->columnSpanFull(),
                TextInput::make('wa_access_token')
                    ->label('Access Token')
                    ->password()
                    ->revealable()
                    ->required()
                    ->columnSpanFull()
                    ->helperText('WhatsApp Business API access token from Meta'),
                TextInput::make('wa_verify_token')
                    ->label('Verify Token')
                    ->required()
                    ->columnSpanFull()
                    ->helperText('Verify token for webhook setup'),
                TextInput::make('wa_app_secret')
                    ->label('App Secret')
                    ->password()
                    ->revealable()
                    ->columnSpanFull()
                    ->helperText('From Meta for Developers > this store\'s App > Settings > Basic > App Secret. Required to verify incoming webhook requests are really from Meta (X-Hub-Signature-256) — without it, webhooks from this store are rejected.'),
                TextInput::make('meta_dataset_id')
                    ->label('Meta Dataset ID')
                    ->columnSpanFull()
                    ->helperText('Conversions API dataset id, from Meta Events Manager. Leave empty to disable CAPI for this store.'),
                TextInput::make('meta_capi_access_token')
                    ->label('Conversions API Access Token')
                    ->password()
                    ->revealable()
                    ->columnSpanFull()
                    ->helperText('Access token generated in Meta Events Manager for this dataset (encrypted)'),
                TextInput::make('meta_capi_currency')
                    ->label('Currency')
                    ->placeholder('e.g. COP, USD')
                    ->required(fn (Get $get) => filled($get('meta_dataset_id')))
                    ->helperText('ISO currency code this store sells in (e.g. COP, USD). Required to send the Purchase conversion event — no default is assumed, since different stores price in different currencies.'),
                TextInput::make('alert_phone')
                    ->label('Admin Alert Phone')
                    ->placeholder('e.g. 573001234567')
                    ->columnSpanFull()
                    ->helperText('WhatsApp number (with country code, no "+") that receives a message whenever a new lead is created for this store. Leave empty to disable the alert.'),
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
            ]);
    }
}
