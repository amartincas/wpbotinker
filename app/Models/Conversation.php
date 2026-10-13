<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'store_id',
    'customer_phone',
    'last_session_at',
    'current_product_id',
    'ctwa_clid',
    'pending_state',
])]
class Conversation extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'last_session_at' => 'datetime',
            'pending_state' => 'array',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function currentProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'current_product_id');
    }
}
