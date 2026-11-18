<?php

namespace App\Platform\Commerce;

use App\Models\Product;
use Illuminate\Support\Facades\Storage;

/**
 * A product's optional audio opening: after the opening's text
 * (OpeningMessage), its audio and then its follow-up text, all configured
 * on the product. This only says whether it applies and what it is; when
 * it is the first time stays the opening's caller's, and the sending the
 * pipeline's.
 *
 * WhatsApp plays as native audio only some formats: what the file is comes
 * from its first bytes, never from its name or the type declared on upload
 * (mimeType(), which says how far each format is checked).
 */
class OpeningAudio
{
    /** Private: the file only ever goes to Meta, never to a public URL. */
    public const DISK = 'local';

    public const DIRECTORY = 'product-audio';

    /** Below Meta's 16 MB for audio and Livewire's 12 MB upload limit. */
    public const MAX_KILOBYTES = 10240;

    /** What the panel accepts on upload; the file's own bytes decide afterwards (mimeType()). */
    public const ACCEPTED_TYPES = ['audio/ogg', 'audio/opus', 'audio/mpeg', 'audio/mp3', 'audio/mp4', 'audio/x-m4a', 'audio/m4a', 'audio/aac', 'audio/x-aac', 'audio/amr'];

    /**
     * The audio opening of the product, or null when it has none turned on.
     * The file may be missing or unusable: the caller logs that and goes on.
     *
     * @return array{path: ?string, followup: ?string}|null
     */
    public function for(Product $product): ?array
    {
        if (! $product->opening_audio_enabled || ! $product->requiresSubscription()) {
            return null;
        }

        $followup = trim((string) $product->opening_audio_followup);

        return [
            'path' => filled($product->opening_audio_path) ? (string) $product->opening_audio_path : null,
            'followup' => $followup !== '' ? $followup : null,
        ];
    }

    /** The WhatsApp audio type of a stored file, or null when it is missing or not one WhatsApp plays. */
    public function storedType(?string $path): ?string
    {
        if ($path === null || ! Storage::disk(self::DISK)->exists($path)) {
            return null;
        }

        return self::mimeType(Storage::disk(self::DISK)->path($path));
    }

    /**
     * The WhatsApp audio type of a file from its first bytes, or null for
     * anything else, whatever its name says. How far each check goes:
     * - OGG: container and codec, Opus (the only OGG WhatsApp accepts);
     * - MP3, AAC (ADTS) and AMR: their own format, which fixes the codec;
     * - MP4/M4A: only the container ("ftyp"). That its audio is AAC, or that
     *   it has no video, is not checked: a partial check, which only a real
     *   send through Meta confirms.
     */
    public static function mimeType(string $absolutePath): ?string
    {
        $handle = @fopen($absolutePath, 'rb');

        if ($handle === false) {
            return null;
        }

        $head = (string) fread($handle, 64);
        fclose($handle);

        if (strlen($head) < 4) {
            return null;
        }

        $first = ord($head[0]);
        $second = ord($head[1]);

        return match (true) {
            // The Opus header is in the first page of the stream; an OGG of Vorbis has none.
            str_starts_with($head, 'OggS') => str_contains($head, 'OpusHead') ? 'audio/ogg' : null,
            str_starts_with($head, '#!AMR-WB') => null,
            str_starts_with($head, "#!AMR\n") => 'audio/amr',
            str_starts_with($head, 'ID3') => 'audio/mpeg',
            // Partial: the MP4 container only, not its codec.
            substr($head, 4, 4) === 'ftyp' => 'audio/mp4',
            // A frame without tags: MPEG layer III, or AAC in ADTS (layer bits 00).
            $first === 0xFF && ($second & 0xE0) === 0xE0 && ($second & 0x06) === 0x02 => 'audio/mpeg',
            $first === 0xFF && ($second & 0xF6) === 0xF0 => 'audio/aac',
            default => null,
        };
    }
}
