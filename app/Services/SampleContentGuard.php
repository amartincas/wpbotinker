<?php

namespace App\Services;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeSource;

/**
 * A sample reaches customers without access, so it may not repeat a section
 * of the knowledge that only subscribers read. Whoever adds a sample asks here
 * and undoes its own transaction when refused, so a previous version stays
 * active. It compares whole sections exactly: a reworded text is not detected.
 */
class SampleContentGuard
{
    /** Why the sample cannot be accepted; null when it can. */
    public function refusalFor(KnowledgeSource $sample): ?string
    {
        $copied = KnowledgeChunk::where('store_id', $sample->store_id)
            ->where('domain_key', $sample->domain_key)
            ->whereIn('content', $sample->chunks()->pluck('content'))
            ->whereHas('source', fn ($query) => $query->where('status', KnowledgeSource::STATUS_ACTIVE)->where('purpose', KnowledgeSource::PURPOSE_KNOWLEDGE))
            ->with('source')
            ->first();

        return $copied !== null
            ? "repite contenido exclusivo de suscriptores (fuente «{$copied->source->title}»). Una muestra debe ser material propio para clientes sin acceso."
            : null;
    }
}
