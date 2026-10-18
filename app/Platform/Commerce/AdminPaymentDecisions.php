<?php

namespace App\Platform\Commerce;

use App\Models\Payment;
use App\Models\Store;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppTemplate;
use App\Services\WhatsAppService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;

/**
 * C5 — the store admin decides a payment by replying «Sí P-12» / «No P-12»
 * to the store's WhatsApp number, after checking their bank.
 *
 * - Only the store's admin number (stores.alert_phone) is heard here; a
 *   customer writing «sí» is just a customer message.
 * - Only payments of THIS store with a receipt under review (alerted to
 *   the admin) can be decided. A bare «Sí»/«No» is accepted only when
 *   exactly one is pending; otherwise the admin is asked which one.
 * - The decision goes through PaymentReview (locks, one activation): a
 *   repeated or concurrent answer changes nothing and tells nobody twice.
 * - The customer is told before the admin gets the reply.
 * - Anything that cannot be resolved is answered without touching the
 *   payment.
 */
class AdminPaymentDecisions
{
    private const PATTERN = '/^(si|no)(?:[\s,.:;!-]*(?:(?:el\s+)?pago\s*)?(?:p\s*-?\s*|#)?(\d+))?[\s.!]*$/';

    public function __construct(
        private readonly PaymentReview $review,
        private readonly StoreMessenger $messenger,
    ) {}

    /** Whether this message is the store admin answering a payment review. */
    public function handles(Store $store, string $from, string $message): bool
    {
        return $this->isAdmin($store, $from) && $this->parse($message) !== null;
    }

    public function handle(Store $store, string $from, string $message): void
    {
        if (! $this->isAdmin($store, $from) || ($parsed = $this->parse($message)) === null) {
            return;
        }

        [$approve, $paymentId] = $parsed;

        $payment = $paymentId !== null ? $this->referenced($store, $from, $message, $paymentId) : $this->onlyPending($store, $from, $message);

        if ($payment === null) {
            return;
        }

        try {
            $outcome = $this->review->decide($payment, $approve, note: 'Decisión del administrador por WhatsApp');
        } catch (LogicException) {
            $this->reply($store, $from, $message, "El pago P-{$payment->id} ya no está en revisión; no se cambió nada.");

            return;
        }

        if (! $outcome->changed) {
            $this->reply($store, $from, $message, "El pago P-{$payment->id} ya estaba ".($approve ? 'confirmado' : 'rechazado').'; no se cambió nada.');

            return;
        }

        // The customer first: nothing that happens with the admin's reply
        // may keep them from being told (a retry finds the payment decided
        // and never tells them twice).
        $this->notifyCustomer($store, $outcome, $approve);

        $product = $outcome->payment->product;

        $this->reply($store, $from, $message, $approve
            ? "✅ P-{$payment->id} confirmado. La suscripción a «{$product->name}» está activa hasta el {$outcome->subscription->expires_at->format('d/m/Y')}. Ya avisé al cliente."
            : "❌ P-{$payment->id} rechazado. No se concedió acceso. Ya avisé al cliente.");
    }

    private function notifyCustomer(Store $store, ReviewOutcome $outcome, bool $approve): void
    {
        $payment = $outcome->payment;
        $product = $payment->product;
        $phone = $payment->customer->customer_phone;
        $expires = $outcome->subscription?->expires_at->format('d/m/Y');
        $text = $approve
            ? "✅ Tu pago de «{$product->name}» fue confirmado. Tu acceso está activo hasta el {$expires}."
            : "Tu pago de «{$product->name}» no pudo confirmarse. Si crees que es un error, escríbenos o escribe «pagar» para intentarlo de nuevo.";

        // The product's activation message only travels as free text: an
        // approved template cannot carry it.
        $activation = $approve ? trim((string) $product->activation_message) : '';

        if ($activation !== '' && $this->messenger->windowOpen($store, $phone)) {
            $text .= "\n\n".$activation;
        }

        $sent = $this->messenger->send($store, $phone, $text,
            WhatsAppTemplate::EVENT_PAYMENT_RESULT,
            ['product_name' => $product->name, 'result' => $approve ? 'confirmado' : 'no confirmado', 'expires_at' => (string) $expires],
            $product->domain_key,
        );

        if ($sent === null) {
            Log::warning('PAYMENT_RESULT_NOT_DELIVERED', ['store_id' => $store->id, 'payment_id' => $payment->id]);
        }
    }

