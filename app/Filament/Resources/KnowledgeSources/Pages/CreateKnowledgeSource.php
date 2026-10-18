<?php

namespace App\Filament\Resources\KnowledgeSources\Pages;

use App\Filament\Resources\KnowledgeSources\KnowledgeSourceResource;
use App\Filament\Resources\KnowledgeSources\Schemas\KnowledgeSourceForm;
use App\Models\KnowledgeSource;
use App\Models\Product;
use App\Models\Store;
use App\Platform\Commerce\CommercialClaims;
use App\Platform\Domains\DomainRegistry;
use App\Platform\Knowledge\KnowledgeFile;
use App\Platform\Knowledge\KnowledgeIngestor;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Uploads one file through KnowledgeIngestor: same file rules as the
 * console command (KnowledgeFile), same dedup and versioning by origin.
 * The uploaded file is deleted once read, whatever the outcome; the
 * ingested source keeps its own copy of the content.
 */
class CreateKnowledgeSource extends CreateRecord
{
    protected static string $resource = KnowledgeSourceResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $path = (string) $data['file'];
        $fileName = basename((string) ($data['file_name'] ?? $path));

        try {
            $content = $this->readUpload($path, $fileName);
        } finally {
            Storage::disk(KnowledgeSourceForm::UPLOAD_DISK)->delete($path);
        }

        $store = Store::find(KnowledgeSourceResource::storeIdFor($data));
        $domainKey = (string) $data['domain_key'];

        if ($store === null || ! app(DomainRegistry::class)->has($domainKey, $store->id)) {
            throw ValidationException::withMessages(['data.domain_key' => 'Elige un dominio o un tema activo de esta tienda.']);
        }

        $product = null;

        if (filled($data['product_id'] ?? null)) {
            $product = Product::where('store_id', $store->id)->where('domain_key', $domainKey)->find($data['product_id'])
                ?? throw ValidationException::withMessages(['data.product_id' => 'El producto no pertenece a esta tienda y dominio.']);
        }

        $purpose = (string) ($data['purpose'] ?? KnowledgeSource::PURPOSE_KNOWLEDGE);
        $delivery = $purpose === KnowledgeSource::PURPOSE_COMMERCIAL ? (string) ($data['delivery'] ?? KnowledgeSource::DELIVERY_REFERENCE) : KnowledgeSource::DELIVERY_REFERENCE;

        // A verbatim resource reaches the customer as written: it may not
        // state prices or amounts, which only the product's plans give.
        if ($delivery === KnowledgeSource::DELIVERY_VERBATIM && app(CommercialClaims::class)->amount(strip_tags($content)) !== null) {
            throw ValidationException::withMessages(['data.file' => "{$fileName}: un recurso literal no puede incluir precios, importes, monedas ni porcentajes. Los precios los agrega el sistema desde los planes del producto: quítalos del archivo."]);
        }

        try {
            $source = app(KnowledgeIngestor::class)->ingest(
                $store,
                $domainKey,
                $product,
                filled($data['title'] ?? null) ? $data['title'] : pathinfo($fileName, PATHINFO_FILENAME),
                KnowledgeFile::typeFor($fileName),
                $content,
                filled($data['origin'] ?? null) ? $data['origin'] : mb_substr("upload:{$fileName}", 0, 191),
                $data['license'] ?? null,
                (string) ($data['language'] ?? 'es'),
                $purpose,
                $delivery,
            );
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['data.file' => "{$fileName}: {$e->getMessage()}"]);
        }

        return $source;
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        /** @var KnowledgeSource $source */
        $source = $this->record;

        return $source->wasRecentlyCreated
            ? "Fuente #{$source->id} v{$source->version} cargada ({$source->chunks()->count()} fragmentos)."
            : "Sin cambios: ese contenido ya existe como fuente #{$source->id} v{$source->version}.";
    }

    protected function getRedirectUrl(): string
    {
        return KnowledgeSourceResource::getUrl('index');
    }

    private function readUpload(string $path, string $fileName): string
    {
        if (KnowledgeFile::typeFor($fileName) === null) {
            throw ValidationException::withMessages(['data.file' => 'Extensión no soportada. Usa .md, .markdown, .html, .htm o .txt.']);
        }

        try {
            return KnowledgeFile::read(Storage::disk(KnowledgeSourceForm::UPLOAD_DISK)->path($path));
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['data.file' => "{$fileName}: {$e->getMessage()}"]);
        }
    }
}
