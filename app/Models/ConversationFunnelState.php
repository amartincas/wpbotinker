<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/**
 * Where a conversation is in a product's sales funnel: the version it runs
 * on, its stage and what that stage needs to remember. Unique per
 * conversation and product. The free replies are not counted here: they
 * belong to sample_deliveries.
 */
#[Fillable(['conversation_id', 'product_id', 'sales_funnel_id', 'stage', 'stage_interactions', 'context', 'stage_entered_at'])]
class ConversationFunnelState extends Model
{
    protected $attributes = [
        'stage_interactions' => 0,
    ];

    protected function casts(): array
    {
        return [
            'stage_interactions' => 'integer',
            'context' => 'array',
            'stage_entered_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $state) {
            $conversation = Conversation::find($state->conversation_id);
            $product = Product::find($state->product_id);
            $funnel = SalesFunnel::find($state->sales_funnel_id);

            if ($conversation === null || $product === null || $funnel === null) {
                throw new InvalidArgumentException('A funnel state needs an existing conversation, product and funnel version.');
            }

            if ($conversation->store_id !== $product->store_id) {
                throw new InvalidArgumentException("Conversation [{$conversation->id}] and product [{$product->id}] belong to different stores.");
            }

            if ($funnel->product_id !== $product->id) {
                throw new InvalidArgumentException("Version [{$funnel->id}] does not belong to product [{$product->id}].");
            }

            if (! $funnel->hasStage((string) $state->stage)) {
                throw new InvalidArgumentException("Version [{$funnel->id}] has no stage [{$state->stage}].");
            }

            $state->stage_entered_at ??= now();
        });
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function salesFunnel(): BelongsTo
    {
        return $this->belongsTo(SalesFunnel::class);
    }
}
