<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One version of a fact about a customer. Rows are never updated in place
 * except to mark them superseded; see App\Platform\Memory\CustomerMemory.
 */
#[Fillable([
    'customer_id', 'store_id', 'scope', 'owner_domain_key', 'fact_key', 'identity_key',
    'version', 'status', 'value', 'source', 'collected_by_domain', 'source_wamid',
    'actor_user_id', 'collected_at', 'superseded_by_id',
])]
#[Hidden(['value'])]
class CustomerFact extends Model
{
    public const STATUS_CURRENT = 'current';

    public const STATUS_SUPERSEDED = 'superseded';

    public const SOURCE_CUSTOMER_ANSWER = 'customer_answer';

    public const SOURCE_OPERATOR = 'operator';

    protected function casts(): array
    {
        return [
            // Wrapped as ['v' => ...] so scalars of any type survive encryption.
            'value' => 'encrypted:array',
            'version' => 'integer',
            'collected_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // The unique (customer_id, current_marker) index allows one current
        // version per identity; the marker always follows the status.
        static::saving(function (self $fact) {
            $fact->current_marker = $fact->status === self::STATUS_CURRENT ? $fact->identity_key : null;
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function plainValue(): mixed
    {
        return $this->value['v'] ?? null;
    }
}
