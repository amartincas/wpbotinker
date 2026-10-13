<?php

namespace App\Console\Commands;

use App\Models\KnowledgeSource;
use App\Models\Store;
use Illuminate\Console\Command;

/**
 * Lists the knowledge sources of one store and domain: metadata only,
 * never their content.
 */
class ListKnowledge extends Command
{
    protected $signature = 'botinker:knowledge:list
        {store : ID de la tienda}
        {domain : Clave del dominio}
        {--active : Mostrar solo las fuentes activas}';

    protected $description = 'Lista las fuentes de conocimiento de una tienda y dominio';

    public function handle(): int
    {
        $store = Store::find($this->argument('store'));

        if ($store === null) {
            $this->error("No existe la tienda [{$this->argument('store')}].");

            return self::FAILURE;
        }

        $sources = KnowledgeSource::where('store_id', $store->id)
            ->where('domain_key', $this->argument('domain'))
            ->when($this->option('active'), fn ($query) => $query->where('status', KnowledgeSource::STATUS_ACTIVE))
            ->withCount('chunks')
            ->orderBy('origin')
            ->orderBy('version')
            ->get();

        if ($sources->isEmpty()) {
            $this->info('No hay fuentes.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Versión', 'Estado', 'Título', 'Origen', 'Producto', 'Licencia', 'Fragmentos', 'Ingesta', 'Retirada'],
            $sources->map(fn (KnowledgeSource $source) => [
                $source->id,
                $source->version,
                $source->status,
                $source->title,
                $source->origin ?? '—',
                $source->product_id ?? 'dominio',
                $source->license ?? '—',
                $source->chunks_count,
                $source->ingested_at?->toDateTimeString(),
                $source->retired_at?->toDateTimeString() ?? '—',
            ])->all(),
        );

        return self::SUCCESS;
    }
}
