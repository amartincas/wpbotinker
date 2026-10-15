<?php

namespace App\Livewire;

use App\Models\Lead;
use App\Models\Conversation;
use App\Models\Store;
use App\Models\WhatsAppMessage;
use App\Services\WhatsAppService;
use App\Services\WhatsAppStatusTracker;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Filament\Notifications\Notification;
use Livewire\Component;

class WhatsAppChatCenter extends Component
{
    // Public properties that Livewire maintains between cycles
    public $conversations = [];
    public $messages = [];
    public ?string $selectedPhone = null;
    public ?int $selectedConversationId = null;
    public bool $botActive = true;
    public string $newMessage = ''; // For text input field
    public ?int $filterStoreId = null; // For superuser store filtering

    // Store the selected conversation belongs to, fixed server-side when it
    // is selected (never set by the client). Sends use it, re-authorized.
    #[Locked]
    public ?int $selectedStoreId = null;
    public $stores = []; // Available stores for superuser filter
    public ?int $selectedLeadId = null; // For JS modal
    public $whatsappTemplates = [];    // List templates
    public $messageStatuses = [];      // Message delivery statuses from cache

    // Unique per page-load id, persisted across every Livewire request for this
    // browser tab. Used to resolve the race between wire:poll and click requests
    // (see setSelectedPhone()/render() below).
    public string $pageToken = '';

    public function mount()
    {
        $this->pageToken = (string) Str::uuid();

        $this->loadConversations();

        // If superuser, load all stores for the filter dropdown
        if (Auth::user()?->is_super_admin) {
            $this->stores = Store::all();
        }
    }

    public function render()
    {
        // Livewire does not serialize concurrent requests for the same component:
        // a wire:poll tick (list refresh every 10s, statuses every 3s) can be
        // in flight at the same moment the user clicks a different conversation.
        // Whichever HTTP response is processed LAST wins, so a slow poll response
        // that was dispatched with the OLD selectedPhone can overwrite a click that
        // already selected a NEW conversation — the chat then appears "stuck" on
        // the previous conversation. The cache entry from selectionCacheKey() is
        // the shared source of truth for "the conversation actually selected in
        // this tab"; every response (poll or click) re-syncs to it before rendering, so they
        // all converge on the same, most-recently-selected conversation regardless
        // of network arrival order.
        if ($this->pageToken !== '') {
            $latestSelectedPhone = Cache::get($this->selectionCacheKey(), $this->selectedPhone);
            if ($latestSelectedPhone !== $this->selectedPhone) {
                $this->selectedPhone = $latestSelectedPhone;
            }
        }

        // Reload conversations on each render to prevent them from disappearing
        $this->loadConversations();

        return view('livewire.whats-app-chat-center');
    }

    /**
     * Set the selected conversation and record it as the authoritative
     * selection for this tab (see render()) so stale concurrent requests
     * (polls dispatched before this selection) can self-correct.
     */
    private function setSelectedPhone(?string $phone): void
    {
        $this->selectedPhone = $phone;
        Cache::put($this->selectionCacheKey(), $phone, now()->addMinutes(30));
    }

    /**
     * The store a conversation with $phone belongs to, for the current
     * user: an operator's own store; a super admin's filtered store; for a
     * super admin without a filter, the phone's store only when exactly one
     * store talks to it. A phone shared by several stores gives null: the
     * super admin must pick the store in the filter.
     */
    private function conversationStoreId(string $phone): ?int
    {
        $user = Auth::user();

        if (!$user?->is_super_admin) {
            return $user?->store_id;
        }

        if ($this->filterStoreId) {
            return $this->filterStoreId;
        }

        $storeIds = WhatsAppMessage::where('customer_phone', $phone)->distinct()->limit(2)->pluck('store_id');

        if ($storeIds->count() > 1) {
            Log::info('Chat Center: phone shared by several stores, store filter required');
        }

        return $storeIds->count() === 1 ? (int) $storeIds->first() : null;
    }

    /**
     * The store a send goes out from: explicitly selected (filter or the
     * selected conversation) and authorized for the current user. Never
     * inferred from a phone's messages at send time.
     */
    private function sendingStore(): ?Store
    {
        $user = Auth::user();

        if (!$user) {
            return null;
        }

        $storeId = $user->is_super_admin
            ? ($this->filterStoreId ?: $this->selectedStoreId)
            : $user->store_id;

        return $storeId ? Store::find($storeId) : null;
    }

