<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'store_id',
    'product_id',
    'payment_id',
    'customer_phone',
    'customer_name',
    'delivery_address_or_location',
    'product_service_name',
    'preferred_date_time',
    'summary',
    'sale_value',
    'ctwa_clid',
    'meta_capi_sent_at',
    'is_processed',
    'bot_active',
    'status',
    'order_status',
    'tracking_number',
    'carrier',
    'needs_address_review',
])]
class Lead extends Model
{
    use HasFactory;

    /**
     * Order fulfillment lifecycle values for `order_status`.
     */
    public const ORDER_STATUSES = [
        'confirmado' => 'Confirmado',
        'enviado' => 'Enviado',
        'entregado' => 'Entregado',
        'devuelto' => 'Devuelto',
    ];

    protected function casts(): array
    {
        return [
            'is_processed' => 'boolean',
            'bot_active' => 'boolean',
            'sale_value' => 'decimal:2',
            'meta_capi_sent_at' => 'datetime',
            'needs_address_review' => 'boolean',
        ];
    }

    /**
     * Get the store that owns this lead.
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * Get the product this lead is for, if one was confidently linked.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Mark the lead as processed.
     */
    public function markAsProcessed(): void
    {
        $this->update(['is_processed' => true]);
    }

    /**
     * Check if the lead has been processed.
     */
    public function isProcessed(): bool
    {
        return $this->is_processed === true;
    }

    /**
     * Get all unprocessed leads for a store.
     */
    public static function unprocessed($storeId)
    {
        return static::where('store_id', $storeId)
            ->where('is_processed', false)
            ->orderBy('created_at', 'desc')
            ->get();
    }
}
