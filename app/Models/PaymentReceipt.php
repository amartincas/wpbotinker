<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

/**
 * Proof of payment for an already started payment. Its file, on the
 * private 'receipts' disk, stays available to the reviewer while the
 * payment is open (including "not reflected") and is deleted once the
 * payment is confirmed or rejected; the metadata remain for traceability.
 */
#[Fillable(['store_id', 'payment_id', 'source_wamid', 'file_path', 'mime_type', 'file_size', 'file_hash', 'extracted_data'])]
#[Hidden(['extracted_data'])]
class PaymentReceipt extends Model
{
    public const DISK = 'receipts';

    /** Extracted fields that identify a person and are dropped with the file. */
    private const PERSONAL_FIELDS = ['payer_name'];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'extracted_data' => 'encrypted:array',
            'file_deleted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $receipt) {
            $payment = Payment::find($receipt->payment_id);

            if ($payment === null || ! $payment->isOpen()) {
                throw new InvalidArgumentException('A receipt belongs to an open payment.');
            }

            if ($receipt->store_id !== null && (int) $receipt->store_id !== $payment->store_id) {
                throw new InvalidArgumentException("Payment [{$payment->id}] does not belong to store [{$receipt->store_id}].");
            }

            $receipt->store_id = $payment->store_id;
        });

        static::updating(function (self $receipt) {
            if ($receipt->isDirty(['store_id', 'payment_id', 'file_hash', 'source_wamid'])) {
                throw new InvalidArgumentException('The payment, file hash and source message of a receipt never change.');
            }

            // A file is attached when the receipt is created; afterwards it
            // can only be removed, never replaced or restored.
            if ($receipt->isDirty('file_path') && $receipt->file_path !== null) {
                throw new InvalidArgumentException('A receipt file cannot be replaced.');
            }
        });
    }

    public function hasFile(): bool
    {
        return $this->file_path !== null;
    }

    /**
     * Deletes the file and the personal data extracted from it once the
     * review is over, keeping the rest as a record. Safe to repeat: a file
     * that is already gone counts as deleted. If the disk refuses, nothing
     * is marked and a later call retries.
     */
    public function purgeAfterReview(): void
    {
        $payment = Payment::findOrFail($this->payment_id);

        if (! $payment->isReviewClosed()) {
            throw new LogicException("Payment [{$payment->id}] is still under review; its receipt must be kept.");
        }

        $data = $this->extracted_data ?? [];
        $hasPersonalData = array_intersect_key($data, array_flip(self::PERSONAL_FIELDS)) !== [];

        if ($this->file_path === null && ! $hasPersonalData) {
            return;
        }

        if ($this->file_path !== null) {
            $disk = Storage::disk(self::DISK);

            if ($disk->exists($this->file_path) && ! $disk->delete($this->file_path)) {
                throw new RuntimeException("The file of receipt [{$this->id}] could not be deleted.");
            }
        }

        $this->forceFill([
            'file_path' => null,
            'file_deleted_at' => $this->file_deleted_at ?? now(),
            'extracted_data' => array_diff_key($data, array_flip(self::PERSONAL_FIELDS)) ?: null,
        ])->save();
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
