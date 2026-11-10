<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Products\Schemas\FunnelPublicationChecks;
use App\Platform\Funnel\GuidedFunnel;
use App\Platform\Funnel\SalesFunnelVersions;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

/**
 * A funnel written as one instruction. Everything about checking, publishing
 * and conflicts is the parent's: only what is edited differs. A version made of
 * stages opens with an empty instruction, because it cannot be read as one.
 */
class EditGuidedFunnel extends EditProductFunnel
{
    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        $base = $this->baseVersion();

        $this->baseVersion = $base?->version;
        $this->activeIdOnOpening = app(SalesFunnelVersions::class)->active($this->product())?->id;

        $reactivation = GuidedFunnel::reactivation($base->definition ?? []);

        $this->form->fill([
            'instructions' => GuidedFunnel::instructions($base->definition ?? []),
            'free_replies' => GuidedFunnel::instructions($base->definition ?? []) !== null
                ? $base->definition['free_replies']['limit']
                : max(1, (int) config('botinker.free_interactions.max', 3)),
            'reactivation_message' => $reactivation['message'] ?? null,
            'reactivation_hours' => $reactivation['after_hours'] ?? GuidedFunnel::DEFAULT_REACTIVATION_HOURS,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('funnel_status')
                    ->label('Estado')
                    ->state(function () {
                        $active = app(SalesFunnelVersions::class)->active($this->product());

                        return $active !== null ? "Activo: versión v{$active->version}" : 'Sin embudo activo';
                    }),
                Textarea::make('instructions')
                    ->label('Instrucciones para la IA')
                    ->helperText('Explica con tus palabras cómo debe vender la IA: tono, enfoque, qué resaltar y qué evitar. Prevalecen sobre las «Instrucciones comerciales» del producto. No escribas precios: el sistema los toma de los planes.')
                    ->required()
                    ->rows(14)
                    ->maxLength(GuidedFunnel::MAX_INSTRUCTIONS_LENGTH)
                    ->validationMessages(['required' => 'Escribe las instrucciones para la IA.', 'max' => 'Las instrucciones no pueden superar '.GuidedFunnel::MAX_INSTRUCTIONS_LENGTH.' caracteres.']),
                TextInput::make('free_replies')
                    ->label('Cantidad de respuestas gratuitas')
                    ->helperText('Por cliente; no se reinicia. Al agotarse, el cliente aún puede pedir los planes y comprar, y la IA ya no resuelve consultas: solo orienta hacia la suscripción, un número limitado de veces.')
                    ->required()
                    ->integer()
                    ->minValue(1)
                    ->maxValue(255)
                    ->validationMessages([
                        'required' => 'Indica la cantidad de respuestas gratuitas.',
                        'integer' => 'Escribe un número entero.',
                        'min' => 'Debe ser al menos 1: es lo que limita a la IA.',
                        'max' => 'No puede superar 255.',
                    ]),
                Textarea::make('reactivation_message')
                    ->label('Mensaje de reactivación')
                    ->helperText('Se envía tal cual, una sola vez, a quien aún no se ha suscrito y deja de responder. Vacío desactiva la reactivación. No se envía a quien rechazó continuar o pidió no recibir más mensajes, ni fuera de la ventana de 24 horas de WhatsApp.')
                    ->rows(3)
                    ->maxLength(GuidedFunnel::MAX_REACTIVATION_LENGTH)
                    ->validationMessages(['max' => 'El mensaje no puede superar '.GuidedFunnel::MAX_REACTIVATION_LENGTH.' caracteres.']),
                TextInput::make('reactivation_hours')
                    ->label('Horas sin respuesta antes de reactivar')
                    ->helperText('Desde el último mensaje del cliente. Entre 1 y '.GuidedFunnel::MAX_REACTIVATION_HOURS.' horas.')
                    ->required()
                    ->integer()
                    ->minValue(1)
                    ->maxValue(GuidedFunnel::MAX_REACTIVATION_HOURS)
                    ->validationMessages([
                        'required' => 'Indica las horas sin respuesta.',
                        'integer' => 'Escribe un número entero.',
                        'min' => 'Debe ser al menos 1 hora.',
                        'max' => 'No puede superar '.GuidedFunnel::MAX_REACTIVATION_HOURS.' horas: después se cierra la ventana de WhatsApp.',
                    ]),
            ])
            ->statePath('data');
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $state = $this->form->getState();

        return GuidedFunnel::definition($state['instructions'], (int) $state['free_replies'], $state['reactivation_message'] ?? null, (int) $state['reactivation_hours']);
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return list<string>
     */
    protected function warningsFor(array $definition): array
    {
        return FunnelPublicationChecks::warnings($this->product(), $definition);
    }

    protected function editorUrl(): string
    {
        return ProductResource::getUrl('funnel-guided', ['record' => $this->product()]);
    }
}
