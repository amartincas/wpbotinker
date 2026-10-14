<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A customer of one store, identified by phone. Memory (facts, shares) is
 * always reached through the customer, never by ids taken from a message.
 */
#[Fillable(['store_id', 'customer_phone'])]
class Customer extends Model
{
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function facts(): HasMany
    {
        return $this->hasMany(CustomerFact::class);
    }

    public function factShares(): HasMany
    {
        return $this->hasMany(CustomerFactShare::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function sampleDeliveries(): HasMany
    {
        return $this->hasMany(SampleDelivery::class);
    }
}
