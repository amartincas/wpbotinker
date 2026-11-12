<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Product;
use App\Models\Store;
use App\Models\WhatsAppMessage;
use App\Platform\Commerce\AccessDecision;
use App\Platform\Commerce\AccessGate;
use App\Platform\Commerce\StoreMessenger;
use App\Platform\Funnel\GuidedFunnel;
use App\Platform\Funnel\SalesFunnelVersions;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One message to a prospect who stopped answering: the literal text of the
 * active guided funnel of the conversation's product, once its hours of
 * silence have passed, and only within the allowed hours (allowedAt()): one
 * due during the quiet hours goes at their end. No model is called.
 *
 * An episode is the conversation's last_session_at when the job was queued
 * (the webhook sets it on every inbound message, before any processing). A
 * newer message changes it, so every earlier job finds it different and
 * sends nothing. Claiming the episode (reactivation_episode_at, under a row
 * lock) happens before the send, so two jobs of one episode send once; the
 * claim is not a success: a sent reactivation is its whatsapp_messages row,
 * which exists only with the id WhatsApp returned. A failed send is logged
 * and never retried.
 *
 * Only for prospects without a subscription (NO_SUBSCRIPTION), with no open
 * payment, a free reply left (the message may invite to use it), no human
 * operator, nothing pending, no refusal in this episode and no stop request
 * ever. It reads nothing else and changes nothing else.
 */
