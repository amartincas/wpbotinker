<?php

namespace App\Jobs;

use App\Models\Store;
use App\Models\WhatsAppMessage;
use App\Models\Product;
use App\Models\Conversation;
use App\Models\Lead;
use App\Factories\AIServiceFactory;
use App\Services\AI\OpenAIService;
use App\Services\WhatsAppService;
use App\Services\WhatsAppStatusTracker;
use App\Services\Inventory\ProductFinderService;
use App\Platform\Conversation\ConversationPipeline;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessWhatsAppMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Real failures (exceptions) allowed before the job is given up.
     *
     * Deliberately NOT $tries: every WithoutOverlapping release (see
     * middleware()) counts as an attempt, so with $tries = 3 a message that
     * waited three times for the previous message of the same customer was
     * failed without ever running — silently unanswered. Waiting is bounded
     * by retryUntil() instead; only real exceptions count here.
     */
    public int $maxExceptions = 3;

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(10);
    }

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Store $store,
        public string $from,
        public ?string $messageBody,
        public ?string $phoneId,
        public ?string $messageType = null,
        public ?string $mediaId = null,
        public ?int $productContext = null,
    ) {}

    /**
     * Ensure only one message for a given customer conversation is processed
     * at a time. Without this, two near-simultaneous messages (e.g. the
     * customer double-clicking the same Click-to-WhatsApp ad, or a queue
     * retry racing a still-running attempt) both read the conversation
     * history before either saves its turn — each one replies as if it were
     * the first message, producing duplicate/incoherent answers. Serializing
     * per store+phone makes the second job wait and see the updated history.
     *
     * releaseAfter() puts the second job back on the queue to retry shortly
     * instead of dropping it, so it still gets answered — just after the
     * first one finishes.
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("whatsapp-message:{$this->store->id}:{$this->from}"))
                ->releaseAfter(5)
                ->expireAfter(120),
        ];
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        //sleep(30);

        try {
            // Verify store object is properly serialized
            if (!$this->store || !$this->store->id) {
                Log::error('CRITICAL: Store object is null or missing ID in job', [
                    'has_store' => $this->store !== null,
                    'store_id' => $this->store->id ?? 'NULL',
                ]);
                throw new \Exception('Store object is not properly initialized in job');
            }

            // ===== HUMAN INTERVENTION MODE CHECK (FIRST THING) =====
            // CRITICAL: This check must happen BEFORE ANY AI PROCESSING
            // 
            // IMPORTANT: The "leads" table serves TWO purposes:
            // 1. Bot Control Records: Any phone where operator toggled bot on/off
            // 2. Marketing Leads: Completed conversations with customer data
            //
            // This check decouples bot control from lead status:
            // - If NO lead record exists → Bot continues (default behavior)
            // - If lead exists AND bot_active = false → Bot disabled for human intervention
            // - If lead exists AND bot_active = true → Bot continues
            
            $botDisabled = Lead::where('store_id', $this->store->id)
                ->where('customer_phone', $this->from)
                ->where('bot_active', false)
                ->exists();

            if ($botDisabled) {
                Log::warning('BOT_DISABLED: Skipping AI response', [
                    'store_id' => $this->store->id,
                    'customer_phone' => $this->from,
                    'message_length' => mb_strlen($this->messageBody ?? ''),
                ]);

                // Still save the user message for reference
                WhatsAppMessage::create([
                    'store_id' => $this->store->id,
                    'customer_phone' => $this->from,
                    'role' => 'user',
                    'content' => $this->messageBody,
                ]);

                return;
            }
            // ===== END HUMAN INTERVENTION MODE CHECK =====

            Log::info("JOB_START: Processing WhatsApp message", [
                'store_id' => $this->store->id,
                'store_name' => $this->store->name,
                'customer_phone' => $this->from,
                'message_length' => mb_strlen($this->messageBody ?? ''),
                'message_type' => $this->messageType,
                'media_id' => $this->mediaId,
            ]);

            if (in_array($this->messageType, ['audio', 'voice'], true) && empty($this->messageBody)) {
                Log::info('Audio/voice message detected, starting transcription workflow', [
                    'store_id' => $this->store->id,
                    'customer_phone' => $this->from,
                    'message_type' => $this->messageType,
                    'media_id' => $this->mediaId,
                ]);

                if (!$this->mediaId) {
                    Log::warning('Audio message missing media_id, cannot transcribe', [
                        'store_id' => $this->store->id,
                        'customer_phone' => $this->from,
                        'message_type' => $this->messageType,
                    ]);
                    return;
                }

                $localPath = WhatsAppService::downloadMedia($this->mediaId, $this->store);
                if (!$localPath) {
                    Log::error('Failed to download audio media for transcription', [
                        'store_id' => $this->store->id,
                        'customer_phone' => $this->from,
                        'media_id' => $this->mediaId,
                    ]);
                    return;
                }

                try {
                    $openAi = new OpenAIService($this->store->ai_api_key, 'whisper-1');
                    $transcribedText = $openAi->transcribeAudio(Storage::disk('local')->path($localPath));
                    $this->messageBody = "🎤 [AUDIO]: " . trim($transcribedText);

                    if (empty($this->messageBody)) {
                        throw new \Exception('Transcription returned empty text');
                    }

                    Log::info('Audio transcription completed successfully', [
                        'store_id' => $this->store->id,
                        'customer_phone' => $this->from,
                        'media_id' => $this->mediaId,
                        'transcription_length' => mb_strlen($this->messageBody),
                    ]);
                } catch (\Exception $e) {
                    Log::error('Audio transcription failed', [
                        'store_id' => $this->store->id,
                        'customer_phone' => $this->from,
                        'media_id' => $this->mediaId,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);

                    WhatsAppService::sendMessage(
                        $this->from,
                        'No pude transcribir tu audio. Por favor intenta de nuevo o escríbeme tu pregunta.',
                        $this->store
                    );

                    return;
                } finally {
                    if (Storage::disk('local')->exists($localPath)) {
                        Storage::disk('local')->delete($localPath);
                    }
                }
            }

            // Stores in 'knowledge' mode use the domain conversation pipeline.
            // Everything below this branch is the sales flow, unchanged.
            if ($this->store->usesKnowledgeMode()) {
                app(ConversationPipeline::class)->handle($this->store, $this->from, (string) $this->messageBody, $this->productContext, $this->phoneId);

                return;
            }

            // Retrieve product context at the beginning
            $productContext = $this->getProductContextWithTypes();

            // Fetch the last 10 messages for this customer
            // IMPORTANT: This includes BOTH AI responses AND human operator messages
            // Both are stored with role='assistant' in the whats_app_messages table:
            // - AI-generated responses: role='assistant', created by this job
            // - Human operator responses: role='assistant', created by sendMessage() in WhatsAppChatCenter
            //
            // This ensures the AI maintains full conversation context even after human intervention.
            $rawHistory = WhatsAppMessage::where('store_id', $this->store->id)
                ->where('customer_phone', $this->from)
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get(['id', 'role', 'content', 'created_at']);

            $history = $rawHistory
                ->reverse()
                ->map(function (WhatsAppMessage $msg) {
                    return [
                        'role' => $msg->role,
                        'content' => $msg->content,
                    ];
                })
                ->toArray();

            // DEBUG: Log the conversation history being sent to AI
            Log::debug('CONVERSATION_HISTORY: Fetched for AI context', [
                'store_id' => $this->store->id,
                'customer_phone' => $this->from,
                'total_messages_in_history' => count($history),
                'roles' => array_column($history, 'role'),
            ]);

            // ===== SYSTEM PROMPT PIPELINE =====
            // Pure concatenation of database values following: Store Instructions → Product Context → Metadata
            
            // 1. Load store system prompt (must be set by store admin)
            $systemPrompt = trim($this->store->system_prompt ?? '');
            
            // 2. Validate store prompt exists and log if missing
            if (empty($systemPrompt)) {
                Log::warning('PROMPT_VALIDATION: Store system_prompt is empty', [
                    'store_id' => $this->store->id,
                    'store_name' => $this->store->name,
                ]);
                // Minimal fallback: does not replace user's instructions, just signals empty
                $systemPrompt = 'You are an assistant.';
            }
            
            // 3. Append product context (formatted with headers, no hardcoded rules)
            if ($productContext && !empty($productContext['context'])) {
                $systemPrompt .= "\n\n### PRODUCT CATALOG DATA:\n" . $productContext['context'];
            } else {
                // Log when no product context available
                Log::warning('CONTEXT_VALIDATION: No product context retrieved', [
                    'store_id' => $this->store->id,
                    'customer_phone' => $this->from,
                ]);
            }
            
            // 3b. If this customer already has an active order, tell the AI
            // explicitly — it only sees the last 10 messages of history, so a
            // conversation that continues past that window (follow-up about
            // delivery, days later) would otherwise look like a brand new
            // customer and the bot would restart the sales/data-collection
            // flow instead of just acknowledging the follow-up.
            $openOrder = Lead::where('store_id', $this->store->id)
                ->where('customer_phone', $this->from)
                ->whereIn('order_status', ['confirmado', 'enviado'])
                ->latest()
                ->first();

            if ($openOrder) {
                $systemPrompt .= "\n\n### PEDIDO EXISTENTE DE ESTE CLIENTE:\n";
                $systemPrompt .= "Producto: {$openOrder->product_service_name}\n";
                $systemPrompt .= "Estado: {$openOrder->order_status}";
                if ($openOrder->tracking_number) {
                    $systemPrompt .= " (guía: {$openOrder->tracking_number}, transportadora: {$openOrder->carrier})";
                }
                $systemPrompt .= "\n";
                $systemPrompt .= "Si el cliente pregunta o da instrucciones sobre este pedido, NO reinicies el proceso de toma de pedido ni vuelvas a pedir sus datos — ya los tienes. Solo registra la instrucción y confirma que se tendrá en cuenta.\n";
            }

            // 4. Append system metadata (timestamps and completion signal)
            $systemPrompt .= "\n\n### SYSTEM METADATA:\n";
            $systemPrompt .= "Current Date/Time: " . now()->format('Y-m-d H:i:s') . "\n";
            $systemPrompt .= "Lead Completion Signal: [LEAD_COMPLETE]\n";
            $systemPrompt .= "When the customer has confirmed a purchase, an order, or provided enough information to create a lead, append the exact token [LEAD_COMPLETE] at the end of your response. Do not use any other variation of that token.\n";

            // Get the configured AI service for this store
            $aiEngine = AIServiceFactory::make($this->store);

            // Prompt metadata only — the prompt itself carries order data of
            // this customer (see 3b above) and must not reach the logs.
            Log::info("PROMPT_PIPELINE: System prompt constructed", [
                'store_id' => $this->store->id,
                'store_name' => $this->store->name,
                'has_system_prompt' => !empty($this->store->system_prompt),
                'has_product_context' => $productContext !== null && !empty($productContext['context']),
                'prompt_length' => strlen($systemPrompt),
            ]);

            // Get AI response with chat history
            $aiResponse = $aiEngine->getResponse($this->messageBody, $systemPrompt, $history);

            $messageToSend = $aiResponse;
            $hasLeadToken = strpos($aiResponse, '[LEAD_COMPLETE]') !== false;

            // Extract lead data from the conversation for both explicit and fallback detection
            $leadData = $this->extractLeadDataWithAI($history, $aiResponse);
            $shouldCreateLead = $this->shouldCreateLeadFromResponse($aiResponse, $leadData, $hasLeadToken);

            if ($shouldCreateLead) {
                if ($hasLeadToken) {
                    $messageToSend = preg_replace('/\[LEAD_COMPLETE\]/', '', $aiResponse);
                    $messageToSend = trim($messageToSend);
                }

                Log::info('Lead data extracted', [
                    'store_id' => $this->store->id,
                    'customer_phone' => $this->from,
                    'extracted_fields' => array_keys(array_filter($leadData)),
                    'has_lead_token' => $hasLeadToken,
                ]);

                // Guard against duplicate leads: once an order is confirmed, the AI
                // keeps summarizing it ("Producto: ... Nombre: ... ¡Gracias por tu
                // compra!") on unrelated follow-ups like "¿me avisas cuando llegue?" —
                // and re-emits [LEAD_COMPLETE] each time, since from its perspective
                // it's still describing a confirmed purchase. A time window is too
                // fragile (follow-ups can come hours or days later), so instead we
                // only allow ONE open (unprocessed) lead per customer at a time — a
                // new one is only created after staff marks the previous as
                // processed. Bot-control records (customer_name = 'Unknown', created
                // by the Chat Center's bot on/off toggle) are excluded so they never
                // block a real lead from being created.
                $hasOpenLead = Lead::where('store_id', $this->store->id)
                    ->where('customer_phone', $this->from)
                    ->where('is_processed', false)
                    ->where(function ($query) {
                        $query->whereNull('customer_name')->orWhere('customer_name', '!=', 'Unknown');
                    })
                    ->exists();

                if ($hasOpenLead) {
                    Log::warning('DUPLICATE_LEAD_SKIPPED: Ya existe un lead abierto para esta conversación', [
                        'store_id' => $this->store->id,
                        'customer_phone' => $this->from,
                    ]);
                } else {
                    // Resolve the sale value from the product currently linked to this
                    // conversation (see getProductContext()), so Meta's "Purchase"
                    // conversion event carries a real value without asking staff to
                    // type anything. If no single product is confidently linked
                    // (e.g. multi-item order), the lead is created without a value
                    // and only the "Lead" event is sent.
                    $conversation = Conversation::where('store_id', $this->store->id)
                        ->where('customer_phone', $this->from)
                        ->first();
                    $product = $conversation?->current_product_id
                        ? Product::find($conversation->current_product_id)
                        : null;

                    // Fallback: current_product_id isn't always persisted in time for
                    // this exact moment (observed in production — the sticky-product
                    // update can lag behind a fast-moving conversation). The AI-based
                    // extraction above (product_service_name) has proven reliable at
                    // naming the right product even when that happens, so match it
                    // against the catalog the same way ProductFinderService already
                    // matches product names mentioned in a message.
                    if (!$product && !empty($leadData['product_service_name'])) {
                        $product = (new ProductFinderService())
                            ->findProductMentionedInMessage($leadData['product_service_name'], $this->store->id);
                    }

                    // Semantic completeness check on the delivery address: does
                    // not block the sale, just flags it for staff follow-up
                    // when the AI-collected address has no city/region at all
                    // (this has been observed slipping through in production —
                    // the sales-flow AI doesn't always catch its own gap).
                    $needsAddressReview = !empty($leadData['delivery_address_or_location'])
                        && !$this->addressHasCityAndRegion($leadData['delivery_address_or_location']);

                    // The catalog's single-unit price is only correct when the
                    // sale was exactly 1 unit of 1 product at list price. It's
                    // wrong for: multiple units ("2 sets" = 2x price), combos/
                    // accumulated multi-item orders (a laundry-service store
                    // with several garment types), or a manually negotiated
                    // price. Three-tier fallback, most reliable first:
                    // 1) extract each order line (name/qty/unit price) and sum
                    //    them in PHP — avoids relying on the AI's own
                    //    multi-line addition, which has been observed in
                    //    production to drop a line or miscompute the sum even
                    //    when every individual line was priced correctly;
                    // 2) extract the total the AI itself stated in its closing
                    //    message, if (1) couldn't parse clean line items;
                    // 3) the catalog's single-unit price, as a last resort.
                    // All three fail open (never block lead creation).
                    $lineItems = $this->extractOrderLineItems($messageToSend);
                    $saleValueSource = 'catalog_price';

                    $saleValue = $this->computeTotalFromLineItems($lineItems);
                    if ($saleValue !== null) {
                        $saleValueSource = 'line_items_sum';
                    } else {
                        $saleValue = $this->extractConfirmedTotal($messageToSend);
                        if ($saleValue !== null) {
                            $saleValueSource = 'confirmed_total';
                        } else {
                            $saleValue = $product?->price;
                        }
                    }

                    Log::info('SALE_VALUE_RESOLUTION: sale_value determined', [
                        'store_id' => $this->store->id,
                        'customer_phone' => $this->from,
                        'sale_value' => $saleValue,
                        'source' => $saleValueSource,
                        'line_items' => $lineItems,
                    ]);

                    $lead = Lead::create([
                        'store_id' => $this->store->id,
                        'product_id' => $product?->id,
                        'customer_phone' => $this->from,
                        'customer_name' => $leadData['customer_name'] ?? null,
                        'delivery_address_or_location' => $leadData['delivery_address_or_location'] ?? null,
                        'product_service_name' => $leadData['product_service_name'] ?? null,
                        'preferred_date_time' => $leadData['preferred_date_time'] ?? null,
                        'summary' => $messageToSend,
                        'sale_value' => $saleValue,
                        'ctwa_clid' => $conversation?->ctwa_clid,
                        'order_status' => 'confirmado',
                        'needs_address_review' => $needsAddressReview,
                        'is_processed' => false,
                    ]);

                    Log::info('Lead created from WhatsApp conversation', [
                        'store_id' => $this->store->id,
                        'lead_id' => $lead->id,
                        'customer_phone' => $this->from,
                        'product_service_name' => $leadData['product_service_name'] ?? null,
                        'sale_value' => $lead->sale_value,
                        'needs_address_review' => $needsAddressReview,
                        'completion_method' => $hasLeadToken ? 'explicit_token' : 'heuristic_fallback',
                    ]);

                    // Sending to Meta's Conversions API happens in LeadObserver,
                    // triggered automatically on Lead::create() above — this way
                    // every lead gets sent regardless of who/what created it
                    // (bot, manual Chat Center template, Filament, backfill).
                }
            }

            // Process AI response to extract and send images
            // This also cleans the message by removing [IMG: id] tags
            $messageToSend = WhatsAppService::processAIResponse(
                $messageToSend,
                $this->store,
                $this->from
            );

            // Save user message to database
            WhatsAppMessage::create([
                'store_id' => $this->store->id,
                'customer_phone' => $this->from,
                'role' => 'user',
                'content' => $this->messageBody,
            ]);

            // Save AI response to database
            $aiMessage = WhatsAppMessage::create([
                'store_id' => $this->store->id,
                'customer_phone' => $this->from,
                'role' => 'assistant',
                'content' => $aiResponse,
            ]);

            // Send AI response back to customer (without the [LEAD_COMPLETE] tag)
            $wamid = WhatsAppService::sendMessage($this->from, $messageToSend, $this->store);

            // Track message status if WAMID was returned
            if ($wamid) {
                WhatsAppStatusTracker::trackMessage($aiMessage->id, $wamid);
                
                Log::info('AI message status tracking initiated', [
                    'db_message_id' => $aiMessage->id,
                    'wamid' => $wamid,
                ]);
            }

            Log::info('WhatsApp message processed successfully by job', [
                'store_id' => $this->store->id,
                'store_name' => $this->store->name,
                'customer_phone' => $this->from,
                'wamid' => $wamid,
            ]);
        } catch (\Exception $e) {
            // Log the specific error with full context
            Log::error('Job: AI Provider Error for store: ' . $this->store->name, [
                'store_id' => $this->store->id,
                'store_name' => $this->store->name,
                'customer_phone' => $this->from,
                'provider' => $this->store->ai_provider,
                'error_message' => $e->getMessage(),
                'message_length' => mb_strlen($this->messageBody ?? ''),
                'attempt' => $this->attempts(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Re-throw so the queue retries; the customer is only told about
            // the problem once the job is given up (see failed()), not on
            // every attempt.
            throw $e;
        }
    }

    /**
     * Handle a job failure: the message is given up, so apologize once.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Job ProcessWhatsAppMessage failed after max retries', [
            'store_id' => $this->store->id,
            'store_name' => $this->store->name,
            'customer_phone' => $this->from,
            'exception' => $exception->getMessage(),
        ]);

        $fallbackMessage = 'Lo siento, estoy experimentando dificultades técnicas. Por favor intenta más tarde.';
        try {
            WhatsAppService::sendMessage($this->from, $fallbackMessage, $this->store);
        } catch (\Exception $sendError) {
            Log::error('Failed to send fallback message', [
                'store_id' => $this->store->id,
                'customer_phone' => $this->from,
                'error' => $sendError->getMessage(),
            ]);
        }
    }



    /**
     * Get product context information with type details for the message.
     * Returns array with context string and type flags.
     * Includes fallback logic to ensure products are found.
     *
     * @return array|null
     */
    private function getProductContextWithTypes(): ?array
    {
        try {
            Log::info("CONTEXT_RETRIEVAL: Starting product context retrieval", [
                'store_id' => $this->store->id,
                'store_name' => $this->store->name,
                'has_product_context_param' => $this->productContext !== null,
            ]);

            // Conversation-level memory of which product this customer is
            // already talking about. Without this, a short reply with no
            // product name in it (e.g. "sí", "me parece bien", a name, an
            // address) can't be matched to anything specific, and every such
            // turn falls back to the FULL catalog — mixing unrelated
            // products' (possibly contradictory) sales strategies into the
            // prompt mid-negotiation, which is how the bot ends up pivoting
            // to a product the customer never asked about.
            $conversation = Conversation::where('store_id', $this->store->id)
                ->where('customer_phone', $this->from)
                ->first();

            // If a specific product context was provided, fetch that product
            if ($this->productContext) {
                $product = Product::with('images')->find($this->productContext);
                if ($product) {
                    Log::info("CONTEXT_RETRIEVAL: Found product by ID", [
                        'product_id' => $this->productContext,
                        'product_name' => $product->name,
                    ]);

                    $conversation?->update(['current_product_id' => $product->id]);

                    return [
                        'context' => $this->formatProductData($product),
                        'hasServices' => $product->type === 'service',
                        'hasProducts' => $product->type === 'product',
                    ];
                }
            }

            // Otherwise, search for products mentioned in the message
            Log::info("CONTEXT_RETRIEVAL: Searching for products by message", [
                'store_id' => $this->store->id,
            ]);

            $finder = new ProductFinderService();
            $result = $finder->findProductsWithTypes($this->messageBody, $this->store->id, 10);

            // Ensure images are loaded for the found products
            $result['products']->load('images');

            Log::info("CONTEXT_RETRIEVAL: Search result", [
                'products_count' => $result['products']->count(),
                'has_services' => $result['hasServices'],
                'has_products' => $result['hasProducts'],
                'context_preview' => substr($result['context'], 0, 150),
            ]);

            if ($result['products']->count() === 1) {
                // Confident single-product match this turn (mentioned by name,
                // or the store simply has one product) — this becomes (or stays)
                // the conversation's product.
                $conversation?->update(['current_product_id' => $result['products']->first()->id]);
            } elseif ($conversation?->current_product_id) {
                // No confident single match this turn (generic message, empty
                // search, or the multi-product catalog fallback) — stick to the
                // product already established for this conversation instead of
                // mixing in unrelated products on every short reply.
                $stickyProduct = Product::with('images')->find($conversation->current_product_id);

                if ($stickyProduct) {
                    Log::info("CONTEXT_RETRIEVAL: Using sticky conversation product", [
                        'store_id' => $this->store->id,
                        'customer_phone' => $this->from,
                        'product_id' => $stickyProduct->id,
                        'product_name' => $stickyProduct->name,
                    ]);

                    return [
                        'context' => $this->formatProductData($stickyProduct),
                        'hasServices' => $stickyProduct->type === 'service',
                        'hasProducts' => $stickyProduct->type === 'product',
                        'products' => collect([$stickyProduct]),
                    ];
                }
            }

            // If search returned nothing at all, force fetch full catalog
            if ($result['products']->isEmpty()) {
                Log::warning("CONTEXT_RETRIEVAL: Search returned no products, forcing full catalog fetch", [
                    'store_id' => $this->store->id,
                ]);

                // Force fetch all products for this store (ProductFinderService already handles this fallback)
                // But let's add an explicit secondary fallback
                $allProducts = Product::where('store_id', $this->store->id)
                    ->with('images')
                    ->limit(10)
                    ->get(['id', 'name', 'price', 'description', 'stock', 'type', 'ai_sales_strategy', 'faq_context', 'required_customer_info']);

                Log::info("CONTEXT_RETRIEVAL: Explicit full catalog fetch", [
                    'store_id' => $this->store->id,
                    'products_found' => $allProducts->count(),
                ]);

                if ($allProducts->isEmpty()) {
                    Log::warning("CONTEXT_RETRIEVAL: No products exist in database for store", [
                        'store_id' => $this->store->id,
                    ]);

                    return null;
                }

                // Return the explicit full catalog
                $hasServices = $allProducts->where('type', 'service')->isNotEmpty();
                $hasProducts = $allProducts->where('type', 'product')->isNotEmpty();

                Log::info("CONTEXT_CONTENT: " . $this->formatProductsForContext($allProducts));

                return [
                    'context' => $this->formatProductsForContext($allProducts),
                    'hasServices' => $hasServices,
                    'hasProducts' => $hasProducts,
                    'products' => $allProducts,
                ];
            }

            Log::info("CONTEXT_CONTENT: " . ($result['context'] ?? 'EMPTY'));

            return [
                'context' => $result['context'],
                'hasServices' => $result['hasServices'],
                'hasProducts' => $result['hasProducts'],
                'products' => $result['products'],
            ];
        } catch (\Exception $e) {
            Log::warning('Product context retrieval failed', [
                'store_id' => $this->store->id,
                'product_id' => $this->productContext,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    /**
     * Format products list for context injection.
     * Pure data pipeline: validates fields exist, formats with headers, concatenates database values only.
     * NO hardcoded instructions or sales text - all content from database.
     */
    private function formatProductsForContext(Collection $products): string
    {
        if ($products->isEmpty()) {
            return "No products available.";
        }

        $formatted = "Available Offerings:\n\n";

        foreach ($products as $product) {
            // ===== PRODUCT IDENTITY =====
            $formatted .= "Product: " . ($product->name ?? 'Unknown') . "\n";
            $formatted .= "Type: " . ($product->type ?? 'unknown') . "\n";
            
            // ===== PRICING & AVAILABILITY =====
            $formatted .= "Price: $" . number_format($product->price ?? 0, 2) . "\n";
            
            if ($product->type === 'service') {
                $availability = ($product->stock ?? 0) === 1 ? 'Available' : 'Unavailable';
                $formatted .= "Availability: " . $availability . "\n";
            } else {
                $stock = $product->stock ?? 0;
                $formatted .= "Stock: " . $stock . " units\n";
            }
            
            // ===== DESCRIPTION =====
            if (!empty($product->description)) {
                $formatted .= "Description: " . $product->description . "\n";
            }
            
            // ===== IMAGES =====
            if ($product->images && $product->images->count() > 0) {
                $formatted .= "Images:\n";
                // Sort images: primary first, then by ID
                $sortedImages = $product->images->sortByDesc('is_primary')->sortBy('id');
                foreach ($sortedImages as $image) {
                    $formatted .= "- [IMG:{$image->id}] Product image\n";
                }
            } else {
                $formatted .= "Images: None\n";
            }
            
            // ===== DATABASE FIELDS FOR AI SALES & RULES =====
            // These are populated by store admin in database - passed through without modification
            
            if (!empty($product->ai_sales_strategy)) {
                $formatted .= "Sales Strategy: " . $product->ai_sales_strategy . "\n";
            }
            
            if (!empty($product->faq_context)) {
                $formatted .= "Rules & FAQ: " . $product->faq_context . "\n";
            }
            
            if (!empty($product->required_customer_info)) {
                $formatted .= "Required Data: " . $product->required_customer_info . "\n";
            } else {
                Log::debug('FIELD_VALIDATION: Product missing required_customer_info', [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                ]);
            }
            
            $formatted .= "\n---\n\n";
        }

        return $formatted;
    }

    /**
     * Get product context information for the message (legacy method).
     * If a specific product ID is provided, fetch that product's details.
     * Otherwise, search for product mentions in the user message.
     */
    private function getProductContext(): ?string
    {
        $contextData = $this->getProductContextWithTypes();
        return $contextData['context'] ?? null;
    }

    /**
     * Format a single product's data for context.
     * Pure data pipeline: validates fields, formats with headers, no hardcoded sales text.
     */
    private function formatProductData(Product $product): string
    {
        // ===== PRODUCT IDENTITY =====
        $formatted = "Product: " . ($product->name ?? 'Unknown') . "\n";
        $formatted .= "Type: " . ($product->type ?? 'unknown') . "\n";
        
        // ===== PRICING & AVAILABILITY =====
        $formatted .= "Price: $" . number_format($product->price ?? 0, 2) . "\n";
        
        if ($product->type === 'service') {
            $availability = ($product->stock ?? 0) === 1 ? 'Available' : 'Unavailable';
            $formatted .= "Availability: " . $availability . "\n";
        } else {
            $stock = $product->stock ?? 0;
            $formatted .= "Stock: " . $stock . " units\n";
        }
        
        // ===== DESCRIPTION =====
        if (!empty($product->description)) {
            $formatted .= "Description: " . $product->description . "\n";
        }
        
        // ===== IMAGES =====
        if ($product->images && $product->images->count() > 0) {
            $formatted .= "Images:\n";
            // Sort images: primary first, then by ID
            $sortedImages = $product->images->sortByDesc('is_primary')->sortBy('id');
            foreach ($sortedImages as $image) {
                $formatted .= "- [IMG:{$image->id}] Product image\n";
            }
        } else {
            $formatted .= "Images: None\n";
        }
        
        // ===== DATABASE FIELDS FOR AI SALES & RULES =====
        // Store admin manages these in database - passed through without modification
        
        if (!empty($product->ai_sales_strategy)) {
            $formatted .= "Sales Strategy: " . $product->ai_sales_strategy . "\n";
        }
        
        if (!empty($product->faq_context)) {
            $formatted .= "Rules & FAQ: " . $product->faq_context . "\n";
        }
        
        if (!empty($product->required_customer_info)) {
            $formatted .= "Required Data: " . $product->required_customer_info . "\n";
        } else {
            Log::debug('FIELD_VALIDATION: Product missing required_customer_info', [
                'product_id' => $product->id,
                'product_name' => $product->name,
            ]);
        }
        
        return $formatted;
    }

    /**
     * Extract lead data using AI with strict context validation.
     * Uses JSON extraction to ensure accurate product/service matching.
     * 
     * CRITICAL: This method prioritizes the CURRENT conversation context,
     * ignoring legacy values from earlier in history if the topic has changed.
     *
     * @param array $history Chat history
     * @param string $lastAiResponse The last AI response before [LEAD_COMPLETE]
     * @return array Extracted lead data
     */
    private function extractLeadDataWithAI(array $history, string $lastAiResponse): array
    {
        $leadData = [
            'customer_name' => null,
            'delivery_address_or_location' => null,
            'product_service_name' => null,
            'preferred_date_time' => null,
        ];

        try {
            // Build extraction prompt focusing on CURRENT context
            $extractionPrompt = <<<'PROMPT'
You are a data extraction specialist. Extract customer information from the conversation.

CRITICAL RULES FOR EXTRACTION:
1. Only extract the product/service the customer EXPLICITLY confirmed or requested in the MOST RECENT message
2. IGNORE any products mentioned earlier if the customer changed their mind or topic
3. The product/service must be mentioned in either:
   - The last customer message, OR
   - The last AI response (where you confirmed their request)
4. Do NOT use products from the beginning of the conversation if they were discussing a different service later

Return ONLY valid JSON (no markdown, no code blocks, no extra text):
{
  "customer_name": "extracted name or null",
  "delivery_address_or_location": "address or null",
  "product_service_name": "CURRENT confirmed service only - not from earlier in conversation",
  "preferred_date_time": "date/time or null"
}

CONVERSATION:
PROMPT;

            // Add relevant conversation context (last few messages are most important)
            $contextMessages = array_slice($history, -6); // Last 6 messages for context
            foreach ($contextMessages as $msg) {
                $role = ucfirst($msg['role']);
                $extractionPrompt .= "{$role}: {$msg['content']}\n";
            }

            // Add current message
            $extractionPrompt .= "Customer: {$this->messageBody}\n";
            $extractionPrompt .= "\nAI Confirmation: {$lastAiResponse}\n";

            // Get AI to extract as JSON
            $aiEngine = AIServiceFactory::make($this->store);
            $jsonResponse = $aiEngine->getResponse(
                "Extract lead information as JSON",
                $extractionPrompt,
                [] // No history for this extraction request
            );

            // Length only: the extraction carries the customer's name and address.
            Log::info('Raw AI Lead Extraction Response', [
                'store_id' => $this->store->id,
                'customer_phone' => $this->from,
                'raw_response_length' => strlen($jsonResponse),
            ]);

            // Parse JSON response
            $jsonResponse = trim($jsonResponse);
            
            // Remove markdown code blocks if present
            $jsonResponse = preg_replace('/^```(?:json)?\s*/i', '', $jsonResponse);
            $jsonResponse = preg_replace('/\s*```$/', '', $jsonResponse);
            $jsonResponse = trim($jsonResponse);

            $extracted = json_decode($jsonResponse, true);

            if ($extracted && is_array($extracted)) {
                // Validate product/service name against recent messages
                if (!empty($extracted['product_service_name'])) {
                    $productName = $extracted['product_service_name'];
                    $recentText = $this->messageBody . ' ' . $lastAiResponse;
                    
                    // Check if product appears in recent context (case-insensitive)
                    if (stripos($recentText, $productName) === false) {
                        Log::warning('Extracted product not in recent context', [
                            'store_id' => $this->store->id,
                            'extracted_product' => $productName,
                        ]);
                        
                        // Fall back to regex extraction which is more conservative
                        $fallbackData = $this->extractLeadDataRegex();
                        $extracted['product_service_name'] = $fallbackData['product_service_name'];
                    }
                }

                // Sanitize and validate each field
                $leadData['customer_name'] = $this->sanitizeString($extracted['customer_name'] ?? null, 100);
                $leadData['delivery_address_or_location'] = $this->sanitizeString($extracted['delivery_address_or_location'] ?? null, 255);
                $leadData['product_service_name'] = $this->sanitizeString($extracted['product_service_name'] ?? null, 150);
                $leadData['preferred_date_time'] = $this->sanitizeString($extracted['preferred_date_time'] ?? null, 150);

                Log::info('Lead Data Successfully Extracted via AI', [
                    'store_id' => $this->store->id,
                    'customer_phone' => $this->from,
                    'has_customer_name' => $leadData['customer_name'] !== null,
                    'product_service_name' => $leadData['product_service_name'],
                ]);
            } else {
                Log::warning('Failed to parse AI extraction JSON', [
                    'store_id' => $this->store->id,
                    'response_length' => strlen($jsonResponse),
                ]);
                
                // Fall back to regex extraction
                $leadData = $this->extractLeadDataRegex();
            }
        } catch (\Exception $e) {
            Log::error('AI extraction failed, using fallback regex', [
                'error' => $e->getMessage(),
                'store_id' => $this->store->id,
            ]);
            
            // Fall back to regex extraction
            $leadData = $this->extractLeadDataRegex();
        }

        return $leadData;
    }

    /**
     * Focused, narrow yes/no check on whether a delivery address includes an
     * identifiable city and region (department/province/state — whatever the
     * country calls it). Deliberately not a hardcoded list of Colombian
     * departments or Ecuadorian provinces: this app serves stores in
     * different countries, and a single-purpose classification question
     * generalizes across all of them without needing per-country maintenance.
     *
     * A narrow, single-purpose question like this is far more reliable for
     * the AI to get right than remembering a data-completeness rule buried
     * in the middle of a long, multi-step sales system_prompt.
     */
    private function addressHasCityAndRegion(string $address): bool
    {
        try {
            $aiEngine = AIServiceFactory::make($this->store);
            $response = $aiEngine->getResponse(
                'Analiza la dirección',
                "Analiza este texto de dirección de entrega:\n\n\"{$address}\"\n\n¿Incluye una ciudad Y una región administrativa identificable (departamento, provincia, estado, o equivalente según el país)? Responde ÚNICAMENTE con la palabra \"si\" o \"no\", sin nada más.",
                []
            );

            $normalized = mb_strtolower(trim($response));

            return str_starts_with($normalized, 'si') || str_starts_with($normalized, 'sí');
        } catch (\Exception $e) {
            Log::warning('addressHasCityAndRegion: AI check failed, assuming address is complete to avoid false alarms', [
                'store_id' => $this->store->id,
                'error' => $e->getMessage(),
            ]);

            // Fail open: an API error here shouldn't flag every lead for review.
            return true;
        }
    }

    /**
     * Extracts the final total the customer actually agreed to pay, from the
     * AI's own confirmation message — covers multiple units, combos/
     * accumulated multi-item orders, and manually negotiated prices, none of
     * which the catalog's single-unit price reflects correctly. Returns null
     * (not a guess) when no clear total is stated, so the caller can fall
     * back to the catalog price instead of recording a wrong value.
     */
    private function extractConfirmedTotal(string $confirmationMessage): ?float
    {
        try {
            $aiEngine = AIServiceFactory::make($this->store);
            $response = $aiEngine->getResponse(
                'Extrae el valor total',
                "Este es el mensaje de un asistente de ventas confirmando un pedido a un cliente:\n\n\"{$confirmationMessage}\"\n\n¿Cuál es el valor TOTAL final que el cliente debe pagar por este pedido completo (sumando todo lo que incluya)? Responde ÚNICAMENTE el número, sin símbolo de moneda ni separadores de miles, por ejemplo: 54900 o 109800.50. Si el mensaje no indica un valor total claro, responde exactamente \"ninguno\".",
                []
            );

            $normalized = trim($response);

            if (mb_strtolower($normalized) === 'ninguno') {
                return null;
            }

            $digitsOnly = preg_replace('/[^\d.]/', '', $normalized);
            $value = $digitsOnly !== '' ? (float) $digitsOnly : null;

            return ($value !== null && $value > 0) ? $value : null;
        } catch (\Exception $e) {
            Log::warning('extractConfirmedTotal: AI check failed, falling back to catalog price', [
                'store_id' => $this->store->id,
                'error' => $e->getMessage(),
            ]);

            // Fail open: an API error here shouldn't block lead creation —
            // the caller falls back to the catalog price.
            return null;
        }
    }

    /**
     * Extracts each confirmed order line (catalog name + quantity) from the
     * AI's own confirmation message, so the total can be computed
     * deterministically in PHP afterwards instead of trusting the AI's own
     * arithmetic OR its memory of a unit price. Observed in production:
     * multi-line addition done in natural language drops a line (or
     * miscomputes the running sum) even when every individual line price is
     * correct — AND the AI can misremember a unit price it saw earlier in a
     * long conversation (e.g. stated $16.000 for an item the catalog prices
     * at $15.000), even while the arithmetic on that wrong number is itself
     * flawless. Matching free-text wording to an exact catalog name is a
     * classification task the AI is reliable at; recalling exact numbers
     * from memory is not — so this call hands the AI the store's real price
     * list as reference and asks only for the matching name, never a price.
     * unit_price is still requested as a fallback for lines that don't match
     * any catalog product (a manually negotiated price, or a combo quoted as
     * a single flat-price line) — computeTotalFromLineItems() only uses it
     * when no catalog match exists. Returns null when no clean line items
     * can be parsed, so the caller falls back to
     * extractConfirmedTotal()/catalog price.
     */
    private function extractOrderLineItems(string $confirmationMessage): ?array
    {
        try {
            $catalogList = Product::where('store_id', $this->store->id)
                ->get(['name', 'price'])
                ->map(fn (Product $p) => "{$p->name}: {$p->price}")
                ->implode("\n");

            $aiEngine = AIServiceFactory::make($this->store);
            $response = $aiEngine->getResponse(
                'Extrae las líneas del pedido',
                "Lista de precios de esta tienda (nombre exacto de catálogo -> precio):\n{$catalogList}\n\n" .
                "Este es el mensaje de un asistente de ventas confirmando un pedido a un cliente:\n\n\"{$confirmationMessage}\"\n\n" .
                "Extrae cada línea del pedido como un arreglo JSON, SIN calcular ningún total, subtotal ni multiplicación. Para cada línea:\n" .
                "- \"catalog_name\": copia EXACTAMENTE, carácter por carácter, uno de los nombres que aparecen antes de los dos puntos en la lista de precios de arriba. NO describas el producto, NO agregues aclaraciones ni paréntesis, NO combines ni inventes un nombre distinto al que está en la lista. Ejemplo correcto: \"Sábana\". Ejemplo INCORRECTO: \"Juego de sábana (incluye fundas)\". Si de verdad ningún nombre de la lista corresponde a esa línea, usa null.\n" .
                "- \"quantity\": la cantidad de esa línea (entero).\n" .
                "- \"unit_price\": el precio unitario que aparece en el mensaje para esa línea. Ponlo igual si catalog_name tiene valor, se usará solo si catalog_name es null.\n\n" .
                "Responde ÚNICAMENTE con JSON válido en este formato exacto, sin texto adicional:\n" .
                "[{\"catalog_name\": \"<nombre o null>\", \"quantity\": <entero>, \"unit_price\": <numero>}]\n\n" .
                "Si no puedes identificar la cantidad de una línea, omítela. Si no hay ninguna línea clara, responde exactamente: []",
                []
            );

            $normalized = trim(preg_replace('/^```(?:json)?\s*|\s*```$/', '', trim($response)));
            $items = json_decode($normalized, true);

            if (!is_array($items) || empty($items)) {
                return null;
            }

            $validItems = array_values(array_filter($items, function ($item) {
                return is_array($item)
                    && isset($item['quantity'])
                    && is_numeric($item['quantity'])
                    && (float) $item['quantity'] > 0;
            }));

            return !empty($validItems) ? $validItems : null;
        } catch (\Exception $e) {
            Log::warning('extractOrderLineItems: AI check failed, falling back to extractConfirmedTotal', [
                'store_id' => $this->store->id,
                'error' => $e->getMessage(),
            ]);

            // Fail open: an API error here shouldn't block lead creation —
            // the caller falls back to extractConfirmedTotal()/catalog price.
            return null;
        }
    }

    /**
     * Sums quantity x unit price for each extracted order line in plain PHP
     * — deterministic, unlike asking the AI to add several lines together
     * in natural language. The unit price itself is resolved against the
     * real products table whenever the line matched a catalog name — never
     * trusting a number the AI recalled from earlier in the conversation —
     * falling back to the AI-stated unit_price only for lines with no
     * catalog match (negotiated prices, flat-price combos).
     */
    private function computeTotalFromLineItems(?array $items): ?float
    {
        if (empty($items)) {
            return null;
        }

        $catalogPrices = Product::where('store_id', $this->store->id)
            ->get(['name', 'price'])
            ->mapWithKeys(fn (Product $p) => [$this->normalizeCatalogName($p->name) => (float) $p->price]);

        $total = 0.0;
        $resolvedAny = false;

        foreach ($items as $item) {
            $quantity = (float) $item['quantity'];
            $catalogName = $item['catalog_name'] ?? null;
            $catalogKey = $catalogName ? $this->normalizeCatalogName($catalogName) : null;

            $fuzzyPrice = $catalogKey ? $this->fuzzyMatchCatalogPrice($catalogKey, $catalogPrices) : null;

            if ($catalogKey && $catalogPrices->has($catalogKey)) {
                $unitPrice = $catalogPrices->get($catalogKey);
            } elseif ($fuzzyPrice !== null) {
                // The AI didn't copy the catalog name exactly (e.g. it added
                // a description instead of just "Sábana") but a real
                // catalog name still appears inside what it wrote — safer
                // to use that than the AI's own recalled unit_price.
                $unitPrice = $fuzzyPrice;
            } elseif (isset($item['unit_price']) && is_numeric($item['unit_price']) && (float) $item['unit_price'] > 0) {
                $unitPrice = (float) $item['unit_price'];
            } else {
                continue;
            }

            $total += $quantity * $unitPrice;
            $resolvedAny = true;
        }

        return ($resolvedAny && $total > 0) ? $total : null;
    }

    /**
     * Fallback for when the AI didn't copy a catalog name exactly. Covers
     * both directions this has been observed to fail:
     * - the AI invented a longer description around a real catalog name
     *   (e.g. "Juego de sábana (incluye fundas)" when the catalog just says
     *   "Sábana") — the real name is a whole-word substring of what the AI
     *   wrote;
     * - the catalog name itself is long/descriptive and the AI shortened it
     *   (e.g. catalog has "Juego de Sábana (2 fundas, sábana y sobre
     *   sábana)" and the AI wrote just "Juego de Sábana") — what the AI
     *   wrote is a leading substring of the real name.
     * Requires at least 4 normalized characters on the shorter side so a
     * short name (e.g. "Top") can't accidentally match as a stray substring
     * of unrelated text. Returns null if nothing matches, so the caller
     * falls back to the AI-stated unit_price.
     */
    private function fuzzyMatchCatalogPrice(string $catalogKey, \Illuminate\Support\Collection $catalogPrices): ?float
    {
        foreach ($catalogPrices as $realName => $price) {
            if (min(mb_strlen($realName), mb_strlen($catalogKey)) < 4) {
                continue;
            }

            if (preg_match('/\b' . preg_quote($realName, '/') . '/u', $catalogKey)
                || str_starts_with($realName, $catalogKey)
            ) {
                return $price;
            }
        }

        return null;
    }

    /**
     * Case/accent-insensitive key for matching a catalog product name
     * against the AI's extracted catalog_name — the AI copies the name from
     * a list it was just given, so an exact match is expected, but this
     * tolerates trivial differences in casing or accents.
     */
    private function normalizeCatalogName(string $name): string
    {
        return \Illuminate\Support\Str::ascii(mb_strtolower(trim($name)));
    }

    /**
     * Sanitize and validate string fields
     */
    private function shouldCreateLeadFromResponse(string $aiResponse, array $leadData, bool $hasLeadToken): bool
    {
        if ($hasLeadToken) {
            return true;
        }

        if (empty($leadData['product_service_name']) && empty($leadData['customer_name'])) {
            return false;
        }

        if ($this->isLeadCompletionResponse($aiResponse)) {
            return true;
        }

        return false;
    }

    private function isLeadCompletionResponse(string $aiResponse): bool
    {
        $text = mb_strtolower($aiResponse);
        $patterns = [
            'orden está confirmada',
            'pedido está confirmado',
            'tu orden está confirmada',
            'tu pedido está confirmado',
            'su pedido está confirmado',
            'su orden está confirmada',
            'pedido confirmado',
            'orden confirmada',
            'confirmo el pedido',
            'confirmé el pedido',
            'su pedido está casi listo',
            'su orden está casi lista',
            'orden lista',
            'pedido listo',
        ];

        foreach ($patterns as $pattern) {
            if (str_contains($text, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function sanitizeString(?string $value, int $maxLength = 255): ?string
    {
        if (!$value) {
            return null;
        }

        $value = trim($value);
        
        // Remove "null" string if present
        if (strtolower($value) === 'null') {
            return null;
        }

        if (strlen($value) === 0) {
            return null;
        }

        return substr($value, 0, $maxLength);
    }

    /**
     * Fallback regex-based lead extraction
     * Used when AI extraction fails or when validation indicates it picked up legacy data
     */
    private function extractLeadDataRegex(): array
    {
        $leadData = [
            'customer_name' => null,
            'delivery_address_or_location' => null,
            'product_service_name' => null,
            'preferred_date_time' => null,
        ];

        // Combine recent user messages only (not full history)
        $recentMessages = array_slice($this->getRawHistory(), -3); // Last 3 messages
        $allUserMessages = '';
        foreach ($recentMessages as $message) {
            if ($message['role'] === 'user') {
                $allUserMessages .= ' ' . $message['content'];
            }
        }
        $allUserMessages .= ' ' . $this->messageBody;

        // Extract customer name
        if (preg_match('/(?:my name is|i\'?m|call me|my name\'?s)\s+([A-Za-z\s]+?)(?:[,\.]|$|\b(?:and|for|at|on))/i', $allUserMessages, $matches)) {
            $name = trim($matches[1]);
            if (strlen($name) < 50) {
                $leadData['customer_name'] = $name;
            }
        }

        // Extract address
        if (preg_match('/(?:address|location|at|deliver to|service at|located at)\s+([^,\.]*[,\.]|\b[A-Za-z0-9\s,]+(?:Road|Street|Ave|Boulevard|Lane|Drive|Court|District|City|Apt|Apartment|Suite|Block)[\w\s]*)/i', $allUserMessages, $matches)) {
            $address = trim($matches[1]);
            if (strlen($address) < 200) {
                $leadData['delivery_address_or_location'] = preg_replace('/[,\.]+$/', '', $address);
            }
        }

        // Extract product/service - ONLY from current context, strict matching
        $productContext = $this->getProductContextWithTypes();
        if ($productContext && !empty($productContext['products'])) {
            // Find the first product actually mentioned in RECENT messages
            foreach ($productContext['products'] as $product) {
                if (stripos($allUserMessages, $product->name) !== false) {
                    $leadData['product_service_name'] = $product->name;
                    break; // Stop at first match
                }
            }
        }

        // Extract date/time
        if (preg_match('/(?:date|time|when|schedule|book|appointment)\s+(?:for|at|on|:)?\s*([^,\.]*\d{1,2}[\/\-\.]\d{1,2}[\/\-\.]\d{2,4}[^,\.]*|(?:Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)[^,\.]*|(?:\d{1,2}:\d{2}|\d{1,2}\s*(?:am|pm))[^,\.]*)/i', $allUserMessages, $matches)) {
            $datetime = trim($matches[1]);
            if (strlen($datetime) < 100) {
                $leadData['preferred_date_time'] = $datetime;
            }
        }

        return $leadData;
    }

    /**
     * Get raw message history (helper for extraction)
     */
    private function getRawHistory(): array
    {
        return WhatsAppMessage::where('store_id', $this->store->id)
            ->where('customer_phone', $this->from)
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get(['role', 'content'])
            ->reverse()
            ->map(fn($msg) => ['role' => $msg->role, 'content' => $msg->content])
            ->toArray();
    }

    /**
     * Extract lead data from chat history and AI responses.
     * DEPRECATED: Use extractLeadDataWithAI instead for better accuracy.
     * Kept as reference only.
     * 
     * IMPORTANT: The history parameter includes the FULL conversation including:
     * - Customer messages (role='user')
     * - AI-generated responses (role='assistant')
     * - Human operator responses (role='assistant', created during human intervention)
     * 
     * This ensures lead extraction includes context from both AI bot and human operator,
     * providing more complete customer data extraction.
     *
     * @param array $history Chat history with 'role' and 'content'
     *                       Includes both AI and human operator messages
     * @return array Extracted lead data with keys: customer_name, delivery_address_or_location, product_service_name, preferred_date_time
     * @deprecated Use extractLeadDataWithAI instead
     */
    private function extractLeadData(array $history): array
    {
        $leadData = [
            'customer_name' => null,
            'delivery_address_or_location' => null,
            'product_service_name' => null,
            'preferred_date_time' => null,
        ];

        // Combine all user messages from history for analysis
        $allUserMessages = '';
        foreach ($history as $message) {
            if ($message['role'] === 'user') {
                $allUserMessages .= ' ' . $message['content'];
            }
        }

        // Add current message body
        $allUserMessages .= ' ' . $this->messageBody;

        // Extract customer name - look for patterns like "My name is X" or "I'm X"
        if (preg_match('/(?:my name is|i\'?m|call me|my name\'?s)\s+([A-Za-z\s]+?)(?:[,\.]|$|\b(?:and|for|at|on))/i', $allUserMessages, $matches)) {
            $name = trim($matches[1]);
            if (strlen($name) < 50) { // Sanity check
                $leadData['customer_name'] = $name;
            }
        }

        // Extract address - look for patterns indicating location/address
        if (preg_match('/(?:address|location|at|deliver to|service at|located at)\s+([^,\.]*[,\.]|\b[A-Za-z0-9\s,]+(?:Road|Street|Ave|Boulevard|Lane|Drive|Court|District|City|Apt|Apartment|Suite|Block)[\w\s]*)/i', $allUserMessages, $matches)) {
            $address = trim($matches[1]);
            if (strlen($address) < 200) { // Sanity check
                $leadData['delivery_address_or_location'] = preg_replace('/[,\.]+$/', '', $address);
            }
        }

        // Extract product/service name from chat history
        $productContext = $this->getProductContextWithTypes();
        if ($productContext && !empty($productContext['products'])) {
            // Get the first product mentioned
            $product = $productContext['products']->first();
            if ($product) {
                $leadData['product_service_name'] = $product->name;
            }
        }

        // Extract date/time patterns
        if (preg_match('/(?:date|time|when|schedule|book|appointment)\s+(?:for|at|on|:)?\s*([^,\.]*\d{1,2}[\/\-\.]\d{1,2}[\/\-\.]\d{2,4}[^,\.]*|(?:Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)[^,\.]*|(?:\d{1,2}:\d{2}|\d{1,2}\s*(?:am|pm))[^,\.]*)/i', $allUserMessages, $matches)) {
            $datetime = trim($matches[1]);
            if (strlen($datetime) < 100) { // Sanity check
                $leadData['preferred_date_time'] = $datetime;
            }
        }

        return $leadData;
    }
}
