<?php

namespace App\Platform\Commerce;

use App\Factories\AIServiceFactory;
use App\Models\Store;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reads a payment receipt image with the store's own AI provider
 * (adapted from WpBotTrainer's ReceiptExtractionService). The AI only
 * EXTRACTS what is visible; it never decides whether the payment is valid:
 * that is the store admin's decision, after checking their bank.
 */
class ReceiptReader
{
    /** Amount or reference found. */
    public const READ = 'read';

    /** Read, but nothing that looks like a receipt (no amount, no reference). */
    public const UNREADABLE = 'unreadable';

    /** The store's provider cannot read images. */
    public const UNAVAILABLE = 'unavailable';

    /** The provider failed (error, timeout, invalid answer). */
    public const FAILED = 'failed';

    private const EMPTY = [
        'amount' => null, 'date' => null, 'time' => null, 'reference' => null, 'entity' => null, 'payer_name' => null, 'uncertain' => true,
    ];

    /**
     * @return array{status: string, data: array{amount: ?float, date: ?string, time: ?string, reference: ?string, entity: ?string, payer_name: ?string, uncertain: bool}}
     */
    public function read(Store $store, string $absolutePath, string $mimeType): array
    {
        try {
            $reader = AIServiceFactory::makeImageReader($store);

            if ($reader === null) {
                return ['status' => self::UNAVAILABLE, 'data' => self::EMPTY];
            }

            $raw = $reader->analyzeImage(base64_encode((string) file_get_contents($absolutePath)), $mimeType, $this->prompt());
            $data = $this->parse($raw);
        } catch (Throwable $e) {
            Log::warning('RECEIPT_READ_FAILED', ['store_id' => $store->id, 'error' => $e::class]);

            return ['status' => self::FAILED, 'data' => self::EMPTY];
        }

        if ($data === null) {
            return ['status' => self::FAILED, 'data' => self::EMPTY];
        }

        $status = $data['amount'] === null && $data['reference'] === null ? self::UNREADABLE : self::READ;

        return ['status' => $status, 'data' => $data];
    }

    private function prompt(): string
    {
        return <<<'PROMPT'
Eres un asistente que EXTRAE datos de un comprobante de pago (transferencia, Nequi, Daviplata, Llave, etc.). NUNCA inventes un valor que no esté visible.

Responde EXCLUSIVAMENTE con un JSON (sin texto adicional, sin markdown) con esta forma exacta:
{
  "amount": <número, solo el monto sin símbolo de moneda> | null,
  "date": "<fecha tal como aparece>" | null,
  "time": "<hora tal como aparece>" | null,
  "reference": "<número de referencia o transacción>" | null,
  "entity": "<banco o billetera>" | null,
  "payer_name": "<nombre del pagador, SOLO si aparece>" | null,
  "uncertain": true | false
}

Reglas:
- Si un dato no es legible o no aparece, su valor es null; nunca lo adivines.
- "uncertain": true si la imagen está borrosa, incompleta o tienes dudas sobre algún valor.
- Si la imagen no es un comprobante de pago, devuelve todos los valores en null.
- Nunca decidas si el pago es válido ni si el monto es el esperado.
PROMPT;
    }

    /**
     * @return array<string, mixed>|null null when the answer is not the expected JSON
     */
    private function parse(string $raw): ?array
    {
        $cleaned = trim((string) preg_replace(['/^```(?:json)?/i', '/```$/'], '', trim($raw)));
        $decoded = json_decode($cleaned, true);

        if (! is_array($decoded)) {
            return null;
        }

        $text = fn (string $key) => is_string($decoded[$key] ?? null) && trim($decoded[$key]) !== '' ? mb_substr(trim($decoded[$key]), 0, 100) : null;

        return [
            'amount' => is_numeric($decoded['amount'] ?? null) ? (float) $decoded['amount'] : null,
            'date' => $text('date'),
            'time' => $text('time'),
            'reference' => $text('reference'),
            'entity' => $text('entity'),
            'payer_name' => $text('payer_name'),
            'uncertain' => (bool) ($decoded['uncertain'] ?? true),
        ];
    }
}
