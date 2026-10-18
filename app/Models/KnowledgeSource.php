<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'store_id', 'domain_key', 'product_id', 'purpose', 'delivery', 'title', 'source_type', 'origin', 'license',
    'language', 'version', 'content_hash', 'original_content', 'status', 'ingested_at', 'retired_at',
])]
class KnowledgeSource extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_RETIRED = 'retired';

    public const TYPES = ['markdown', 'html', 'text'];

    /** What the topic knows: answers and samples. */
    public const PURPOSE_KNOWLEDGE = 'knowledge';

    /** Sales resources of a product (or of every product of the topic): CommercialAdvisor only. */
    public const PURPOSE_COMMERCIAL = 'commercial';

    public const PURPOSES = [self::PURPOSE_KNOWLEDGE, self::PURPOSE_COMMERCIAL];

    /** The AI may word it, never beyond what it says. */
    public const DELIVERY_REFERENCE = 'reference';

    /** Sent exactly as written. */
    public const DELIVERY_VERBATIM = 'verbatim';

    public const DELIVERIES = [self::DELIVERY_REFERENCE, self::DELIVERY_VERBATIM];

    protected $attributes = [
        'purpose' => self::PURPOSE_KNOWLEDGE,
        'delivery' => self::DELIVERY_REFERENCE,
    ];

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

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
