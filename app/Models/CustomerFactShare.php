<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Permission for a domain to read a person fact of a customer. Revoking
 * keeps the row; a new grant creates a new row.
 */
#[Fillable(['customer_id', 'store_id', 'fact_key', 'grantee_domain_key', 'basis', 'granted_at', 'revoked_at'])]
class CustomerFactShare extends Model
{
    /** The customer gave the fact to this domain. */
    public const BASIS_PROVIDED = 'provided';

    /** The customer allowed this domain to reuse a fact given elsewhere. */
    public const BASIS_CONSENTED = 'consented';

    /**
     * The customer refused. Recorded as an already-revoked row, so it never
     * grants access; it only stops the permission question from repeating.
     */
    public const BASIS_DECLINED = 'declined';

    protected function casts(): array
    {
        return [
            'granted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // The unique (customer_id, active_marker) index allows one active
        // grant per fact and domain; the marker always follows revoked_at.
        static::saving(function (self $share) {
            $share->active_marker = $share->revoked_at === null ? "{$share->fact_key}:{$share->grantee_domain_key}" : null;
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
