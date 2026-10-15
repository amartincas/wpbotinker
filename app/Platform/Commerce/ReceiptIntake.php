<?php

namespace App\Platform\Commerce;

use App\Models\Customer;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Models\Store;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppTemplate;
use App\Platform\Memory\CustomerMemory;
use App\Services\WhatsAppService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * C4 — a payment receipt (image) sent by a customer. Adapted from
 * WpBotTrainer's PaymentHandler receipt flow:
 *
 * - it attaches to ONE open payment of this customer in this store; an
 *   image never creates a payment, and an ambiguous one is not guessed;
 * - the file is moved to the private receipts disk (the downloaded temp
 *   copy is always deleted — WpBotTrainer kept it) and deduplicated by
 *   hash within the store;
 * - the AI reading only supports the review: it never confirms anything;
 * - the store admin is alerted once per receipt; if that fails the job
 *   fails, and its retry (same message id) resumes from the stored
 *   receipt without reading it again or duplicating anything.
 */
class ReceiptIntake
{
    public function __construct(
        private readonly CustomerMemory $memory,
        private readonly ReceiptReader $reader,
        private readonly ReceiptChecks $checks,
        private readonly StoreMessenger $messenger,
    ) {}

    public function handle(Store $store, string $from, string $mediaId, ?string $caption, string $wamid): void
    {
        // A retry of this same message: resume from what was stored.
        if ($existing = PaymentReceipt::where('store_id', $store->id)->where('source_wamid', $wamid)->first()) {
            $this->finish($store, $from, $existing);

            return;
        }

        $customer = $this->memory->customerFor($store, $from);
        $payment = $this->targetPayment($store, $customer, $from, $caption);

        if ($payment === null) {
            return;
        }

        $download = WhatsAppService::downloadMedia($mediaId, $store);

        if ($download === null) {
            $this->reply($store, $from, 'No pude descargar la imagen. ¿Puedes enviarla de nuevo?', $payment->product->domain_key);

            return;
        }

        $local = Storage::disk('local');

        try {
            $contents = (string) $local->get($download);
            $mime = $local->mimeType($download) ?: 'image/jpeg';
            $hash = hash('sha256', $contents);

            if (! str_starts_with($mime, 'image/')) {
                $this->reply($store, $from, 'Envía el comprobante como una foto o captura de pantalla, por favor.', $payment->product->domain_key);

                return;
            }

            if (PaymentReceipt::where('store_id', $store->id)->where('file_hash', $hash)->exists()) {
                $this->reply($store, $from, 'Ya recibimos este comprobante y lo estamos revisando. Te avisaremos cuando quede confirmado.', $payment->product->domain_key);

                return;
            }

            $read = $this->reader->read($store, $local->path($download), $mime);

            if ($read['status'] === ReceiptReader::UNREADABLE) {
                $this->reply($store, $from, 'No pude leer el comprobante. Envía una foto clara donde se vean el valor y la referencia del pago.', $payment->product->domain_key);

                return;
            }

            $path = "{$store->id}/".Str::uuid().'.'.(pathinfo($download, PATHINFO_EXTENSION) ?: 'jpg');
            Storage::disk(PaymentReceipt::DISK)->put($path, $contents);
        } finally {
            $local->delete($download);
        }

        try {
            $receipt = $this->record($customer, $payment, $wamid, $path, $mime, strlen($contents), $hash, $read);
        } catch (Throwable $e) {
            Storage::disk(PaymentReceipt::DISK)->delete($path);

            if ($e instanceof UniqueConstraintViolationException && ($same = PaymentReceipt::where('store_id', $store->id)->where('source_wamid', $wamid)->first())) {
                $this->finish($store, $from, $same);

                return;
            }

            if ($e instanceof UniqueConstraintViolationException || $e instanceof PaymentNoLongerOpen) {
                $this->reply($store, $from, 'Ya recibimos un comprobante para este pago o el pago ya fue revisado. Escribe «estado de mi pago» para consultarlo.', $payment->product->domain_key);

                return;
            }

            throw $e;
        }

        $this->finish($store, $from, $receipt);
    }

