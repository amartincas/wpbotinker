<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Models\Conversation;
use App\Jobs\ProcessWhatsAppMessage;
use App\Services\WhatsAppService;
use App\Services\WhatsAppStatusTracker;
use App\Services\Inventory\ProductFinderService;
use App\Models\Lead;
use App\Models\WhatsAppTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;

class WhatsAppController extends Controller
{
    /**
     * Handle Meta WhatsApp webhook verification challenge.
     * GET /api/whatsapp/webhook/{store_token}
     *
     * Meta sends a GET request with query parameters:
     * - hub.mode=subscribe
     * - hub.challenge=<challenge_string>
     * - hub.verify_token=<verify_token>
     *
     * @param Request $request
     * @param string $store_token
     * @return Response
     */
    public function verify(Request $request, string $store_token)
    {
        // Find store by decrypted wa_verify_token
        // Since wa_verify_token is encrypted in DB, we load all stores and compare
        // (Store table is small, so this is efficient)
        $store = Store::all()->firstWhere('wa_verify_token', $store_token);

        if (!$store) {
            Log::warning('WhatsApp webhook verification failed: store not found', [
                'token_length' => strlen($store_token),
            ]);
            return response('Store Not Found', 404);
        }

        // Capturamos los datos que envía Meta
        $mode = $request->input('hub_mode');
        $challenge = $request->input('hub_challenge');
        $verifyToken = $request->input('hub_verify_token');

        // Validamos contra el token de la base de datos
        if ($mode === 'subscribe' && $verifyToken === $store->wa_verify_token) {
            // IMPORTANTE: Retornar solo el challenge como texto plano
            return response($challenge, 200)
                    ->header('Content-Type', 'text/plain');
        }

        return response('Forbidden', 403);
    }

