<?php

namespace App\Console\Commands;

use App\Models\KnowledgeSource;
use App\Platform\Knowledge\KnowledgeIngestor;
use Illuminate\Console\Command;

/**
 * Retires a knowledge source: it stops being retrieved, but the source and
 * its chunks are kept as history. The store is required so a mistyped id
 * can never touch another store's knowledge.
 */
class RetireKnowledge extends Command
{
    protected $signature = 'botinker:knowledge:retire
        {store : ID de la tienda dueña de la fuente}
        {source : ID de la fuente}';

    protected $description = 'Retira una fuente de conocimiento sin borrar su historial';

    public function handle(KnowledgeIngestor $ingestor): int
    {
        $source = KnowledgeSource::where('store_id', $this->argument('store'))->find($this->argument('source'));

        if ($source === null) {
            $this->error("La fuente [{$this->argument('source')}] no existe en la tienda [{$this->argument('store')}].");

            return self::FAILURE;
        }

        if ($source->status === KnowledgeSource::STATUS_RETIRED) {
            $this->info("La fuente #{$source->id} ya estaba retirada.");

            return self::SUCCESS;
        }

        $ingestor->retire($source);

        $this->info("Fuente #{$source->id} v{$source->version} retirada; se conserva su historial.");

        return self::SUCCESS;
    }
}
