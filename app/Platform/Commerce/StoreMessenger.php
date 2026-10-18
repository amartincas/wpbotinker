<?php

namespace App\Platform\Commerce;

use App\Models\Conversation;
use App\Models\Store;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppTemplate;
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\Log;

/**
 * Messages the system starts itself (receipt alerts to the store admin,
 * review results to the customer), always from the store's own number.
 * Adapted from WpBotTrainer's CustomerNotifier: free text while WhatsApp's
 * 24-hour window is open (the recipient wrote to this store recently),
 * otherwise the store's approved template for the event. Never throws:
 * a failed send returns null and is logged without the message content.
 */
class StoreMessenger
{
    /** Conservative margin under Meta's 24 hours. */
    private const WINDOW_MINUTES = 23 * 60 + 30;

    /** Sent instead of an empty template parameter, which Meta rejects. */
    private const EMPTY_PARAMETER = '-';

    /**
     * @param  array<string, string>  $variables  template parameters by name (parameters_map)
     * @return string|null the WhatsApp message id
     */
    public function send(Store $store, string $to, string $text, string $eventKey, array $variables, ?string $domainKey = null): ?string
    {
        try {
            $windowOpen = $this->windowOpen($store, $to);
            $template = $windowOpen ? null : WhatsAppTemplate::where('store_id', $store->id)->where('event_key', $eventKey)->first();

            if (! $windowOpen && $template === null) {
                Log::warning('STORE_TEMPLATE_NOT_CONFIGURED', ['store_id' => $store->id, 'event_key' => $eventKey]);
            }

            $wamid = $template
                ? WhatsAppService::sendTemplateMessage(
                    to: $to,
                    templateName: $template->name,
                    languageCode: $template->language,
                    variables: $this->ordered($template, $variables),
                    store: $store,
                )
                // Inside the window; or no template configured: best effort.
                : WhatsAppService::sendMessage($to, $text, $store);

            if ($wamid !== null) {
                WhatsAppMessage::create([
                    'store_id' => $store->id, 'customer_phone' => $to, 'role' => 'assistant', 'domain_key' => $domainKey,
                    'content' => $text, 'wamid' => $wamid, 'status' => 'pending', 'status_updated_at' => now(),
                ]);
            }

            Log::info('STORE_MESSAGE_SENT', ['store_id' => $store->id, 'event_key' => $eventKey, 'channel' => $template ? 'template' : 'text', 'success' => $wamid !== null]);

            return $wamid;
        } catch (\Throwable $e) {
            Log::error('STORE_MESSAGE_FAILED', ['store_id' => $store->id, 'event_key' => $eventKey, 'error' => $e::class]);

            return null;
        }
    }

    /** The recipient wrote to this store within the window (conversations.last_session_at is set on every inbound message). */
    public function windowOpen(Store $store, string $to): bool
    {
        $lastInbound = Conversation::where('store_id', $store->id)->where('customer_phone', $to)->value('last_session_at');

        return $lastInbound !== null && now()->diffInMinutes($lastInbound, true) < self::WINDOW_MINUTES;
    }

    /**
     * @return list<string>
     */
    private function ordered(WhatsAppTemplate $template, array $variables): array
    {
        $resolved = [];

        foreach ($template->parameters_map ?? [] as $position => $name) {
            // Meta rejects an empty parameter (e.g. no expiry date on a rejection).
            $value = trim((string) ($variables[$name] ?? ''));
            $resolved[(int) $position] = $value === '' ? self::EMPTY_PARAMETER : $value;
        }

        ksort($resolved);

        return array_values($resolved);
    }
}
