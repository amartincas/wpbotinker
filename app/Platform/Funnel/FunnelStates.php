<?php

namespace App\Platform\Funnel;

use App\Models\Conversation;
use App\Models\ConversationFunnelState;
use App\Models\Product;
use App\Models\SalesFunnel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * Where each conversation is in each product's funnel. Stored as told: what
 * the next stage is gets decided elsewhere. Forgetting a state does not touch
 * the free replies already given (sample_deliveries).
 *
 * Call save() and reset() outside any transaction: a deadlock undoes the
 * whole transaction, so inside one these methods cannot recover from it. An
 * operation that needs more atomicity needs its own transaction with its own
 * full retries.
 */
class FunnelStates
{
    private const MAX_RETRIES = 3;

    /** MariaDB: a concurrent save or reset took the row the insert counted on. */
    private const DUPLICATE_ENTRY = 1062;

    /** MariaDB: two inserts waiting on each other over the same unique index. */
    private const DEADLOCK = 1213;

    public function find(Conversation $conversation, Product $product): ?ConversationFunnelState
    {
        return ConversationFunnelState::where('conversation_id', $conversation->id)->where('product_id', $product->id)->first();
    }

    /**
     * Saved over whatever is there at that moment. A collision with another
     * save or a reset of the same state repeats the whole operation, reading
     * the state again; once the retries run out, or on any other error, the
     * exception goes up.
     *
     * @param  array<string, mixed>|null  $context
     */
    public function save(Conversation $conversation, Product $product, SalesFunnel $funnel, string $stage, int $stageInteractions = 0, ?array $context = null): ConversationFunnelState
    {
        return $this->withRetries($conversation, $product, fn () => $this->store($conversation, $product, $funnel, $stage, $stageInteractions, $context));
    }

    /**
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    private function withRetries(Conversation $conversation, Product $product, callable $operation): mixed
    {
        for ($retry = 0; ; $retry++) {
            try {
                return $operation();
            } catch (QueryException $e) {
                if ($retry >= self::MAX_RETRIES || ! $this->isRetriable($e)) {
                    throw $e;
                }

                Log::info('FUNNEL_STATE_RETRY', ['conversation_id' => $conversation->id, 'product_id' => $product->id, 'error' => $e->errorInfo[1], 'retry' => $retry + 1]);

                // A little apart, so the same processes do not collide again.
                Sleep::for(random_int(5, 25) * ($retry + 1))->milliseconds();
            }
        }
    }

    /**
     * A deadlock undoes the whole surrounding transaction: repeating only
     * this operation inside it would lose the rest without saying so, so there it
     * goes up to whoever owns the transaction.
     */
    private function isRetriable(QueryException $e): bool
    {
        return match ($e->errorInfo[1] ?? null) {
            self::DUPLICATE_ENTRY => true,
            self::DEADLOCK => ! $this->insideTransaction(),
            default => false,
        };
    }

    protected function insideTransaction(): bool
    {
        return DB::transactionLevel() > 0;
    }

    /**
     * The unique conversation/product index decides between concurrent
     * first saves. stage_entered_at only moves when the stage changes.
     *
     * @param  array<string, mixed>|null  $context
     */
    private function store(Conversation $conversation, Product $product, SalesFunnel $funnel, string $stage, int $stageInteractions, ?array $context): ConversationFunnelState
    {
        $state = ConversationFunnelState::firstOrCreate(
            ['conversation_id' => $conversation->id, 'product_id' => $product->id],
            ['sales_funnel_id' => $funnel->id, 'stage' => $stage, 'stage_interactions' => $stageInteractions, 'context' => $context],
        );

        if ($state->wasRecentlyCreated) {
            return $state;
        }

        $state->fill(['sales_funnel_id' => $funnel->id, 'stage' => $stage, 'stage_interactions' => $stageInteractions, 'context' => $context]);

        if ($state->isDirty('stage')) {
            $state->stage_entered_at = now();
        }

        $state->save();

        return $state;
    }

    /**
     * Collides with a save like a save with it: see save(). True means the
     * state ended up reset, not that this call removed a row: another process
     * may have removed it just before. False: there was no state to forget.
     */
    public function reset(Conversation $conversation, Product $product): bool
    {
        return $this->withRetries($conversation, $product, fn () => $this->find($conversation, $product)?->delete() ?? false);
    }
}
