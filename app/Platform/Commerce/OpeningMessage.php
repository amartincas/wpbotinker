<?php

namespace App\Platform\Commerce;

use App\Models\Product;
use Illuminate\Support\Str;

/**
 * A product's configured opening: the text sent exactly as written as the
 * first free reply when the customer's message is one of its triggers
 * (typically an ad's pre-filled message). The text and the triggers are
 * the product's configuration; this only decides whether they apply.
 * Whether the customer may still get a first free reply is SampleLedger's.
 */
class OpeningMessage
{
    /** The opening for this message, or null when the product has none or the message is not a trigger. */
    public function for(Product $product, string $message): ?string
    {
        $opening = trim((string) $product->opening_message);

        if ($opening === '' || ! $product->requiresSubscription()) {
            return null;
        }

        $text = self::normalize($message);

        if ($text === '') {
            return null;
        }

        foreach ((array) ($product->opening_triggers ?? []) as $trigger) {
            if (self::normalize((string) $trigger) === $text) {
                return $opening;
            }
        }

        return null;
    }

    /**
     * Lowercase, no accents, punctuation or emoji, single spaces, and without
     * the greetings and courtesies around it: «¡Hola! Quiero conocer…, por
     * favor» = «quiero conocer…». A text made only of them stays as it is.
     */
    public static function normalize(string $text): string
    {
        $text = trim(preg_replace('/[^a-z0-9]+/', ' ', Str::ascii(mb_strtolower($text))));
        $greeting = '(?:hola|buenas tardes|buenas noches|buenos dias|buen dia|buenas|saludos)';
        $courtesy = '(?:por favor|muchas gracias|gracias)';

        if (preg_match("/^(?:(?:{$greeting}|{$courtesy})(?:\s+|$))+$/", $text) === 1) {
            return $text;
        }

        $text = preg_replace("/^(?:{$greeting}\s+)+/", '', $text);

        return preg_replace("/(?:\s+{$courtesy})+$/", '', $text);
    }
}