    /**
     * Handle incoming WhatsApp messages from Meta webhook.
     * POST /api/whatsapp/webhook/{store_token}
     *
     * @param Request $request
     * @param string $store_token
     * @return Response
     */
    public function handle(Request $request, string $store_token): Response
{
    $payload = $request->json()->all();

    // SECURITY: the {store_token} in the URL is never actually checked here
    // (only the GET verify() handshake uses it) — the store below is
    // resolved purely from phone_number_id inside the payload body, which
    // isn't secret. Without verifying Meta's signature, anyone who finds
    // this URL pattern and a store's phone_number_id could POST a forged
    // payload and have it processed as a real incoming message. Resolving
    // the store and checking the signature up front, before any other
    // processing, closes that gap for both the status and message branches
    // below.
    $store = $this->resolveStoreFromPayload($payload);

    if (!$store) {
        Log::warning('WhatsApp webhook rejected: could not resolve store from payload metadata', [
            'store_token' => $store_token,
            'payload_metadata' => data_get($payload, 'entry.0.changes.0.value.metadata'),
        ]);
        return response('Not Found', 404);
    }

    if (!$this->verifySignature($request, $store)) {
        Log::warning('WhatsApp webhook rejected: invalid or missing signature', [
            'store_id' => $store->id,
        ]);
        return response('Forbidden', 403);
    }

    // 1. PROCESS STATUS EVENTS (Sent, Delivered, Read, Failed)
    if (isset($payload['entry'][0]['changes'][0]['value']['statuses'])) {
        $statuses = $payload['entry'][0]['changes'][0]['value']['statuses'] ?? [];
        foreach ($statuses as $statusEvent) {
            $wamid = $statusEvent['id'] ?? null;
            $status = $statusEvent['status'] ?? null;
            
            if ($wamid && $status) {
                // Update message status in cache for frontend display
                WhatsAppStatusTracker::updateStatus($wamid, $status);

                // Meta includes an 'errors' array (code/title/message) only
                // when status is 'failed' — without logging it, a failed
                // delivery shows up with no indication of why (expired
                // media link, recipient opted out, template/session
                // restriction, etc.).
                if ($status === 'failed' && !empty($statusEvent['errors'])) {
                    Log::warning('WhatsApp message delivery failed', [
                        'wamid' => $wamid,
                        'errors' => $statusEvent['errors'],
                    ]);
                }

                Log::info('WhatsApp message status received', [
                    'wamid' => $wamid,
                    'status' => $status,
                    'timestamp' => $statusEvent['timestamp'] ?? null,
                ]);
            }
        }
        return response('OK', 200);
    }

    // Extract first message from the array
    $messages = $payload['entry'][0]['changes'][0]['value']['messages'] ?? [];
    if (empty($messages)) {
        return response('OK', 200);
    }

    $message = $messages[0];
    $phoneId = $message['id'] ?? null; // Este es el WAMID único de Meta

    // Rate limit por tienda+contacto: protege el costo de IA frente a un
    // solo número que inunde una tienda. Va ANTES del control de
    // idempotencia: un mensaje rechazado aquí nunca queda marcado como
    // procesado, así el reintento posterior de Meta sí se procesa.
    // RateLimiter::hit() incrementa de forma atómica y devuelve el conteo.
    if (!empty($message['from'])) {
        $hits = RateLimiter::hit(
            "wa-contact:{$store->id}:{$message['from']}",
            (int) config('services.meta.contact_rate_limit_minutes') * 60
        );

        if ($hits > (int) config('services.meta.contact_rate_limit_max')) {
            Log::warning('WHATSAPP_CONTACT_RATE_LIMITED', [
                'store_id' => $store->id,
                'customer_phone' => $message['from'],
                'message_id' => $phoneId,
                'hits' => $hits,
            ]);

            return response('Too Many Requests', 429);
        }
    }

    // 🔥 CONTROL DE IDEMPOTENCIA: Bloquear reintentos de Meta de inmediato
    //
    // IMPORTANTE: Cache::add() es atómico (check-and-set en una sola operación).
    // Antes esto era un Cache::has() seguido de un Cache::put() por separado —
    // dos peticiones casi simultáneas para el mismo WAMID (reintento de Meta
    // procesado por otro worker antes de que el primero alcanzara a escribir
    // la caché) podían pasar ambas el chequeo y disparar el job dos veces,
    // generando dos respuestas de IA para el mismo mensaje del cliente.
    if ($phoneId) {
        $cacheKey = "whatsapp_msg_processed:{$phoneId}";

        // add() devuelve false si la clave ya existía — ahí sabemos que es un reintento.
        if (!Cache::add($cacheKey, true, now()->addMinutes(10))) {
            Log::warning('WhatsApp Webhook: Reintento de Meta detectado e ignorado.', ['message_id' => $phoneId]);
            return response('EVENT_RECEIVED', 200);
        }
    }

    // Si pasa los filtros, registramos solo metadatos del mensaje entrante —
    // nunca el payload crudo: trae el texto del cliente y su nombre de perfil.
    Log::info('WhatsApp webhook message received', [
        'store_id' => $store->id,
        'message_id' => $phoneId,
        'type' => $message['type'] ?? null,
    ]);

    // $store was already resolved and its signature verified at the top of
    // this method.

    $type = $message['type'] ?? null;
    $fromPhone = $message['from'] ?? null;

    $body = null;
    $mediaId = null;

    if ($type === 'text') {
        $body = $message['text']['body'] ?? null;
    } elseif (in_array($type, ['audio', 'voice'], true)) {
        $mediaId = $message[$type]['id'] ?? null;
        
        // Keep body empty so ProcessWhatsAppMessage can trigger transcription logic
        $body = null; 
        
        Log::info('WhatsApp audio/voice message received', [
            'store_id' => $store->id,
            'customer_phone' => $fromPhone,
            'message_id' => $phoneId,
            'media_id' => $mediaId,
        ]);
    } elseif ($type === 'image' && $store->usesKnowledgeMode()) {
        // Payment receipts (C4). The sales flow keeps ignoring images.
        $mediaId = $message['image']['id'] ?? null;
        $body = $message['image']['caption'] ?? null;
    }

    if (!$fromPhone || (!$body && !$mediaId)) {
        return response('OK', 200);
    }

    // Find or create conversation
    $conversation = Conversation::firstOrCreate(
        ['store_id' => $store->id, 'customer_phone' => $fromPhone],
        ['last_session_at' => now()]
    );

    if ($conversation->wasRecentlyCreated === false) {
        $conversation->update(['last_session_at' => now()]);
    }

    // Si el cliente escribió desde un anuncio de Click-to-WhatsApp, Meta manda
    // el ID del anuncio en referral.source_id. Lo usamos para identificar el
    // producto exacto sin depender de que el mensaje prellenado del anuncio
    // coincida con el nombre del producto; si no hay match, ProcessWhatsAppMessage
    // cae de vuelta a la búsqueda por texto del mensaje.
    $adId = $message['referral']['source_id'] ?? null;
    $productContext = $adId
        ? (new ProductFinderService())->findProductByAdId($adId, $store->id)?->id
        : null;

    // An ad tagged on several products of the store names none of them:
    // the sales flow asks the customer which one instead of guessing.
    $adProductCandidates = null;

    if ($adId && $productContext === null) {
        $candidates = (new ProductFinderService())->productIdsForAdId($adId, $store->id);
        $adProductCandidates = count($candidates) > 1 ? $candidates : null;
    }

    // ctwa_clid es el click id que la Conversions API necesita para atribuir
    // una conversión al anuncio que originó la conversación. Solo viene en el
    // primer mensaje de la sesión de Click-to-WhatsApp, así que solo lo
    // guardamos cuando está presente para no borrar uno ya guardado en
    // mensajes de seguimiento que no traen referral.
    $ctwaClid = $message['referral']['ctwa_clid'] ?? null;
    if ($ctwaClid) {
        $conversation->update(['ctwa_clid' => $ctwaClid]);
    }

    // Dispatch job to process the message asynchronously
    ProcessWhatsAppMessage::dispatch(
        $store,
        $fromPhone,
        $body,
        $phoneId,
        $type,
        $mediaId,
        $productContext,
        $adProductCandidates
    );

    Log::info('WhatsApp message queued for processing', [
        'store_id' => $store->id,
        'message_id' => $phoneId,
    ]);

    return response('EVENT_RECEIVED', 200);
}
// -------------------------------------------------------------------------
    // NEW METHOD
    // -------------------------------------------------------------------------

