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
    /** The kinds of read a commercial turn may take (takeRead()). */
    public const READ = 'read';

    public const GRACE = 'grace';

    public const REACTIVATION = 'reactivation';

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
     * Takes one of the conversation's model reads after its free replies, under
     * the row's lock: however many messages arrive at once, no more than $max
     * are taken. A retry of the same inbound message keeps the read it took.
     *
     * @return bool whether this message may call the model
     */
    public function takeLimitRead(Conversation $conversation, Product $product, SalesFunnel $funnel, string $stage, ?string $wamid, int $max): bool
    {
        return $this->takeRead($conversation, $product, $funnel, $stage, $wamid, $max) !== null;
    }

    /**
     * Takes a read for a commercial turn, under the row's lock, and says which:
     *
     * - READ: one of the $max reads of the conversation;
     * - GRACE: once those ran out, a read to classify the message while free
     *   replies are left, at most $grace of them (the free replies left) kept
     *   apart; it lets the message be read, it is no free reply;
     * - REACTIVATION: once both ran out, one read per reactivation sent
     *   ($reactivationEpisode), so its invitation to ask can be kept;
     * - null: none, the message is not read.
     *
     * A retry of the same inbound message keeps the read it took.
     */
    public function takeRead(Conversation $conversation, Product $product, SalesFunnel $funnel, string $stage, ?string $wamid, int $max, int $grace = 0, ?string $reactivationEpisode = null): ?string
    {
        return $this->withRetries($conversation, $product, function () use ($conversation, $product, $funnel, $stage, $wamid, $max, $grace, $reactivationEpisode) {
            $id = ConversationFunnelState::firstOrCreate(
                ['conversation_id' => $conversation->id, 'product_id' => $product->id],
                ['sales_funnel_id' => $funnel->id, 'stage' => $stage, 'stage_interactions' => 0],
            )->id;

            return DB::transaction(function () use ($id, $wamid, $max, $grace, $reactivationEpisode) {
                $state = ConversationFunnelState::whereKey($id)->lockForUpdate()->first();

                if ($state === null) {
                    return null;
                }

                $context = $state->context ?? [];

                foreach ([self::READ => 'limit_read_wamids', self::GRACE => 'grace_read_wamids', self::REACTIVATION => 'reactivation_read_wamids'] as $kind => $key) {
                    if ($wamid !== null && in_array($wamid, $context[$key] ?? [], true)) {
                        return $kind;
                    }
                }

                $kind = match (true) {
                    (int) ($context['limit_reads'] ?? 0) < $max => self::READ,
                    (int) ($context['grace_reads'] ?? 0) < $grace => self::GRACE,
                    $reactivationEpisode !== null && ($context['reactivation_read_episode'] ?? null) !== $reactivationEpisode => self::REACTIVATION,
                    default => null,
                };

                if ($kind === null) {
                    return null;
                }

                [$counter, $key] = match ($kind) {
                    self::READ => ['limit_reads', 'limit_read_wamids'],
                    self::GRACE => ['grace_reads', 'grace_read_wamids'],
                    self::REACTIVATION => [null, 'reactivation_read_wamids'],
                };

                if ($counter !== null) {
                    $context[$counter] = (int) ($context[$counter] ?? 0) + 1;
                } else {
                    $context['reactivation_read_episode'] = $reactivationEpisode;
                }

                $context[$key] = $wamid !== null ? [...($context[$key] ?? []), $wamid] : ($context[$key] ?? []);

                $state->update(['context' => $context]);

                return $kind;
            });
        });
    }

    /**
     * Gives back the read a message took, when its turn became a free reply:
     * free replies have their own limit. The message's id goes too, so a
     * retry takes and gives back its read again instead of keeping it. A
     * reactivation's read is kept: it was granted for that reactivation, and
     * its free reply kept the invitation.
     */
    public function releaseLimitRead(Conversation $conversation, Product $product, ?string $wamid, string $kind = self::READ): void
    {
        if ($kind === self::REACTIVATION) {
            return;
        }

        [$counter, $key] = $kind === self::GRACE ? ['grace_reads', 'grace_read_wamids'] : ['limit_reads', 'limit_read_wamids'];

        $this->withRetries($conversation, $product, function () use ($conversation, $product, $wamid, $counter, $key) {
            DB::transaction(function () use ($conversation, $product, $wamid, $counter, $key) {
                $state = ConversationFunnelState::where('conversation_id', $conversation->id)->where('product_id', $product->id)->lockForUpdate()->first();

                if ($state === null) {
                    return;
                }

                $context = $state->context ?? [];
                $context[$counter] = max(0, (int) ($context[$counter] ?? 0) - 1);
                $context[$key] = array_values(array_filter($context[$key] ?? [], fn ($id) => $id !== $wamid));

                $state->update(['context' => $context]);
            });
        });
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

        // Without a new context, the one it has stays: a save must not forget its counters.
        $state->fill(['sales_funnel_id' => $funnel->id, 'stage' => $stage, 'stage_interactions' => $stageInteractions, 'context' => $context ?? $state->context]);

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
