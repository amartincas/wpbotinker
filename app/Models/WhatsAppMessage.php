<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

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
     * Records a turn whose reply WhatsApp already accepted: both rows or
     * neither, the reply already carrying its WhatsApp id and the initial
     * 'pending' status (as WhatsAppStatusTracker::trackMessage() sets it).
     */
    public static function recordDeliveredTurn(int $storeId, string $customerPhone, ?string $domainKey, string $userContent, string $reply, string $wamid): self
    {
        return DB::transaction(function () use ($storeId, $customerPhone, $domainKey, $userContent, $reply, $wamid) {
            self::create(['store_id' => $storeId, 'customer_phone' => $customerPhone, 'role' => 'user', 'domain_key' => $domainKey, 'content' => $userContent]);

            return self::create([
                'store_id' => $storeId,
                'customer_phone' => $customerPhone,
                'role' => 'assistant',
                'domain_key' => $domainKey,
                'content' => $reply,
                'wamid' => $wamid,
                'status' => 'pending',
                'status_updated_at' => now(),
            ]);
        });
    }

    /**
     * Get the store that owns this message.
     */
    public function store()
    {
        return $this->belongsTo(Store::class);
    }
}
