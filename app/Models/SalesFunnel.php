<?php

namespace App\Models;

use App\Platform\Funnel\FunnelDefinition;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

/**
 * A published version of a product's sales funnel: its configuration as
 * data, and what the product said at that moment. A record: it never
 * changes nor is removed on its own; a change is a new version (see
 * App\Platform\Funnel\SalesFunnelVersions). Which version runs is the
 * product's active_sales_funnel_id.
 */
#[Fillable(['store_id', 'product_id', 'version', 'definition', 'product_snapshot', 'restored_from_id', 'created_by', 'published_at'])]
class SalesFunnel extends Model
{
    private ?FunnelDefinition $parsedDefinition = null;

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'definition' => 'array',
            'product_snapshot' => 'array',
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $funnel) {
            $product = Product::find($funnel->product_id);

            if ($product === null) {
                throw new InvalidArgumentException('A sales funnel needs an existing product.');
            }

            if ($funnel->store_id !== null && (int) $funnel->store_id !== $product->store_id) {
                throw new InvalidArgumentException("Product [{$product->id}] does not belong to store [{$funnel->store_id}].");
            }

            if ($funnel->restored_from_id !== null && self::find($funnel->restored_from_id)?->product_id !== $product->id) {
                throw new InvalidArgumentException("Version [{$funnel->restored_from_id}] does not belong to product [{$product->id}].");
            }

            FunnelDefinition::fromArray($funnel->definition);

            $funnel->store_id = $product->store_id;
            $funnel->published_at ??= now();
        });

        static::updating(function () {
            throw new InvalidArgumentException('A published sales funnel version never changes: publish a new one.');
        });

        static::deleting(function () {
            throw new InvalidArgumentException('A published sales funnel version is a record: it is not removed on its own.');
        });
    }

    /** The validated configuration of this version. */
    public function funnelDefinition(): FunnelDefinition
    {
        return $this->parsedDefinition ??= FunnelDefinition::fromArray($this->definition);
    }

    public function hasStage(string $key): bool
    {
        return in_array($key, array_column($this->definition['stages'] ?? [], 'key'), true);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function restoredFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'restored_from_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function conversationStates(): HasMany
    {
        return $this->hasMany(ConversationFunnelState::class);
    }
}