    /**
     * The one open payment the receipt belongs to, or null after telling
     * the customer why none can be chosen. A pending payment past its
     * 48-hour window expires here (no scheduler needed for that).
     */
    private function targetPayment(Store $store, Customer $customer, string $from, ?string $caption): ?Payment
    {
        $expired = false;

        $open = Payment::where('customer_id', $customer->id)
            ->whereIn('status', Payment::OPEN_STATUSES)
            ->with('product')
            ->orderBy('id')
            ->get()
            ->reject(function (Payment $payment) use (&$expired) {
                if ($payment->status === Payment::STATUS_PENDING && $payment->expires_at?->isPast()) {
                    $payment->update(['status' => Payment::STATUS_EXPIRED]);

                    return $expired = true;
                }

                return false;
            })
            ->values();

        if ($open->isEmpty()) {
            $this->reply($store, $from, $expired
                ? 'Tu solicitud de pago venció. Escribe «pagar» para iniciar una nueva y vuelve a enviar el comprobante.'
                : 'No tengo un pago en curso para asociar este comprobante. Escribe «pagar» para iniciarlo y después envíalo de nuevo.');

            return null;
        }

        if ($open->count() === 1) {
            return $open->first();
        }

        $named = $this->namedIn($open, (string) $caption);

        if ($named !== null) {
            return $named;
        }

        $this->reply($store, $from, 'Tienes pagos en curso para '.$open->map(fn (Payment $p) => "«{$p->product->name}»")->implode(', ')
            .'. Vuelve a enviar el comprobante escribiendo en el mensaje el nombre del producto que pagaste.');

        return null;
    }

    /**
     * @param  Collection<int, Payment>  $payments
     */
    private function namedIn(Collection $payments, string $caption): ?Payment
    {
        $text = Str::ascii(mb_strtolower($caption));

        $matches = $payments->filter(fn (Payment $payment) => collect([$payment->product->name, ...($payment->product->aliases ?? [])])
            ->map(fn ($name) => Str::ascii(mb_strtolower(trim((string) $name))))
            ->contains(fn ($name) => mb_strlen($name) >= 3 && preg_match('/\b'.preg_quote($name, '/').'\b/', $text) === 1));

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /**
     * Stores the receipt and moves the payment to review, under the same
     * locks as PaymentReview (customer, then payment).
     */
    private function record(Customer $customer, Payment $payment, string $wamid, string $path, string $mime, int $size, string $hash, array $read): PaymentReceipt
    {
        return DB::transaction(function () use ($customer, $payment, $wamid, $path, $mime, $size, $hash, $read) {
            Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $locked = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isOpen()) {
                throw new PaymentNoLongerOpen;
            }

            $flags = $this->checks->flags($locked, $read['status'], $read['data']);

            $receipt = PaymentReceipt::create([
                'payment_id' => $locked->id,
                'source_wamid' => $wamid,
                'file_path' => $path,
                'mime_type' => $mime,
                'file_size' => $size,
                'file_hash' => $hash,
                // The payer's name is not needed for the review: never stored.
                'extracted_data' => [...array_diff_key($read['data'], ['payer_name' => true]), 'read_status' => $read['status'], 'flags' => $flags],
            ]);

            $locked->update(array_filter([
                'status' => $locked->status === Payment::STATUS_UNDER_REVIEW ? null : Payment::STATUS_UNDER_REVIEW,
                'reference' => $locked->reference === null ? $read['data']['reference'] : null,
                'receipt_submitted_at' => now(),
            ], fn ($value) => $value !== null));

            Log::info('PAYMENT_RECEIPT_RECEIVED', ['store_id' => $locked->store_id, 'payment_id' => $locked->id, 'receipt_id' => $receipt->id, 'read_status' => $read['status']]);

            return $receipt;
        });
    }

