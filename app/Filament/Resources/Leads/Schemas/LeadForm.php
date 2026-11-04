<?php

namespace App\Filament\Resources\Leads\Schemas;

use App\Models\Lead;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class LeadForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->schema([
                // Regular users get their store_id from CreateLead's
                // mutateFormDataBeforeCreate(); super admins have no store of
                // their own, so they need to pick one explicitly here.
                Select::make('store_id')
                    ->label('Tienda')
                    ->relationship('store', 'name')
                    ->required()
                    ->visible(Auth::user()?->is_super_admin)
                    ->columnSpanFull(),
                TextInput::make('customer_name')
                    ->label('Nombre completo')
                    ->required()
                    ->columnSpan(2),
                TextInput::make('customer_phone')
                    ->label('Número de WhatsApp')
                    ->tel()
                    ->required()
                    ->columnSpan(2),
                Textarea::make('delivery_address_or_location')
                    ->label('Dirección de entrega o lugar del servicio')
                    ->rows(3)
                    ->columnSpanFull(),
                TextInput::make('product_service_name')
                    ->label('Producto o servicio')
                    ->columnSpan(2),
                TextInput::make('sale_value')
                    ->label('Valor de la venta')
                    ->numeric()
                    ->helperText('Se resuelve solo del catálogo si coincide con "Product / Service Name" — ajústalo aquí si es distinto (precio negociado, varios productos, etc.).')
                    ->columnSpan(2),
                TextInput::make('preferred_date_time')
                    ->label('Fecha y hora preferidas')
                    ->hint('Para servicios: fecha y hora preferidas de la reserva')
                    ->columnSpan(2),
                Textarea::make('summary')
                    ->label('Resumen del pedido')
                    ->rows(4)
                    ->required()
                    ->columnSpanFull(),
                Select::make('order_status')
                    ->label('Estado del pedido')
                    ->options(Lead::ORDER_STATUSES)
                    ->helperText('Confirmado/Enviado se marcan solos; marca Entregado o Devuelto manualmente cuando corresponda.')
                    ->columnSpan(2),
                TextInput::make('tracking_number')
                    ->label('Número de guía')
                    ->columnSpan(1),
                TextInput::make('carrier')
                    ->label('Transportadora')
                    ->columnSpan(1),
                Toggle::make('needs_address_review')
                    ->label('Requiere revisar la dirección')
                    ->helperText('Se marca automáticamente cuando parece faltar la ciudad o región en la dirección de entrega.')
                    ->columnSpanFull(),
                Toggle::make('is_processed')
                    ->label('Marcar como procesado')
                    ->hint('Actívalo cuando el lead se haya atendido con éxito')
                    ->columnSpanFull(),
                Toggle::make('bot_active')
                    ->label('Bot activo para este cliente')
                    ->helperText('Desactívalo para atender la conversación manualmente.')
                    ->default(true)
                    ->columnSpanFull(),
                Placeholder::make('recent_conversation')
                    ->label('Conversación reciente')
                    ->content(function ($record) {
                        if (!$record) {
                            return view('filament.components.chat-history', [
                                'record' => null,
                                'messages' => collect([]),
                            ]);
                        }

                        $messages = \App\Models\WhatsAppMessage::where('store_id', $record->store_id)
                            ->where('customer_phone', $record->customer_phone)
                            ->orderBy('created_at', 'desc')
                            ->limit(15)
                            ->get()
                            ->reverse();

                        return view('filament.components.chat-history', [
                            'record' => $record,
                            'messages' => $messages,
                        ]);
                    })
                    ->columnSpanFull(),
            ]);
    }
}
