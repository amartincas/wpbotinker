<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
    'personality_type',
    'system_prompt',
    'ai_provider',
    'ai_model',
    'ai_api_key',
    'wa_access_token',
    'wa_phone_number_id',
    'wa_business_account_id',
    'wa_verify_token',
    'wa_app_secret',
    'meta_dataset_id',
    'meta_capi_access_token',
    'meta_capi_currency',
    'alert_phone',
    'conversation_mode',
    'payment_instructions',
    'welcome_message',
    'topic_prompt_message',
    'topic_unavailable_message',
])]
class Store extends Model
{
    use HasFactory;

    /** The existing commercial flow (default). */
    public const MODE_SALES = 'sales';

    /** Domain conversation pipeline (App\Platform\Conversation\ConversationPipeline). */
    public const MODE_KNOWLEDGE = 'knowledge';

    public function usesKnowledgeMode(): bool
    {
        return $this->conversation_mode === self::MODE_KNOWLEDGE;
    }

    protected function casts(): array
    {
        return [
            'personality_type' => 'string',
            'ai_provider' => 'string',
            'ai_api_key' => 'encrypted',
            'wa_access_token' => 'encrypted',
            'wa_verify_token' => 'encrypted',
            'wa_app_secret' => 'encrypted',
            'meta_capi_access_token' => 'encrypted',
        ];
    }

    /**
     * Whether this store has Meta Conversions API credentials configured.
     */
    public function hasCapiConfigured(): bool
    {
        return filled($this->meta_dataset_id) && filled($this->meta_capi_access_token);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    /**
     * The store admin's WhatsApp number (alert_phone, digits only): the
     * only sender whose replies can decide this store's payments.
     */
    public function adminWhatsApp(): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $this->alert_phone);

        return $digits === '' ? null : $digits;
    }

    public function plans(): HasMany
    {
        return $this->hasMany(Plan::class);
    }
}