    /**
     * Send a Meta-approved template message to a lead from the operator dashboard.
     *
     * POST /api/whatsapp/templates/send
     *
     * Request body:
     * {
     *   "lead_id":      123,
     *   "template_id":  7,
     *   "custom_values": ["value1", "value2"]   // operator-supplied overrides
     * }
     *
     * Flow:
     *  1. Validate request fields.
     *  2. Load lead and template, enforcing store_id ownership on both.
     *  3. Auto-prefill variables from lead data using parameters_map,
     *     then merge/override with any custom_values supplied by the operator.
     *  4. Call WhatsAppService::sendTemplateMessage().
     *  5. If template is_reengagement, reset the lead status so the
     *     AIOrchestrator can resume once the customer replies.
     *
     * @param  Request      $request
     * @return JsonResponse
     */
    public function sendManualTemplate(Request $request): JsonResponse
    {
        $user = Auth::guard('web')->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        // Derive store from the authenticated user
        $storeId = $user->store_id;    

        // ── 1. Validate ───────────────────────────────────────────────────────
        $validated = $request->validate([
            'lead_id'        => ['required', 'integer', 'exists:leads,id'],
            'template_id'    => ['required', 'integer', 'exists:whatsapp_templates,id'],
            'custom_values'  => ['sometimes', 'array'],
            'custom_values.*' => ['string', 'max:1024'],
        ]);

        // ── 2. Load & authorise ───────────────────────────────────────────────
        // The authenticated user's store_id gates both records, ensuring a store
        // operator can never send a template or contact a lead from another tenant.
        // Super admins may act on any store (same rule as the Filament resources).
        $lead = Lead::query()
            ->when(! $user->is_super_admin, fn ($query) => $query->where('store_id', $storeId))
            ->findOrFail($validated['lead_id']);

        /** @var \App\Models\Store $store */
        $store = $lead->store;   // eager via relationship

        // Verify the template belongs to the same store as the lead.
        $template = WhatsAppTemplate::where('id', $validated['template_id'])
            ->where('store_id', $store->id)
            ->first();

        if (!$template) {
            Log::warning('WhatsApp template send unauthorised: template does not belong to lead store', [
                'store_id'    => $store->id,
                'lead_id'     => $lead->id,
                'template_id' => $validated['template_id'],
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Template not found or does not belong to this store.',
            ], 403);
        }

        // ── 3. Build variables array ──────────────────────────────────────────
        // parameters_map defines which lead/product fields auto-fill each position.
        // Example map: {"1": "customer_name", "2": "product_name", "3": "tracking_number"}
        //
        // Supported auto-fill keys (extend as your Lead / Product models grow):
        //   customer_name, customer_phone, product_name, lead_status
        //
        // custom_values supplied by the operator act as an indexed override list:
        //   position 1 → custom_values[0], position 2 → custom_values[1], …
        // An operator value that is a non-empty string takes precedence over the
        // auto-filled value for that position.

        $parametersMap  = $template->parameters_map ?? [];  // ["1" => "customer_name", …]
        $customValues   = $validated['custom_values'] ?? [];
        $resolvedValues = [];

        // Auto-fill lookup table — add more mappings here as needed
        $leadData = [
            'customer_name'  => $lead->customer_name  ?? '',
            'customer_phone' => $lead->customer_phone ?? '',
            'product_name'   => $lead->product_name   ?? '',
            'lead_status'    => $lead->status         ?? '',
        ];

        // Walk through each positional slot defined in the map
        foreach ($parametersMap as $position => $fieldKey) {
            $positionIndex = (int) $position - 1;  // convert 1-based to 0-based

            // Operator override wins if provided and non-empty
            $operatorValue = $customValues[$positionIndex] ?? null;

            $resolvedValues[$positionIndex] = (is_string($operatorValue) && $operatorValue !== '')
                ? $operatorValue
                : ($leadData[$fieldKey] ?? '');
        }

        // Fill any extra positions the operator added beyond the map definition
        foreach ($customValues as $idx => $value) {
            if (!isset($resolvedValues[$idx]) && $value !== '') {
                $resolvedValues[$idx] = $value;
            }
        }

        // Ensure sequential numeric keys for the service method
        ksort($resolvedValues);
        $finalVariables = array_values($resolvedValues);

        Log::info('WhatsApp manual template send initiated', [
            'store_id'      => $store->id,
            'lead_id'       => $lead->id,
            'template_id'   => $template->id,
            'template_name' => $template->name,
            'is_reengagement' => $template->is_reengagement,
            'variable_count' => count($finalVariables),
        ]);

        // ── 4. Send via WhatsAppService ───────────────────────────────────────
        $sent = WhatsAppService::sendTemplateMessage(
            to:           $lead->customer_phone,
            templateName: $template->name,
            languageCode: $template->language,
            variables:    $finalVariables,
            store:        $store,
        );

        if (!$sent) {
            Log::error('WhatsApp manual template send failed', [
                'store_id'      => $store->id,
                'lead_id'       => $lead->id,
                'template_name' => $template->name,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to send template message. Check logs for Meta API details.',
            ], 502);
        }

        // ── 5. Re-engagement: reset lead status ───────────────────────────────
        // When an operator sends a re-engagement template (is_reengagement = true),
        // the 24-hour WhatsApp conversation window will reopen once the customer
        // replies. We pre-emptively set the lead back to an active state so the
        // AIOrchestrator (via ProcessWhatsAppMessage) will resume AI processing
        // as soon as that reply arrives — without requiring manual intervention.
        if ($template->is_reengagement) {
            $lead->update(['status' => 'waiting_customer']);

            // Also re-enable the bot for this phone in case it was paused
            // (bot_active flag lives on the leads table per ARCHITECTURE.md §2.2 Step 2)
            $lead->update(['bot_active' => true]);

            Log::info('WhatsApp re-engagement template sent: lead status reset', [
                'store_id'    => $store->id,
                'lead_id'     => $lead->id,
                'customer'    => $lead->customer_phone,
                'new_status'  => 'waiting_customer',
                'bot_active'  => true,
            ]);
        }

        Log::info('WhatsApp manual template send completed successfully', [
            'store_id'        => $store->id,
            'lead_id'         => $lead->id,
            'template_name'   => $template->name,
            'is_reengagement' => $template->is_reengagement,
        ]);

        return response()->json([
            'success'         => true,
            'message'         => 'Template message sent successfully.',
            'is_reengagement' => $template->is_reengagement,
            'lead_status'     => $template->is_reengagement ? 'waiting_customer' : $lead->status,
        ]);
    }

