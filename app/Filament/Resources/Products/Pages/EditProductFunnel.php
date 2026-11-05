<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Products\Schemas\FunnelDefinitionForm;
use App\Filament\Resources\Products\Schemas\FunnelDefinitionFormMapper;
use App\Filament\Resources\Products\Schemas\FunnelPublicationChecks;
use App\Filament\Resources\Products\Schemas\FunnelPublicationPreview;
use App\Filament\Resources\Products\Schemas\FunnelSummaryInfolist;
use App\Models\KnowledgeSource;
use App\Models\Product;
use App\Models\SalesFunnel;
use App\Platform\Funnel\FunnelDefinition;
use App\Platform\Funnel\SalesFunnelVersions;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Throwable;

/**
 * Writes a funnel's definition in memory, starting from any version of the
 * product or from nothing, and publishes it. Only publishing stores anything:
 * leaving discards it. ProductResource resolves the product, so a product of
 * another store is a 404, and the version is looked up among the product's
 * own.
 */
class EditProductFunnel extends Page
{
    use InteractsWithRecord;

    protected static string $resource = ProductResource::class;

    /** The version to start from; without one, the active version. */
    #[Url]
    public ?string $from = null;

    #[Locked]
    public ?int $baseVersion = null;

    /** Which version was active on opening: publishing over another one would overwrite it unseen. */
    #[Locked]
    public ?int $activeIdOnOpening = null;

    /** @var list<string> */
    #[Locked]
    public array $existingKeys = [];

    /**
     * What the last check found worth a second look.
     *
     * @var list<string>
     */
    #[Locked]
    public array $configurationWarnings = [];

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        $base = $this->baseVersion();
        $state = FunnelDefinitionFormMapper::toFormState($base->definition ?? []);

        $this->baseVersion = $base?->version;
        $this->activeIdOnOpening = app(SalesFunnelVersions::class)->active($this->product())?->id;
        $this->existingKeys = array_column($state['stages'], 'key');
        $state['stages'] = array_map(fn (array $stage) => $stage + ['is_existing' => true], $state['stages']);
        // Absent, the contract reads it as true, and a switch must show what applies.
        $state['opening_consumes_free_reply'] ??= true;

