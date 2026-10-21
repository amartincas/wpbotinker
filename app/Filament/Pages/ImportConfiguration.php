<?php

namespace App\Filament\Pages;

use App\Models\Store;
use App\Services\ConfigurationImport\ConfigurationImporter;
use App\Services\ConfigurationImport\ImportReport;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Uploads a configuracion.json (botinker-config skill), shows what it would
 * create or change and imports it on confirmation. A regular user always
 * imports into their own store; only a superuser picks the store. The
 * import runs only on the exact file that was reviewed.
 */
class ImportConfiguration extends Page
{
    use WithFileUploads;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static ?string $navigationLabel = 'Importar configuración';

    protected static ?string $title = 'Importar configuración';

    protected string $view = 'filament.pages.import-configuration';

    public ?int $storeId = null;

    /** @var TemporaryUploadedFile|null */
    public $file = null;

    public bool $updateStoreTexts = false;

    /** The last preview, as plain data. */
    public ?array $report = null;

    /** Hash of the file and options the preview was made for. */
    public ?string $reviewed = null;

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return (bool) ($user?->is_super_admin || $user?->store_id);
    }

    public function mount(): void
    {
        $this->storeId = Auth::user()?->is_super_admin ? Store::orderBy('name')->value('id') : Auth::user()?->store_id;
        $this->updateStoreTexts = blank($this->store()?->system_prompt);
    }

    /** @return array<int, string> */
    public function getStoreOptions(): array
    {
        return Auth::user()?->is_super_admin ? Store::orderBy('name')->pluck('name', 'id')->all() : [];
    }

    public function updatedStoreId(): void
    {
        $this->updateStoreTexts = blank($this->store()?->system_prompt);
        $this->resetReport();
    }

    public function updatedFile(): void
    {
        $this->resetReport();
    }

    public function updatedUpdateStoreTexts(): void
    {
        $this->resetReport();
    }

    public function review(ConfigurationImporter $importer): void
    {
        $json = $this->readFile();
        $store = $this->store();

        if ($json === null || $store === null) {
            return;
        }

        $this->report = $this->toArray($importer->preview($store, $json, $this->updateStoreTexts, Auth::id()));
        $this->reviewed = $this->fingerprint($store, $json);
    }

    public function import(ConfigurationImporter $importer): void
    {
        $json = $this->readFile();
        $store = $this->store();

        if ($json === null || $store === null) {
            return;
        }

        if ($this->reviewed !== $this->fingerprint($store, $json)) {
            Notification::make()->title('Revisa el archivo antes de importarlo.')->warning()->send();

            return;
        }

        $result = $importer->import($store, $json, $this->updateStoreTexts, Auth::id());

        if (! $result->applied) {
            $this->report = $this->toArray($result);
            Notification::make()->title('No se importó nada.')->body(implode(' ', $result->errors))->danger()->send();

            return;
        }

        Notification::make()->title('Configuración importada.')->success()->send();
        $this->report = $this->toArray($result);
        $this->reviewed = null;
        $this->file = null;
    }

    public function actionLabel(string $action): string
    {
        return match ($action) {
            ImportReport::CREATE => 'Crear',
            ImportReport::UPDATE => 'Actualizar',
            ImportReport::NEW_VERSION => 'Nueva versión',
            ImportReport::UNCHANGED => 'Sin cambios',
            ImportReport::SKIPPED => 'Omitido',
            default => $action,
        };
    }

    public function show(mixed $value): string
    {
        $text = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string) ($value ?? '(vacío)');

        return mb_strlen($text) > 600 ? mb_substr($text, 0, 600).'…' : $text;
    }

    private function store(): ?Store
    {
        $user = Auth::user();

        // The store comes from the user, never from the request, unless they are a superuser.
        return Store::find($user?->is_super_admin ? $this->storeId : $user?->store_id);
    }

    private function readFile(): ?string
    {
        $this->validate(
            ['file' => ['required', 'file', 'max:'.(ConfigurationImporter::MAX_BYTES / 1024), 'mimetypes:application/json,text/plain']],
            ['file.required' => 'Elige el archivo configuracion.json.', 'file.max' => 'El archivo supera el máximo de 2 MB.', 'file.mimetypes' => 'El archivo debe ser un .json.'],
        );

        return file_get_contents($this->file->getRealPath()) ?: null;
    }

    private function fingerprint(Store $store, string $json): string
    {
        return hash('sha256', $store->id.'|'.(int) $this->updateStoreTexts.'|'.$json);
    }

    private function resetReport(): void
    {
        $this->report = null;
        $this->reviewed = null;
    }

    private function toArray(ImportReport $report): array
    {
        return [
            'errors' => $report->errors,
            'warnings' => $report->warnings,
            'rows' => $report->rows,
            'pending' => $report->pending,
            'applied' => $report->applied,
            'has_changes' => $report->hasChanges(),
        ];
    }
}
