<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'store_id', 'domain_key', 'product_id', 'title', 'source_type', 'origin', 'license',
    'language', 'version', 'content_hash', 'original_content', 'status', 'ingested_at', 'retired_at',
])]
class KnowledgeSource extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_RETIRED = 'retired';

    public const TYPES = ['markdown', 'html', 'text'];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'ingested_at' => 'datetime',
            'retired_at' => 'datetime',
        ];
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(KnowledgeChunk::class);
    }
}