    /** The payment the admin named, if it belongs to this store and is under review. */
    private function referenced(Store $store, string $from, string $message, int $paymentId): ?Payment
    {
        $payment = Payment::where('store_id', $store->id)->with('product')->find($paymentId);

        if ($payment === null) {
            $this->reply($store, $from, $message, "No encuentro el pago P-{$paymentId} en esta tienda. No se cambió nada.");

            return null;
        }

        if (in_array($payment->status, [Payment::STATUS_CONFIRMED, Payment::STATUS_REJECTED], true)) {
            return $payment; // PaymentReview answers it as a no-op or refuses the opposite decision
        }

        if (! $this->awaitingDecision($store)->contains('id', $payment->id)) {
            $this->reply($store, $from, $message, "El pago P-{$paymentId} no tiene un comprobante en revisión. No se cambió nada.");

            return null;
        }

        return $payment;
    }

    /** A bare «Sí»/«No» decides only when exactly one payment awaits a decision. */
    private function onlyPending(Store $store, string $from, string $message): ?Payment
    {
        $pending = $this->awaitingDecision($store);

        if ($pending->count() === 1) {
            return $pending->first();
        }

        $this->reply($store, $from, $message, $pending->isEmpty()
            ? 'No hay pagos en revisión en este momento. No se cambió nada.'
            : "Hay {$pending->count()} pagos en revisión:\n"
                .$pending->map(fn (Payment $p) => "- P-{$p->id}: «{$p->product->name}», cliente {$p->customer->customer_phone}")->implode("\n")
                ."\n\nResponde «Sí P-número» o «No P-número» para indicar cuál.");

        return null;
    }

    /**
     * Payments of this store with a receipt the admin was alerted about,
     * still waiting for a decision.
     *
     * @return Collection<int, Payment>
     */
    private function awaitingDecision(Store $store): Collection
    {
        return Payment::where('store_id', $store->id)
            ->whereIn('status', [Payment::STATUS_UNDER_REVIEW, Payment::STATUS_NOT_REFLECTED])
            ->whereHas('receipts', fn ($query) => $query->whereNotNull('alerted_at'))
            ->with(['product', 'customer'])
            ->orderBy('id')
            ->get();
    }

    private function isAdmin(Store $store, string $from): bool
    {
        $admin = $store->adminWhatsApp();

        return $admin !== null && preg_replace('/\D/', '', $from) === $admin;
    }

    /**
     * @return array{0: bool, 1: ?int}|null approve, payment id
     */
    private function parse(string $message): ?array
    {
        $text = Str::ascii(mb_strtolower(trim($message)));

        if (preg_match(self::PATTERN, $text, $m) !== 1) {
            return null;
        }

        return [$m[1] === 'si', isset($m[2]) && $m[2] !== '' ? (int) $m[2] : null];
    }

    private function reply(Store $store, string $from, string $message, string $text): void
    {
        WhatsAppMessage::create(['store_id' => $store->id, 'customer_phone' => $from, 'role' => 'user', 'content' => $message]);
        $reply = WhatsAppMessage::create(['store_id' => $store->id, 'customer_phone' => $from, 'role' => 'assistant', 'content' => $text]);

        if ($wamid = WhatsAppService::sendMessage($from, $text, $store)) {
            $reply->update(['wamid' => $wamid, 'status' => 'pending', 'status_updated_at' => now()]);
        }

        Log::info('ADMIN_PAYMENT_DECISION_REPLY', ['store_id' => $store->id]);
    }
}
