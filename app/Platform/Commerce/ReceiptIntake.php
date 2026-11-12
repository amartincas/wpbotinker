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
use Illuminate\Support\Facades\Cache;
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
 *   image never creates a payment, and an ambiguous one is not guessed.
 *   After a plan change (CommerceResponder), the amount read decides
 *   between the open payment and the one the change replaced; without one
 *   single match nothing is attached and the admin reviews it by hand;
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
    /** How long a receipt waits for the admin's «Asociar P-n»: its media is downloaded again then. */
    private const UNMATCHED_DAYS = 7;

    /** Characters of a receipt's reference («C-3F9A2»), more only when another pending one holds it. */
    private const REFERENCE_LENGTH = 5;

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
        // A plan change left a payment that may still be the one paid: the receipt's amount decides, once read.
        $replaced = $this->replacedPayments($customer);
        $payment = $replaced->isEmpty() ? $this->targetPayment($store, $customer, $from, $caption) : null;

        if ($replaced->isEmpty() && $payment === null) {
            return;
        }

        $domainKey = ($payment ?? $replaced->first())->product->domain_key;
        $download = WhatsAppService::downloadMedia($mediaId, $store);

        if ($download === null) {
            $this->reply($store, $from, 'No pude descargar la imagen. ¿Puedes enviarla de nuevo?', $domainKey);

            return;
        }

        $local = Storage::disk('local');

        try {
            $contents = (string) $local->get($download);
            $mime = $local->mimeType($download) ?: 'image/jpeg';
            $hash = hash('sha256', $contents);

            if (! str_starts_with($mime, 'image/')) {
                $this->reply($store, $from, 'Envía el comprobante como una foto o captura de pantalla, por favor.', $domainKey);

                return;
            }

            if (PaymentReceipt::where('store_id', $store->id)->where('file_hash', $hash)->exists()) {
                $this->reply($store, $from, 'Ya recibimos este comprobante y lo estamos revisando. Te avisaremos cuando quede confirmado.', $domainKey);

                return;
            }

            $read = $this->reader->read($store, $local->path($download), $mime);

            if ($read['status'] === ReceiptReader::UNREADABLE) {
                $this->reply($store, $from, 'No pude leer el comprobante. Envía una foto clara donde se vean el valor y la referencia del pago.', $domainKey);

                return;
            }

            $path = "{$store->id}/".Str::uuid().'.'.(pathinfo($download, PATHINFO_EXTENSION) ?: 'jpg');
            Storage::disk(PaymentReceipt::DISK)->put($path, $contents);
        } finally {
            $local->delete($download);
        }

        if ($payment === null) {
            [$payment, $reason, $candidates] = $this->matchByAmount($customer, $replaced, $read['data']['amount']);

            if ($payment === null) {
                $this->unmatched($store, $from, $customer, $wamid, $mediaId, $path, $read, $candidates, $reason, $domainKey);

                return;
            }
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
        [$open, $expired] = $this->openPayments($customer);

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
     * The customer's open payments, a pending one past its window expiring
     * here, and whether one did.
     *
     * @return array{0: Collection<int, Payment>, 1: bool}
     */
    private function openPayments(Customer $customer): array
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

        return [$open, $expired];
    }

    /** @return Collection<int, Payment> the customer's payments a plan change replaced, within their window */
    private function replacedPayments(Customer $customer): Collection
    {
        return Payment::where('customer_id', $customer->id)
            ->where('status', Payment::STATUS_EXPIRED)
            ->where('expires_at', '>', now())
            ->with('product')
            ->orderBy('id')
            ->get();
    }

    /**
     * After a plan change, the one payment whose amount the receipt shows,
     * among the open ones and those the change replaced. Never the latest by
     * default: no amount read, several payments of that amount, none, or a
     * replaced payment while the open one already has a receipt, and nothing
     * is chosen. The receipt states no currency: payments of the same amount
     * in different currencies are several.
     *
     * @param  Collection<int, Payment>  $replaced
     * @return array{0: ?Payment, 1: ?string, 2: Collection<int, Payment>}
     */
    private function matchByAmount(Customer $customer, Collection $replaced, ?float $amount): array
    {
        $candidates = $this->openPayments($customer)[0]->concat($replaced)->sortBy('id')->values();
        $matches = $amount === null ? collect() : $candidates->filter(fn (Payment $payment) => abs((float) $payment->amount - $amount) < 0.005)->values();

        $reason = match (true) {
            $amount === null => 'amount_unreadable',
            $matches->isEmpty() => 'no_amount_match',
            $matches->count() > 1 => 'several_matches',
            default => null,
        };

        $match = $reason === null ? $matches->first() : null;

        // Bringing a replaced payment back closes the open one: only a pending one with no receipt may give way.
        if ($match?->wasReplaced() && $candidates->contains(fn (Payment $payment) => $payment->isOpen() && $payment->product_id === $match->product_id
            && ($payment->status !== Payment::STATUS_PENDING || $payment->receipt_submitted_at !== null))) {
            [$match, $reason] = [null, 'open_payment_in_review'];
        }

        return [$match, $reason, $candidates];
    }

    /**
     * A receipt no payment could be chosen for: nothing is attached, changed
     * or activated. The admin gets the image, the amount read and the real
     * candidates, once per message; the customer is told it is reviewed by hand.
     *
     * @param  Collection<int, Payment>  $candidates
     */
    private function unmatched(Store $store, string $from, Customer $customer, string $wamid, string $mediaId, string $path, array $read, Collection $candidates, string $reason, ?string $domainKey): void
    {
        $alertedKey = 'receipt-unmatched:'.$store->id.':'.hash('sha256', $wamid);

        try {
            // What the admin's «Asociar P-n» needs to attach exactly this receipt later: its message and media, never the image.
            $token = $this->rememberUnmatched($store, [
                'wamid' => $wamid, 'media_id' => $mediaId, 'customer_id' => $customer->id, 'candidate_ids' => $candidates->pluck('id')->all(),
                'read' => ['status' => $read['status'], 'data' => array_diff_key($read['data'], ['payer_name' => true])],
            ]);

            Log::warning('RECEIPT_UNMATCHED', ['store_id' => $store->id, 'customer_id' => $customer->id, 'reason' => $reason, 'candidate_ids' => $candidates->pluck('id')->all(), 'reference' => $token]);

            if (! Cache::has($alertedKey)) {
                $this->alertUnmatched($store, $customer, $path, $read['data']['amount'], $candidates, $token);
                Cache::put($alertedKey, true, now()->addDay());
            }
        } finally {
            Storage::disk(PaymentReceipt::DISK)->delete($path);
        }

        $this->reply($store, $from, 'Recibí tu comprobante. Como hubo un cambio de plan, lo revisaremos manualmente y te avisaremos cuando quede confirmado.', $domainKey, '[comprobante de pago]');
    }

    /**
     * @param  Collection<int, Payment>  $candidates
     */
    private function alertUnmatched(Store $store, Customer $customer, string $path, ?float $amount, Collection $candidates, string $token): void
    {
        $admin = $store->adminWhatsApp();

        if ($admin === null) {
            Log::warning('PAYMENT_ALERT_NO_ADMIN', ['store_id' => $store->id, 'payment_id' => $candidates->first()?->id]);

            return;
        }

        $read = $amount !== null ? $this->money($amount, (string) ($candidates->first()?->currency ?? '')) : 'no disponible';
        $lines = [
            "🧾 Comprobante sin asociar — revisión manual ({$token})",
            "Tienda: {$store->name}",
            "Cliente: {$customer->customer_phone}",
            "Valor leído (sin moneda): {$read}",
            '',
            'Pagos posibles:',
            ...$candidates->map(fn (Payment $payment) => "- P-{$payment->id} · {$payment->product->name} · plan {$payment->plan_name} · "
                .$this->money((float) $payment->amount, $payment->currency).' · '.($payment->wasReplaced() ? 'vencido por cambio de plan' : $payment->status))->all(),
            '',
            'No se asoció a ningún pago ni se activó nada.',
            "Cuando sepas cuál es, responde «Asociar P-número {$token}» y después confírmalo como siempre.",
        ];

        $sent = $this->messenger->send($store, $admin, implode("\n", $lines), WhatsAppTemplate::EVENT_PAYMENT_ALERT, [
            'store_name' => $store->name,
            'payment_ref' => $candidates->map(fn (Payment $payment) => "P-{$payment->id}")->implode(' / '),
            'product_name' => $candidates->map(fn (Payment $payment) => $payment->product->name)->unique()->implode(' / '),
            'amount' => $read,
            'customer_phone' => $customer->customer_phone,
        ]);

        if ($sent === null) {
            throw new RuntimeException("The alert for an unmatched receipt of customer [{$customer->id}] could not be sent.");
        }

        // The image itself, best effort (only delivered inside the 24-hour window).
        WhatsAppService::sendWhatsAppImage($admin, $path, $store, 'Comprobante sin asociar', PaymentReceipt::DISK);
    }

    /**
     * The admin's «Asociar P-n» for a receipt that could not be attached
     * (unmatched()): it attaches exactly that receipt, downloaded again from
     * its WhatsApp media, to one of the payments its alert listed, through
     * the same record() as any receipt, and alerts the admin for «Sí/No P-n»
     * as always. It activates nothing. Without a reference, the payment must
     * belong to one single pending receipt; anything ambiguous, already done
     * or no longer possible changes nothing and says why.
     *
     * @return string the reply for the admin
     */
    public function associate(Store $store, int $paymentId, ?string $token): string
    {
        $pending = $this->pendingUnmatched($store);
        $entries = $token !== null
            ? array_intersect_key($pending, [$token => true])
            : array_filter($pending, fn (array $entry) => in_array($paymentId, $entry['candidate_ids'], true));

        if ($entries === []) {
            return $token !== null
                ? "No encuentro el comprobante {$token} pendiente de asociar. No se cambió nada."
                : "El pago P-{$paymentId} no es uno de los pagos posibles de ningún comprobante pendiente de asociar. No se cambió nada.";
        }

        if (count($entries) > 1) {
            return "El pago P-{$paymentId} aparece en varios comprobantes pendientes: ".implode(', ', array_keys($entries))
                .". Responde «Asociar P-{$paymentId} C-código» para indicar cuál. No se cambió nada.";
        }

        $token = (string) array_key_first($entries);
        $entry = $entries[$token];

        if (! in_array($paymentId, $entry['candidate_ids'], true)) {
            return "P-{$paymentId} no es uno de los pagos posibles de {$token}: "
                .implode(', ', array_map(fn ($id) => "P-{$id}", $entry['candidate_ids'])).'. No se cambió nada.';
        }

        if ($existing = PaymentReceipt::where('store_id', $store->id)->where('source_wamid', $entry['wamid'])->first()) {
            return $this->associated($store, $token, $existing, true);
        }

        $payment = Payment::where('store_id', $store->id)->find($paymentId);
        $customer = Customer::where('store_id', $store->id)->find($entry['customer_id']);

        if ($payment === null || $customer === null || $payment->customer_id !== $customer->id) {
            return "No encuentro el pago P-{$paymentId} de ese cliente en esta tienda. No se cambió nada.";
        }

        $download = WhatsAppService::downloadMedia($entry['media_id'], $store);

        if ($download === null) {
            return "No pude descargar de nuevo la imagen de {$token}: WhatsApp ya no la tiene disponible. Pide al cliente que la reenvíe. No se cambió nada.";
        }

        $local = Storage::disk('local');

        try {
            $contents = (string) $local->get($download);
            $mime = $local->mimeType($download) ?: 'image/jpeg';
            $hash = hash('sha256', $contents);
            $path = "{$store->id}/".Str::uuid().'.'.(pathinfo($download, PATHINFO_EXTENSION) ?: 'jpg');
        } finally {
            $local->delete($download);
        }

        if (PaymentReceipt::where('store_id', $store->id)->where('file_hash', $hash)->exists()) {
            return 'Esa misma imagen ya está asociada a otro pago. No se cambió nada.';
        }

        Storage::disk(PaymentReceipt::DISK)->put($path, $contents);

        try {
            $receipt = $this->record($customer, $payment, $entry['wamid'], $path, $mime, strlen($contents), $hash, $entry['read']);
        } catch (Throwable $e) {
            Storage::disk(PaymentReceipt::DISK)->delete($path);

            if ($e instanceof UniqueConstraintViolationException && ($same = PaymentReceipt::where('store_id', $store->id)->where('source_wamid', $entry['wamid'])->first())) {
                return $this->associated($store, $token, $same, true);
            }

            if ($e instanceof PaymentNoLongerOpen) {
                return "El pago P-{$paymentId} ya no puede recibir este comprobante (está {$payment->fresh()->status} o el cliente tiene otro pago en revisión). No se cambió nada.";
            }

            throw $e;
        }

        Log::info('RECEIPT_ASSOCIATED_BY_ADMIN', ['store_id' => $store->id, 'payment_id' => $paymentId, 'receipt_id' => $receipt->id, 'reference' => $token]);

        return $this->associated($store, $token, $receipt, false);
    }

    /** Once attached: the usual alert, once (a retry resumes it), and the reference is done with. */
    private function associated(Store $store, string $token, PaymentReceipt $receipt, bool $already): string
    {
        $payment = $receipt->payment()->with('product')->firstOrFail();

        if ($receipt->alerted_at === null) {
            $this->alertAdmin($store, $payment, $receipt);
        }

        $this->forgetUnmatched($store, $token);

        return $already
            ? "El comprobante {$token} ya está asociado al pago P-{$payment->id}. No se cambió nada."
            : "Asocié el comprobante {$token} al pago P-{$payment->id}. Te envié sus datos: verifícalo y responde «Sí P-{$payment->id}» o «No P-{$payment->id}».";
    }

    private function unmatchedKey(Store $store): string
    {
        return "receipts-unmatched:{$store->id}";
    }

    /**
     * Keeps the receipt for the admin under its reference, decided under the
     * lock: its message's hash, so a retry finds the same one, made longer
     * while another pending receipt holds it, so none is ever overwritten.
     *
     * @param  array<string, mixed>  $entry
     * @return string the reference, for the alert
     */
    private function rememberUnmatched(Store $store, array $entry): string
    {
        return Cache::lock($this->unmatchedKey($store).':lock', 10)->block(5, function () use ($store, $entry) {
            $pending = $this->pendingUnmatched($store);
            $hash = strtoupper(hash('sha256', $entry['wamid']));

            for ($length = self::REFERENCE_LENGTH; ; $length++) {
                $token = 'C-'.substr($hash, 0, $length);

                if (! isset($pending[$token]) || $pending[$token]['wamid'] === $entry['wamid']) {
                    break;
                }
            }

            Cache::put($this->unmatchedKey($store), [...$pending, $token => $entry + ['at' => now()->timestamp]], now()->addDays(self::UNMATCHED_DAYS));

            return $token;
        });
    }

    private function forgetUnmatched(Store $store, string $token): void
    {
        Cache::lock($this->unmatchedKey($store).':lock', 10)->block(5, function () use ($store, $token) {
            Cache::put($this->unmatchedKey($store), array_diff_key($this->pendingUnmatched($store), [$token => true]), now()->addDays(self::UNMATCHED_DAYS));
        });
    }

    /** @return array<string, array<string, mixed>> the store's receipts still waiting for the admin, by reference */
    private function pendingUnmatched(Store $store): array
    {
        $since = now()->subDays(self::UNMATCHED_DAYS)->timestamp;

        return array_filter((array) Cache::get($this->unmatchedKey($store), []), fn ($entry) => is_array($entry) && ($entry['at'] ?? 0) >= $since);
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

            // The receipt is of the payment a plan change replaced: it comes back for review, and the pending
            // payment that replaced it gives way first, so there is never a second open one.
            if ($locked->wasReplaced()) {
                $current = Payment::where('customer_id', $locked->customer_id)->where('product_id', $locked->product_id)
                    ->whereIn('status', Payment::OPEN_STATUSES)->lockForUpdate()->get();

                if ($current->contains(fn (Payment $open) => $open->status !== Payment::STATUS_PENDING || $open->receipt_submitted_at !== null)) {
                    throw new PaymentNoLongerOpen;
                }

                $current->each(fn (Payment $open) => $open->update(['status' => Payment::STATUS_EXPIRED]));
                $locked->update(['status' => Payment::STATUS_UNDER_REVIEW]);

                Log::info('PAYMENT_REPLACED_RECEIPT', ['store_id' => $locked->store_id, 'payment_id' => $locked->id, 'expired_payment_ids' => $current->pluck('id')->all()]);
            }

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
