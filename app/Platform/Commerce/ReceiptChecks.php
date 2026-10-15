<?php

namespace App\Platform\Commerce;

use App\Models\Payment;
use Carbon\Carbon;
use Throwable;

/**
 * Deterministic warnings for the reviewer (adapted from WpBotTrainer's
 * PaymentValidationService). They never confirm or reject anything.
 * Fixed from WpBotTrainer: the reference check compares against
 * payments.reference, which is now actually stored, within the store.
 */
class ReceiptChecks
{
    private const STALE_DAYS = 15;

    public const MESSAGES = [
        'ocr_unavailable' => 'La lectura automática no está disponible para esta tienda.',
        'ocr_failed' => 'No se pudo leer el comprobante automáticamente.',
        'uncertain_extraction' => 'La lectura automática no es segura.',
        'amount_unreadable' => 'No se pudo leer el valor.',
        'amount_mismatch' => 'El valor leído no coincide con el esperado.',
        'reference_missing' => 'No se encontró número de referencia.',
        'reference_already_used' => 'Esa referencia ya aparece en otro pago de la tienda.',
        'date_unreadable' => 'No se pudo leer la fecha.',
        'stale_receipt' => 'El comprobante parece tener más de 15 días.',
    ];

    /**
     * @param  array<string, mixed>  $extracted
     * @return list<string> flag keys (see MESSAGES)
     */
    public function flags(Payment $payment, string $readStatus, array $extracted): array
    {
        if ($readStatus === ReceiptReader::UNAVAILABLE) {
            return ['ocr_unavailable'];
        }

        if ($readStatus === ReceiptReader::FAILED) {
            return ['ocr_failed'];
        }

        $flags = [];

        if ($extracted['uncertain'] ?? true) {
            $flags[] = 'uncertain_extraction';
        }

        if ($extracted['amount'] === null) {
            $flags[] = 'amount_unreadable';
        } elseif ((int) round($extracted['amount'] * 100) !== (int) round((float) $payment->amount * 100)) {
            $flags[] = 'amount_mismatch';
        }

        $reference = Payment::normalizeReference($extracted['reference']);

        if ($reference === null) {
            $flags[] = 'reference_missing';
        } elseif (Payment::where('store_id', $payment->store_id)->where('reference', $reference)->whereKeyNot($payment->id)
            ->whereIn('status', [Payment::STATUS_CONFIRMED, Payment::STATUS_UNDER_REVIEW, Payment::STATUS_NOT_REFLECTED])->exists()) {
            $flags[] = 'reference_already_used';
        }

        $date = $extracted['date'] === null ? null : $this->date($extracted['date']);

        if ($date === null) {
            $flags[] = 'date_unreadable';
        } elseif ($date->diffInDays(now(), true) > self::STALE_DAYS) {
            $flags[] = 'stale_receipt';
        }

        return $flags;
    }

    /** Day-first formats first (Colombian receipts): "05/03/2026" is 5 March. */
    private function date(string $value): ?Carbon
    {
        foreach (['d/m/Y', 'd-m-Y', 'd.m.Y', 'Y-m-d', 'd/m/y'] as $format) {
            try {
                $date = Carbon::createFromFormat('!'.$format, trim($value));
            } catch (Throwable) {
                continue;
            }

            if ($date && $date->format($format) === trim($value)) {
                return $date;
            }
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