    private function resolveStoreFromPayload(array $payload): ?Store
    {
        $metadata = data_get($payload, 'entry.0.changes.0.value.metadata', []);
        $phoneNumberId = $metadata['phone_number_id'] ?? $metadata['phoneNumberId'] ?? null;

        if (empty($phoneNumberId)) {
            return null;
        }

        return Store::where('wa_phone_number_id', (string) $phoneNumberId)->first();
    }

    /**
     * Verifies Meta's X-Hub-Signature-256 header against the raw request
     * body, using this store's own Meta App Secret (each store here is
     * registered under its own separate Meta for Developers app, not a
     * shared one). This is the only real authentication on this endpoint —
     * the signature can only be produced by someone who knows the App
     * Secret, which Meta never exposes to clients, unlike the URL's
     * {store_token} or the payload's phone_number_id.
     */
    private function verifySignature(Request $request, Store $store): bool
    {
        if (empty($store->wa_app_secret)) {
            // Fail closed: a store without a configured secret can't be
            // verified, so its webhook is rejected rather than silently
            // trusted. Configure it in the store's WhatsApp settings.
            Log::warning('WhatsApp webhook rejected: store has no app secret configured', [
                'store_id' => $store->id,
            ]);
            return false;
        }

        $signatureHeader = $request->header('X-Hub-Signature-256', '');

        if (!str_starts_with($signatureHeader, 'sha256=')) {
            return false;
        }

        $expected = hash_hmac('sha256', $request->getContent(), $store->wa_app_secret);
        $provided = substr($signatureHeader, strlen('sha256='));

        return hash_equals($expected, $provided);
    }
}