        $this->form->fill($state);
    }

    public function getTitle(): string
    {
        return "Editar el embudo de «{$this->product()->name}»";
    }

    public function getSubheading(): string
    {
        $origin = $this->baseVersion !== null ? "Partes de la versión v{$this->baseVersion}." : 'Partes de un embudo vacío.';

        return "{$origin} Los cambios solo existen en esta pantalla hasta publicar: cerrarla o salir de ella los descarta.";
    }

    public function getBreadcrumb(): string
    {
        return 'Editar embudo';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('leave')
                ->label('Salir sin guardar')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(ProductResource::getUrl('funnel', ['record' => $this->product()])),
            Action::make('checkDefinition')
                ->label('Comprobar definición')
                ->icon('heroicon-o-check-circle')
                ->color('gray')
                ->action(fn () => $this->checkDefinition()),
            $this->publishAction(),
        ];
    }

    private function publishAction(): Action
    {
        return Action::make('publish')
            ->label('Publicar')
            ->icon('heroicon-o-rocket-launch')
            ->modalHeading('Publicar esta versión')
            ->modalWidth(Width::TwoExtraLarge)
            ->modalSubmitActionLabel('Publicar y activar ahora')
            ->beforeFormFilled(function (Action $action) {
                if ($this->validDefinition() === null || $this->conflictNotified()) {
                    $action->cancel();
                }
            })
            ->schema(function () {
                $definition = $this->definitionForPreview() ?? [];

                return [
                    ...FunnelPublicationPreview::components($this->product(), $definition, $this->warningsFor($definition)),
                    Checkbox::make('confirmed')
                        ->label('Entiendo que se aplica de inmediato a todos los prospectos de este producto.')
                        ->accepted()
                        ->validationMessages(['accepted' => 'Confirma que entiendes el efecto de publicar.']),
                ];
            })
            ->action(fn () => $this->publish());
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Advertencias de configuración')
                ->description('De la última comprobación. No impiden publicar.')
                ->visible(fn () => $this->configurationWarnings !== [])
                ->schema([
                    TextEntry::make('configuration_warnings')
                        ->hiddenLabel()
                        ->state(fn () => $this->configurationWarnings)
                        ->listWithLineBreaks(),
                ]),
            EmbeddedSchema::make('form'),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components(FunnelDefinitionForm::components($this->existingKeys, FunnelSummaryInfolist::activeSources($this->product(), KnowledgeSource::PURPOSE_SAMPLE)))
            ->statePath('data');
    }

    /**
     * What the form holds as a definition, after validating its fields.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return FunnelDefinitionFormMapper::toDefinition($this->form->getState());
    }

    private function checkDefinition(): void
    {
        $this->configurationWarnings = [];

        $definition = $this->validDefinition();

        if ($definition === null) {
            return;
        }

        $this->configurationWarnings = $this->warningsFor($definition);

        Notification::make()->success()->title('La definición es válida.')->body('Todavía no se ha guardado nada.')->send();
    }

    /**
     * The form's validation, in its fields, and then the contract's, which is
     * the final word and whose message only exists in English.
     *
     * @return array<string, mixed>|null null when the contract refuses it
     */
    private function validDefinition(): ?array
    {
        $definition = $this->definition();

        try {
            FunnelDefinition::fromArray($definition);
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()
                ->title('La definición no supera la validación del motor. No se publicó nada.')
                ->body("Detalle técnico: {$e->getMessage()}")
                ->send();

            return null;
        }

        return $definition;
    }

    /**
     * The modal's content is built before validating: a form that does not
     * validate shows its errors instead, through beforeFormFilled().
     *
     * @return array<string, mixed>|null
     */
    private function definitionForPreview(): ?array
    {
        try {
            return $this->definition();
        } catch (ValidationException) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return list<string>
     */
    protected function warningsFor(array $definition): array
    {
        return [
            ...FunnelPublicationChecks::warnings($this->product(), $definition),
            ...FunnelPublicationChecks::discardedMessageRules($this->originDefinition()),
        ];
    }

    /**
     * The check is repeated under the same lock the service takes, so nothing
     * can be published between comparing and publishing. Whatever fails undoes
     * the whole transaction: no version and the same one active.
     */
    private function publish(): void
    {
        $definition = $this->validDefinition();

        if ($definition === null) {
            return;
        }

        $product = $this->product();

        try {
            $version = DB::transaction(function () use ($product, $definition) {
                $current = Product::whereKey($product->id)->lockForUpdate()->value('active_sales_funnel_id');

                if ($current !== null) {
                    $current = (int) $current;
                }

                return $current === $this->activeIdOnOpening
                    ? app(SalesFunnelVersions::class)->publish($product, $definition, Auth::id())
                    : null;
            });
        } catch (Throwable $e) {
            report($e);

            Notification::make()->danger()->title('No se pudo publicar. Nada cambió: inténtalo de nuevo.')->send();

            return;
        }

        if ($version === null) {
            $this->notifyConflict();

            return;
        }

        Notification::make()->success()->title("Se publicó y activó la versión v{$version->version}.")->send();

        $this->redirect(ProductResource::getUrl('funnel', ['record' => $product]));
    }

    private function conflictNotified(): bool
    {
        if (app(SalesFunnelVersions::class)->active($this->product())?->id === $this->activeIdOnOpening) {
            return false;
        }

        $this->notifyConflict();

        return true;
    }

    private function notifyConflict(): void
    {
        $product = $this->product();
        $active = app(SalesFunnelVersions::class)->active($product);

        Notification::make()
            ->danger()
            ->persistent()
            ->title('El embudo activo cambió mientras editabas. No se publicó nada.')
            ->body('Revisa lo que está activo ahora. Recargar el editor descarta lo que escribiste en esta pantalla.')
            ->actions(array_values(array_filter([
                $active !== null
                    ? Action::make('reviewActive')
                        ->label("Revisar la versión v{$active->version}")
                        ->url(ProductResource::getUrl('funnel-version', ['record' => $product, 'version' => $active->version]), shouldOpenInNewTab: true)
                    : null,
                Action::make('reload')
                    ->label('Recargar el editor')
                    ->url($this->editorUrl()),
            ])))
            ->send();
    }

    protected function editorUrl(): string
    {
        return ProductResource::getUrl('funnel-edit', ['record' => $this->product()]);
    }

    protected function baseVersion(): ?SalesFunnel
    {
        if ($this->from !== null) {
            return $this->product()->salesFunnels()->where('version', (int) $this->from)->firstOrFail();
        }

        return $this->product()->activeFunnel();
    }

    /**
     * @return array<string, mixed>
     */
    private function originDefinition(): array
    {
        return $this->baseVersion !== null
            ? $this->product()->salesFunnels()->where('version', $this->baseVersion)->value('definition') ?? []
            : [];
    }

    protected function product(): Product
    {
        /** @var Product */
        return $this->getRecord();
    }
}