    private function selectionCacheKey(): string
    {
        return "whats_app_chat_center_selection:{$this->pageToken}";
    }

    /**
     * Load all conversations (unique customer phones) and messages for selected conversation
     * CRITICAL: Must filter by store_id for multi-tenant safety
     * EXCEPTION: Superusers see all stores (or filtered by $filterStoreId)
     * ORDERS by most recent message for each conversation (newest first)
     */
    public function loadConversations()
    {
        try {
            $isSuperAdmin = Auth::user()?->is_super_admin ?? false;
            
            // Determine which store(s) to query
            if ($isSuperAdmin && $this->filterStoreId) {
                // Superuser with filter applied
                $storeId = $this->filterStoreId;
            } elseif (!$isSuperAdmin) {
                // Regular user - must use their store_id
                $storeId = Auth::user()?->store_id;
                
                if (!$storeId) {
                    Log::warning('loadConversations: store_id is null for non-admin user', [
                        'user_id' => Auth::id(),
                        'user_email' => Auth::user()?->email,
                    ]);
                    $this->conversations = [];
                    return;
                }
            } else {
                // Superuser without filter - see all
                $storeId = null;
            }

            // 1. Load conversations with the date of the last message
            $query = WhatsAppMessage::query();
            
            // Apply store filter if needed
            if ($storeId) {
                $query->where('store_id', $storeId);
            }
            
            $this->conversations = $query
                ->select('customer_phone')
                ->selectRaw('MAX(created_at) as last_message_at')
                ->groupBy('customer_phone')
                ->orderBy('last_message_at', 'DESC')
                ->get();

            Log::debug('loadConversations: Retrieved conversations', [
                'is_super_admin' => $isSuperAdmin,
                'store_id' => $storeId,
                'filter_store_id' => $this->filterStoreId,
                'count' => count($this->conversations),
            ]);

            // 2. If a phone is selected, load its messages
            if ($this->selectedPhone) {
                $messageQuery = WhatsAppMessage::query()
                    ->where('customer_phone', (string) $this->selectedPhone);
                
                // Apply store filter if needed
                if ($storeId) {
                    $messageQuery->where('store_id', $storeId);
                }
                
                $queryMessages = $messageQuery
                    ->orderBy('created_at', 'asc')
                    ->get();
                
                // Comparar por IDs, no solo por cantidad — dos conversaciones
                // distintas pueden tener la misma cantidad de mensajes por
                // coincidencia, y comparar solo el conteo dejaba pegados los
                // mensajes de la conversación anterior al cambiar de chat.
                $newIds = $queryMessages->pluck('id')->all();
                $currentIds = collect($this->messages)->pluck('id')->all();

                if ($newIds !== $currentIds) {
                    $this->messages = $queryMessages;
                    $this->dispatch('scroll-down');

                    Log::debug('loadConversations: Messages updated', [
                        'customer_phone' => $this->selectedPhone,
                        'message_count' => count($queryMessages),
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::error('loadConversations Error: ' . $e->getMessage(), [
                'user_id' => Auth::id(),
                'is_super_admin' => Auth::user()?->is_super_admin,
                'store_id' => Auth::user()?->store_id,
                'filter_store_id' => $this->filterStoreId,
                'selected_phone' => $this->selectedPhone,
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Select a conversation and load its details
     * Fetches bot_active status from leads table (works for both control records and marketing leads)
     * For superusers, determine the store_id of the conversation
     */
    public function selectConversation(string $phone): void
    {
        $this->setSelectedPhone((string) $phone);
        $this->loadConversations(); // Force message load

        // Fetch message statuses for this conversation
        $this->getMessageStatuses();

        $storeId = $this->conversationStoreId((string) $phone);
        $this->selectedStoreId = $storeId;

        if ($storeId) {
            // Check if a control record or marketing lead exists for this phone
            $lead = Lead::query()
                ->where('store_id', $storeId)
                ->where('customer_phone', (string) $phone)
                ->first();

            // Store selected conversation id if one exists
            $conversation = Conversation::query()
                ->where('store_id', $storeId)
                ->where('customer_phone', (string) $phone)
                ->first();

            $this->selectedConversationId = $conversation?->id;
            // Fetch bot_active status, default to true if no record exists yet
            $this->botActive = $lead?->bot_active ?? true;
            // Store selected lead ID for JS modal (can be null if no lead record exists yet)
            $this->selectedLeadId = $lead?->id;
            // Load WhatsApp templates for this store (for manual template sending)
            $this->whatsappTemplates = \App\Models\WhatsAppTemplate::where('store_id', $storeId)->get();
        } else {
            $this->selectedConversationId = null;
        }

        // Important for JavaScript
        $this->loadConversations();
        $this->dispatch('scroll-down');
    }

    /**
     * Send manual message to customer
     * Saves to database and sends via WhatsAppService
     * 
     * IMPORTANT: Human operator messages are saved with role='assistant' (not 'user').
     * This ensures that when the bot re-enables, it can see and maintain context
     * from everything the human operator said during the intervention period.
     * 
     * Message flow:
     * 1. Operator types message → Saved to DB with role='assistant'
     * 2. Message sent to customer via WhatsApp API
     * 3. Customer replies → ProcessWhatsAppMessage job fetches history
     * 4. History includes operator's previous messages (role='assistant')
     * 5. AI engine receives full context including what operator said
     * 6. Bot continues conversation with full context awareness
     */
    public function sendMessage(): void
    {
        if (empty(trim($this->newMessage)) || !$this->selectedPhone) {
            return;
        }

        $storeId = $this->sendingStore()?->id;

        if (!$storeId) {
            Log::warning('sendMessage: no explicit, authorized store selected');
            $this->dispatch('store-selection-required');
            return;
        }

        try {
            // 1. Save message to database (sent by operator)
            // CRITICAL: role='assistant' ensures bot maintains context when re-enabled
            $message = WhatsAppMessage::create([
                'store_id' => $storeId,
                'customer_phone' => $this->selectedPhone,
                'content' => $this->newMessage,
                'role' => 'assistant', // ← Operator message treated as 'assistant' for AI context
            ]);

            // 2. Send via WhatsApp API
            $store = Store::find($storeId);
            if ($store) {
                // Process [IMG:id] tags: sends product images (each tracked in its own
                // whatsapp_messages row, see processAIResponse()) and strips the tags
                $textToSend = WhatsAppService::processAIResponse($this->newMessage, $store, $this->selectedPhone);

                // Only send a text message if something remains after stripping [IMG:...] tags
                $wamid = null;
                if (trim($textToSend) !== '') {
                    $wamid = WhatsAppService::sendMessage($this->selectedPhone, $textToSend, $store);
                }

                // 3. Track message status if WAMID was returned
                if ($wamid) {
                    WhatsAppStatusTracker::trackMessage($message->id, $wamid);
                    
                    Log::info('Message status tracking initiated', [
                        'db_message_id' => $message->id,
                        'wamid' => $wamid,
                    ]);
                }
                
                Log::info('Manual message sent via WhatsApp', [
                    'store_id' => $storeId,
                    'customer_phone' => $this->selectedPhone,
                    'message_length' => strlen($this->newMessage),
                    'role' => 'assistant',  // Log that this will be in AI context
                    'wamid' => $wamid,
                ]);
            } else {
                Log::warning('sendMessage: Store not found', ['store_id' => $storeId]);
            }

            // 4. Clear input and refresh
            $this->newMessage = '';
            $this->loadConversations();
            
            // 5. Scroll down
            $this->dispatch('scroll-down');
        } catch (\Exception $e) {
            Log::error('sendMessage: Failed to send message', [
                'store_id' => $storeId,
                'customer_phone' => $this->selectedPhone,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle bot_active toggle changes
     * Creates bot control records in the leads table to decouple bot control from marketing leads
     * 
     * IMPORTANT: The "leads" table serves TWO purposes:
     * 1. Bot Control Records: Any phone where operator toggled bot on/off (customer_name = 'Unknown')
     * 2. Marketing Leads: Completed conversations with customer data (customer_name = actual name)
     * 
     * This decoupling allows operators to disable bots for ANY conversation,
     * even if the customer hasn't provided their data yet (not a "lead" in marketing sense).
     */
    public function updatedBotActive(bool $value): void
    {
        if (!$this->selectedPhone || !Auth::user()?->store_id) {
            Log::warning('updatedBotActive: Missing phone or store_id');
            return;
        }

        try {
            $storeId = Auth::user()->store_id;
            
            // IMPORTANT: Cast value to boolean and log exactly what we're saving
            $botActiveValue = (bool) $value;
            
            Log::info('updatedBotActive: Toggle switch changed', [
                'store_id' => $storeId,
                'customer_phone' => $this->selectedPhone,
                'raw_value' => $value,
                'cast_value' => $botActiveValue,
                'value_type' => gettype($value),
            ]);
            
            // Create or update control record in leads table
            // If this phone doesn't have a record yet, this creates a "bot control record"
            // identified by customer_name = 'Unknown'
            $lead = Lead::updateOrCreate(
                [
                    'store_id' => $storeId,
                    'customer_phone' => (string) $this->selectedPhone,
                ],
                [
                    'customer_name' => 'Unknown', // Identifies as control record, not a marketing lead
                    'summary' => 'WhatsApp Chat Center',
                    'bot_active' => $botActiveValue,  // EXPLICIT: Pass the cast boolean value
                ]
            );

            Log::info('Bot Active status toggled (control record)', [
                'store_id' => $storeId,
                'customer_phone' => $this->selectedPhone,
                'bot_active_saved' => $lead->bot_active,
                'database_value' => (bool) $lead->bot_active,
            ]);
        } catch (\Exception $e) {
            Log::error('updatedBotActive: Failed to update status', [
                'customer_phone' => $this->selectedPhone,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Handle store filter change for superusers
     * Clears selected phone and reloads conversations with new store filter
     */
    public function updatedFilterStoreId($value): void
    {
        // Livewire assigns the property before this hook runs, so a
        // non-admin's value must be discarded here, not just ignored.
        if (!Auth::user()?->is_super_admin) {
            $this->filterStoreId = null;

            return;
        }

        $this->filterStoreId = (int) $value ?: null;
        $this->setSelectedPhone(null);
        $this->selectedStoreId = null;
        $this->messages = [];
        $this->loadConversations();
        
        Log::info('Store filter changed by superuser', [
            'user_id' => Auth::id(),
            'filter_store_id' => $this->filterStoreId,
        ]);
    }

    public function sendTemplate(int $templateId, array $customValues = [], ?string $externalPhone = null): void
    {
        $targetPhone = $this->selectedPhone;
        $isExternalSend = false;

        if (!empty($externalPhone)) {
            $normalized = preg_replace('/\s+/', '', $externalPhone);
            if (!preg_match('/^\+?\d{6,15}$/', $normalized)) {
                Log::warning('sendTemplate: invalid external phone format', ['external_phone' => $externalPhone]);
                $this->dispatch('template-sent-error');
                return;
            }

            $targetPhone = ltrim($normalized, '+');
            $isExternalSend = true;
        }

        $store = $this->sendingStore();

        if (!$store) {
            Log::warning('sendTemplate: no explicit, authorized store selected', ['is_external_send' => $isExternalSend]);
            $this->dispatch('template-sent-error');
            return;
        }

        $storeId = $store->id;

        $template = \App\Models\WhatsAppTemplate::where('id', $templateId)
            ->where('store_id', $storeId)
            ->firstOrFail();

        if ($template->requires_phone_input && !$isExternalSend) {
            Log::warning('sendTemplate: template requires external phone but none was provided', ['template_id' => $templateId]);
            $this->dispatch('template-sent-error');
            return;
        }

        $targetPhone = $targetPhone ? (string) $targetPhone : null;
        if (!$targetPhone) {
            Log::warning('sendTemplate: target phone is missing', ['template_id' => $templateId]);
            $this->dispatch('template-sent-error');
            return;
        }

        $lead = Lead::where('customer_phone', $targetPhone)
            ->where('store_id', $storeId)
            ->first();

        if ($isExternalSend) {
            $lead = Lead::firstOrCreate(
                ['store_id' => $storeId, 'customer_phone' => $targetPhone],
                ['customer_name' => 'Unknown', 'summary' => 'Proactive message', 'bot_active' => false]
            );
        }

        $parametersMap = $template->parameters_map ?? [];
        $leadData = [
            'customer_name'  => $lead?->customer_name  ?? '',
            'customer_phone' => $lead?->customer_phone ?? '',
            'product_service_name'   => $lead?->product_service_name   ?? '',
        ];

        // Metadata only: the variable values and the lead data carry the
        // customer's personal data (name, tracking number...).
        Log::info('DEBUG sendTemplate', [
            'store_id' => $storeId,
            'template_id' => $template->id,
            'selected_phone' => $this->selectedPhone,
            'external_phone' => $externalPhone,
            'target_phone' => $targetPhone,
            'custom_value_count' => count($customValues),
            'parametersMap' => $parametersMap,
            'lead_id' => $lead?->id,
        ]);

        $resolvedValues = [];
        foreach ($parametersMap as $position => $fieldKey) {
            $idx = (int) $position - 1;
            $override = $customValues[$idx] ?? null;
            $resolvedValues[$idx] = ($override !== '' && $override !== null)
                ? $override
                : ($leadData[$fieldKey] ?? '');
        }
        ksort($resolvedValues);

        Log::info('DEBUG resolvedValues', ['resolved_value_count' => count($resolvedValues)]);

        $conversation = Conversation::firstOrCreate([
            'store_id' => $storeId,
            'customer_phone' => $targetPhone,
        ], [
            'last_session_at' => now(),
        ]);

        $this->selectedConversationId = $conversation->id;
        $this->setSelectedPhone($targetPhone);

        $sent = WhatsAppService::sendTemplateMessage(
            to:           $targetPhone,
            templateName: $template->name,
            languageCode: $template->language,
            variables:    array_values($resolvedValues),
            store:        $store,
        );

        if ($sent) {
            $renderedBody = $template->body_preview;
            foreach (array_values($resolvedValues) as $index => $value) {
                $placeholder = '{{'. ($index + 1). '}}';
                $renderedBody = str_replace($placeholder, $value, $renderedBody);
            }

            $message = WhatsAppMessage::create([
                'store_id'       => $storeId,
                'customer_phone' => $targetPhone,
                'content'        => $renderedBody,
                'role'           => 'assistant',
            ]);

            // Track message status if WAMID was returned
            if ($sent) {
                WhatsAppStatusTracker::trackMessage($message->id, $sent);
                
                Log::info('Template message status tracking initiated', [
                    'db_message_id' => $message->id,
                    'wamid' => $sent,
                ]);
            }

            Notification::make()
                ->title('Plantilla enviada')
                ->body('El mensaje fue enviado correctamente al cliente.')
                ->success()
                ->send();

            if ($template->is_reengagement && $lead) {
                $lead->update(['status' => 'waiting_customer', 'bot_active' => true]);
            }

            // Shipping-notification templates for this store are built with
            // "Guía"/"Transportadora" fields (see parameters_map on the
            // template) — reusing that existing convention lets us detect
            // "this send is a shipment notification" and record the order's
            // tracking info automatically, with no new template config.
            if ($lead) {
                $guiaPosition = array_search('Guía', $parametersMap, true);

                if ($guiaPosition !== false) {
                    $transportadoraPosition = array_search('Transportadora', $parametersMap, true);
                    $trackingIdx = (int) $guiaPosition - 1;
                    $carrierIdx = $transportadoraPosition !== false ? (int) $transportadoraPosition - 1 : null;

                    $lead->update([
                        'order_status' => 'enviado',
                        'tracking_number' => $resolvedValues[$trackingIdx] ?? null,
                        'carrier' => $carrierIdx !== null ? ($resolvedValues[$carrierIdx] ?? null) : $lead->carrier,
                    ]);
                }
            }

            $this->selectConversation($targetPhone);
        } else {
            Notification::make()
                ->title('Error al enviar')
                ->body('Meta no pudo entregar la plantilla. Revisa los logs.')
                ->danger()
                ->send();
        }
    }

    // Este método se llamará desde el script del modal tras un envío exitoso
    #[On('template-sent')] 
    public function handleTemplateSent()
    {
        $this->loadConversations();
        $this->dispatch('scroll-down');
        Log::info('Chat refreshed after template send', ['phone' => $this->selectedPhone]);
    }

    /**
     * Get delivery statuses for all messages in current conversation
     * Called by Livewire polling to fetch current message statuses from cache
     * 
     * @return void - Updates $messageStatuses property
     */
    public function getMessageStatuses(): void
    {
        if (!$this->selectedPhone) {
            $this->messageStatuses = [];
            return;
        }

        // Get all message IDs from the current conversation
        $messageIds = WhatsAppMessage::where('customer_phone', $this->selectedPhone)
            ->pluck('id')
            ->toArray();

        // Fetch all statuses from cache
        $this->messageStatuses = WhatsAppStatusTracker::getMultipleStatuses($messageIds);

        Log::debug('Message statuses fetched', [
            'customer_phone' => $this->selectedPhone,
            'message_count' => count($messageIds),
            'tracked_count' => count($this->messageStatuses),
        ]);
    }

}