    /** Alerts the admin once per receipt (a failure fails the job, so it is retried), then tells the customer. */
    private function finish(Store $store, string $from, PaymentReceipt $receipt): void
    {
        $payment = $receipt->payment()->with('product')->firstOrFail();

        if ($receipt->alerted_at === null) {
            $this->alertAdmin($store, $payment, $receipt);
        }

        $this->reply($store, $from, "Recibí tu comprobante de «{$payment->product->name}». Lo estamos verificando y te avisaré cuando quede confirmado.", $payment->product->domain_key, '[comprobante de pago]');
    }

    private function alertAdmin(Store $store, Payment $payment, PaymentReceipt $receipt): void
    {
        $admin = $store->adminWhatsApp();

        if ($admin === null) {
            Log::warning('PAYMENT_ALERT_NO_ADMIN', ['store_id' => $store->id, 'payment_id' => $payment->id]);

            return;
        }

        $data = $receipt->extracted_data ?? [];
        $amount = $this->money((float) $payment->amount, $payment->currency);
        $lines = [
            "🧾 Comprobante por revisar — P-{$payment->id}",
            "Producto: {$payment->product->name} · plan {$payment->plan_name}",
            "Valor esperado: {$amount}",
            "Cliente: {$payment->customer->customer_phone}",
            '',
            'Lectura automática (solo apoyo, no confirma el pago):',
            '- Valor: '.(isset($data['amount']) ? $this->money((float) $data['amount'], $payment->currency) : 'no disponible'),
            '- Referencia: '.($data['reference'] ?? 'no disponible'),
            '- Fecha: '.($data['date'] ?? 'no disponible'),
            '- Entidad: '.($data['entity'] ?? 'no disponible'),
        ];

        foreach ($data['flags'] ?? [] as $flag) {
            $lines[] = '⚠️ '.(ReceiptChecks::MESSAGES[$flag] ?? $flag);
        }

        $lines[] = '';
        $lines[] = "Verifica el pago en tu banco y responde «Sí P-{$payment->id}» si llegó o «No P-{$payment->id}» si no llegó.";

        $sent = $this->messenger->send($store, $admin, implode("\n", $lines), WhatsAppTemplate::EVENT_PAYMENT_ALERT, [
            'store_name' => $store->name,
            'payment_ref' => "P-{$payment->id}",
            'product_name' => $payment->product->name,
            'amount' => $amount,
            'customer_phone' => $payment->customer->customer_phone,
        ]);

        if ($sent === null) {
            throw new RuntimeException("The payment alert for payment [{$payment->id}] could not be sent.");
        }

        $receipt->forceFill(['alerted_at' => now()])->save();

        // The image itself, best effort (only delivered inside the 24-hour window).
        if ($receipt->hasFile()) {
            WhatsAppService::sendWhatsAppImage($admin, $receipt->file_path, $store, "Comprobante P-{$payment->id}", PaymentReceipt::DISK);
        }
    }

    private function reply(Store $store, string $from, string $text, ?string $domainKey = null, string $customerTurn = '[imagen]'): void
    {
        WhatsAppMessage::create(['store_id' => $store->id, 'customer_phone' => $from, 'role' => 'user', 'domain_key' => $domainKey, 'content' => $customerTurn]);
        $message = WhatsAppMessage::create(['store_id' => $store->id, 'customer_phone' => $from, 'role' => 'assistant', 'domain_key' => $domainKey, 'content' => $text]);

        if ($wamid = WhatsAppService::sendMessage($from, $text, $store)) {
            $message->update(['wamid' => $wamid, 'status' => 'pending', 'status_updated_at' => now()]);
        }
    }

    private function money(float $amount, string $currency): string
    {
        return '$'.number_format($amount, fmod($amount, 1.0) == 0.0 ? 0 : 2, ',', '.')." {$currency}";
    }
}
