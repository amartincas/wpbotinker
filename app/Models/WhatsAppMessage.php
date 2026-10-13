<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppMessage extends Model
{
    protected $table = 'whatsapp_messages';

    protected $fillable = [
        'store_id',
        'customer_phone',
        'role',
        'domain_key',
        'content',
        'wamid',
        'status',
        'status_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'status_updated_at' => 'datetime',
        ];
    }

    /**
     * Get the store that owns this message.
     */
    public function store()
    {
        return $this->belongsTo(Store::class);
    }
}
