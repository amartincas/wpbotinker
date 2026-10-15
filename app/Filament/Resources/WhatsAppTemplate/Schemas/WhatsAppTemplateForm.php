<?php

namespace App\Filament\Resources\WhatsAppTemplate\Schemas;

use App\Models\Store;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class WhatsAppTemplateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->schema([

            Select::make('store_id')
                ->label('Tienda')
                ->relationship(
                    'store',
                    'name',
                    fn ($query) => $query->when(
                        ! Auth::user()?->is_super_admin,
                        fn ($q) => $q->where('id', Auth::user()?->store_id)
                    )
                )
                ->required()
                ->default(Auth::user()?->store_id)
                ->searchable()
                ->preload(),

            TextInput::make('name')
                ->label('Nombre Tecnico (Meta)')
                ->placeholder('ej: shipping_update_v2')
                ->helperText('Debe coincidir exactamente con el nombre aprobado en Meta Business Manager. Solo minusculas, guiones bajos, sin espacios.')
                ->required()
                ->maxLength(512),

            TextInput::make('language')
                ->label('Codigo de Idioma')
                ->placeholder('es_CO')
                ->helperText('Codigo BCP-47 del idioma registrado en Meta. Ej: es_CO, en_US, pt_BR.')
                ->default('es_CO')
                ->required()
                ->maxLength(10),

            Select::make('type')
                ->label('Categoria Meta')
                ->options([
                    'utility'        => 'Utility - Transaccional / Informativo',
                    'marketing'      => 'Marketing - Promocional / Re-engagement',
                    'authentication' => 'Authentication - OTP / Verificacion',
                ])
                ->default('utility')
                ->required()
                ->native(false)
                ->helperText('Debe coincidir con la categoria aprobada por Meta para esta plantilla.'),

            Textarea::make('body_preview')
                ->label('Vista Previa del Cuerpo')
                ->placeholder('Ej: Hola {{1}}, tu pedido {{2}} ha sido enviado.')
                ->helperText('Texto de referencia para los operadores. Usa {{1}}, {{2}}, etc. Este campo NO se envia a Meta.')
                ->rows(4)
                ->required()
                ->maxLength(4096)
                ->columnSpanFull(),

            KeyValue::make('parameters_map')
                ->label('Mapa de Parametros')
                ->keyLabel('Numero de Posicion (ej: 1, 2, 3)')
                ->valueLabel('Campo del Lead o Etiqueta Manual')
                ->helperText('La clave es el numero del marcador {{N}}. El valor es el campo del lead (ej: customer_name, product_name, customer_phone).')
                ->addActionLabel('+ Agregar parametro')
                ->reorderable()
                ->columnSpanFull(),

            Toggle::make('is_reengagement')
                ->label('Plantilla de Re-engagement')
                ->helperText('Activalo si esta plantilla reabre la ventana de 24h de WhatsApp. El bot de IA se reactivara automaticamente cuando el cliente responda.')
                ->onColor('success')
                ->offColor('gray')
                ->default(false)
                ->inline(false)
                ->columnSpanFull(),

            Toggle::make('requires_phone_input')
                ->label('Request Phone Number')
                ->helperText('Enable this for templates intended for new contacts not yet in the chat list.')
                ->onColor('success')
                ->offColor('gray')
                ->default(false)
                ->inline(false)
                ->columnSpanFull(),

            Select::make('event_key')
                ->label('Uso en pagos (opcional)')
                ->options([
                    \App\Models\WhatsAppTemplate::EVENT_PAYMENT_ALERT => 'Alerta de comprobante al administrador (store_name, payment_ref, product_name, amount, customer_phone)',
                    \App\Models\WhatsAppTemplate::EVENT_PAYMENT_RESULT => 'Resultado del pago al cliente (product_name, result, expires_at)',
                ])
                ->helperText('Se usa solo cuando la ventana de 24 h de WhatsApp está cerrada. Usa estos nombres en el mapa de parámetros.')
                ->columnSpanFull(),

            Toggle::make('is_lead_alert')
                ->label('Plantilla de Alerta de Nuevo Lead')
                ->helperText('Activalo si esta es la plantilla que se envia al "Admin Alert Phone" de la tienda cada vez que se crea un lead nuevo. Solo una por tienda. Usa como parametros: store_name, customer_name, customer_phone, product_service_name, sale_value.')
                ->onColor('success')
                ->offColor('gray')
                ->default(false)
                ->inline(false)
                ->columnSpanFull(),

        ]);
    }
}
