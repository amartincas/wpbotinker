<?php

namespace App\Platform\Commerce;

use App\Models\Product;
use Illuminate\Support\Str;

/**
 * A product's configured opening: the text sent exactly as written the first
 * time the customer talks about the product, whatever their message says. The
 * product is ProductResolver's (the ad the customer came from first, then the
 * message); when it is the first time is the caller's: a funnel not started
 * yet, or the first free reply without one. The text is the product's
 * configuration; its triggers are no longer required for it.
 */
class OpeningMessage
{
    /** The product's opening, or null when it has none. */
    public function for(Product $product): ?string
    {
        $opening = trim((string) $product->opening_message);

        return $opening !== '' && $product->requiresSubscription() ? $opening : null;
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