class SendProspectReactivation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    private const TIMESTAMP = GuidedFunnel::EPISODE_FORMAT;

    private const EVENT_KEY = 'prospect_reactivation';

    public function __construct(
        public readonly int $storeId,
        public readonly string $phone,
        public readonly string $episode,
        public readonly bool $rescheduled = false,
    ) {}

    /**
     * Queues the reactivation of the conversation's current episode, if its
     * product's active guided funnel configures one. Never throws: the reply
     * already went out, and must not be retried because of this.
     */
    public static function scheduleFor(Store $store, string $phone): void
    {
        try {
            $conversation = Conversation::where('store_id', $store->id)->where('customer_phone', $phone)->first();
            $product = $conversation?->current_product_id ? Product::find($conversation->current_product_id) : null;
            $reactivation = $product ? self::configured($product) : null;

            if ($reactivation === null || $conversation->last_session_at === null || $conversation->reactivation_opted_out_at !== null) {
                return;
            }

            self::dispatch($store->id, $phone, self::stamp($conversation->last_session_at))
                ->delay(self::dueAt($conversation->last_session_at, $reactivation['after_hours']));
        } catch (Throwable $e) {
            Log::error('REACTIVATION_SCHEDULE_FAILED', ['store_id' => $store->id, 'error' => $e::class]);
        }
    }

    /**
     * Never alongside the processing of a message of the same customer: that
     * message already ended this episode, so the job is dropped, not delayed.
     * The lock is ProcessWhatsAppMessage's own: its key carries that class.
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("whatsapp-message:{$this->storeId}:{$this->phone}"))
                ->withPrefix('laravel-queue-overlap:'.ProcessWhatsAppMessage::class.':')
                ->shared()
                ->dontRelease()
                ->expireAfter(120),
        ];
    }

    public function handle(AccessGate $access, StoreMessenger $messenger): void
    {
        $store = Store::find($this->storeId);
        $conversation = Conversation::where('store_id', $this->storeId)->where('customer_phone', $this->phone)->first();

        if ($store === null || $conversation === null || self::stamp($conversation->last_session_at) !== $this->episode) {
            $this->skip('newer_message');

            return;
        }

        $product = $conversation->current_product_id ? Product::find($conversation->current_product_id) : null;
        $reactivation = $product ? self::configured($product) : null;

        if ($reactivation === null) {
            $this->skip('disabled', $product);

            return;
        }

        $due = self::dueAt($conversation->last_session_at, $reactivation['after_hours']);

        if (now()->lt($due)) {
            // The hours were raised after it was queued: once more, at the new time.
            $this->rescheduled ? $this->skip('not_due', $product) : self::dispatch($this->storeId, $this->phone, $this->episode, true)->delay($due);

            return;
        }

        // Run late, into the quiet hours (a worker that waited, a day that changed): never now. Once more at the
        // window's opening, where everything below is checked again.
        $now = now();

        if (! self::allowedAt($now)->equalTo($now)) {
            $this->rescheduled ? $this->skip('quiet_hours', $product) : self::dispatch($this->storeId, $this->phone, $this->episode, true)->delay(self::allowedAt($now));

            return;
        }

        $this->recordStopRequest($conversation);

        if ($reason = $this->ineligibility($store, $conversation->refresh(), $product, $access, $messenger)) {
            $this->skip($reason, $product);

            return;
        }

        if (! $this->claim($conversation)) {
            $this->skip('already_claimed', $product);

            return;
        }

        $wamid = $messenger->sendInWindow($store, $this->phone, $reactivation['message'], self::EVENT_KEY, $product->domain_key);

        Log::info($wamid !== null ? 'REACTIVATION_SENT' : 'REACTIVATION_FAILED', [
            'store_id' => $this->storeId,
            'conversation_id' => $conversation->id,
            'product_id' => $product->id,
            'wamid' => $wamid,
        ]);
    }

    /** The configured hours of silence after the episode, moved into the allowed hours when they end outside them. */
    public static function dueAt(CarbonInterface $episode, int $afterHours): CarbonInterface
    {
        return self::allowedAt($episode->copy()->addHours($afterHours));
    }

    /**
     * $at itself when it falls within the reactivation window (config
     * sales_funnels.guided.reactivation_window: from its opening until its
     * close, excluded, in its timezone), otherwise the window's next opening.
     * In $at's own timezone: only the window is read in the configured one, so
     * stored times keep the application's. Without a valid window, $at.
     */
    public static function allowedAt(CarbonInterface $at): CarbonInterface
    {
        $window = (array) config('botinker.sales_funnels.guided.reactivation_window', []);
        $from = self::clock($window['from'] ?? null);
        $until = self::clock($window['until'] ?? null);

        try {
            $local = $at->copy()->setTimezone((string) ($window['timezone'] ?? ''));
        } catch (Throwable) {
            return $at;
        }

        if ($from === null || $until === null || $from >= $until) {
            return $at;
        }

        $minute = $local->hour * 60 + $local->minute;
        $opening = $local->copy()->startOfDay()->addMinutes($from);

        return match (true) {
            $minute < $from => $opening->setTimezone($at->getTimezone()),
            $minute >= $until => $opening->addDay()->setTimezone($at->getTimezone()),
            default => $at,
        };
    }

    /** "HH:MM" as minutes since midnight (24:00 is the end of the day), or null. */
    private static function clock(mixed $time): ?int
    {
        if (! is_string($time) || preg_match('/^([01]\d|2[0-4]):([0-5]\d)$/', $time, $match) !== 1 || ((int) $match[1] === 24 && (int) $match[2] !== 0)) {
            return null;
        }

        return (int) $match[1] * 60 + (int) $match[2];
    }

    /** @return array{message: string, after_hours: int}|null */
    private static function configured(Product $product): ?array
    {
        if (! $product->store?->usesKnowledgeMode()) {
            return null;
        }

        $active = app(SalesFunnelVersions::class)->active($product);

        return $active ? GuidedFunnel::reactivation($active->definition ?? []) : null;
    }

    /**
     * A stop request in any message since the last claimed episode: earlier
     * ones were read when that episode was claimed.
     */
    private function recordStopRequest(Conversation $conversation): void
    {
        if ($conversation->reactivation_opted_out_at !== null) {
            return;
        }

        $messages = WhatsAppMessage::where('store_id', $this->storeId)
            ->where('customer_phone', $this->phone)
            ->where('role', 'user')
            ->when($conversation->reactivation_episode_at, fn ($query, $since) => $query->where('created_at', '>=', $since))
            ->pluck('content');

        if ($messages->contains(fn ($content) => GuidedFunnel::asksToStop((string) $content))) {
            Conversation::whereKey($conversation->id)->whereNull('reactivation_opted_out_at')->update(['reactivation_opted_out_at' => now()]);
        }
    }

    private function ineligibility(Store $store, Conversation $conversation, Product $product, AccessGate $access, StoreMessenger $messenger): ?string
    {
        if ($conversation->reactivation_opted_out_at !== null) {
            return 'opted_out';
        }

        // The customer refused or showed frustration in this episode (GuidedFunnel::declineEpisode()): this one ends here.
        if (GuidedFunnel::episodeDeclined($this->storeId, $this->phone, $this->episode)) {
            return 'declined_episode';
        }

        if (Lead::where('store_id', $this->storeId)->where('customer_phone', $this->phone)->where('bot_active', false)->exists()) {
            return 'human';
        }

        if ($conversation->pending_state !== null) {
            return 'pending_state';
        }

        $customer = Customer::where('store_id', $this->storeId)->where('customer_phone', $this->phone)->first();

        if ($customer === null) {
            return 'no_customer';
        }

        $decision = $access->decide($customer, $product);

        if ($decision->allowed || $decision->reason !== AccessDecision::NO_SUBSCRIPTION) {
            return $decision->reason;
        }

        if ($decision->openPayment !== null) {
            return 'open_payment';
        }

        // The reactivation may invite to ask a free question: never to who has none left.
        if (! $decision->sampleAvailable) {
            return 'no_free_reply';
        }

        $last = WhatsAppMessage::where('store_id', $this->storeId)
            ->where('customer_phone', $this->phone)
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->first();

        if ($last?->role !== 'assistant') {
            return 'not_answered';
        }

        $lastFromCustomer = WhatsAppMessage::where('store_id', $this->storeId)
            ->where('customer_phone', $this->phone)
            ->where('role', 'user')
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->value('content');

        if ($lastFromCustomer !== null && GuidedFunnel::declines($lastFromCustomer)) {
            return 'declined';
        }

        return $messenger->windowOpen($store, $this->phone) ? null : 'window_closed';
    }

    /** Takes this episode for this job alone, if no newer message arrived meanwhile. */
    private function claim(Conversation $conversation): bool
    {
        return DB::transaction(function () use ($conversation) {
            $current = Conversation::whereKey($conversation->id)->lockForUpdate()->first();

            if ($current === null
                || self::stamp($current->last_session_at) !== $this->episode
                || self::stamp($current->reactivation_episode_at) === $this->episode) {
                return false;
            }

            Conversation::whereKey($conversation->id)->update(['reactivation_episode_at' => $this->episode]);

            return true;
        });
    }

    private function skip(string $reason, ?Product $product = null): void
    {
        Log::info('REACTIVATION_SKIPPED', [
            'store_id' => $this->storeId,
            'product_id' => $product?->id,
            'reason' => $reason,
        ]);
    }

    private static function stamp(?CarbonInterface $at): ?string
    {
        return $at?->format(self::TIMESTAMP);
    }
}
