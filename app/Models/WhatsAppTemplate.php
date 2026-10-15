<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppTemplate extends Model
{
    protected $table = 'whatsapp_templates';
    
    protected $fillable = [
        'store_id',
        'name',
        'body_preview',
        'parameters_map',
        'requires_phone_input',
        'language',
        'type',
        'is_reengagement',
        'is_lead_alert',
        'event_key',
    ];

    /** Template sent to the store admin about a receipt to review. Parameters: store_name, payment_ref, product_name, amount, customer_phone. */
    public const EVENT_PAYMENT_ALERT = 'payment_alert';

    /** Template sent to the customer with the review result. Parameters: product_name, result, expires_at. */
    public const EVENT_PAYMENT_RESULT = 'payment_result';

    protected $casts = [
        'parameters_map'  => 'array',
        'is_reengagement' => 'boolean',
        'requires_phone_input' => 'boolean',
        'is_lead_alert'   => 'boolean',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
