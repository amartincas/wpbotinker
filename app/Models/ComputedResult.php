<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * Cached result of a capability for one customer; see
 * App\Platform\Capabilities\CapabilityRunner. Deleted (not just flagged)
 * when one of its input facts is corrected or its permission is revoked.
 */
#[Fillable([
    'store_id', 'customer_id', 'domain_key', 'capability_key', 'capability_version',
    'input_hash', 'input_fact_ids', 'payload', 'computed_at',
])]
#[Hidden(['payload'])]
class ComputedResult extends Model
{
    protected function casts(): array
    {
        return [
            'input_fact_ids' => 'array',
            'payload' => 'encrypted:array',
            'computed_at' => 'datetime',
        ];
    }

    public function usesFactId(int $factId): bool
    {
        return in_array($factId, $this->input_fact_ids ?? [], true);
    }

    public function usesFactKey(string $factKey): bool
    {
        return array_key_exists($factKey, $this->input_fact_ids ?? []);
    }
}
