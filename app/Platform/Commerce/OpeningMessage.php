<?php

namespace App\Platform\Commerce;

use App\Models\Product;
use Illuminate\Support\Str;

/**
 * A product's configured opening: the text sent exactly as written as the
 * first free reply when the customer came from the product's ad, or when
 * their message is one of its triggers (typically an ad's pre-filled
 * message) or the start of only one trigger of the store, as a pre-filled
 * message sent before it was complete. The text and the triggers are the
 * product's configuration; this only decides whether they apply. Which
 * product it is stays ProductResolver's, and whether the customer may still
 * get a first free reply, SampleLedger's.
 */
class OpeningMessage
{
    /** What the start of a trigger needs to stand for it: less says too little to tell which one. */
    private const MIN_PARTIAL_WORDS = 3;

    private const MIN_PARTIAL_CHARACTERS = 15;

    /**
     * The opening for this message, or null when the product has none or the message is not a trigger.
     *
     * @param  bool  $fromAd  the product was resolved from the ad the customer came from: its pre-filled
     *                        text may have arrived cut, changed or empty, and the ad already says which product
     */
    public function for(Product $product, string $message, bool $fromAd = false): ?string
    {
        $opening = trim((string) $product->opening_message);

        if ($opening === '' || ! $product->requiresSubscription()) {
            return null;
        }

        if ($fromAd) {
            return $opening;
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

        return $this->startsOnlyTrigger($product, $text) ? $opening : null;
    }

    /**
     * The message is the start of one trigger of this product and of no other
     * trigger of the store: never a guess between two of them.
     */
    private function startsOnlyTrigger(Product $product, string $text): bool
    {
        if (count(explode(' ', $text)) < self::MIN_PARTIAL_WORDS || strlen($text) < self::MIN_PARTIAL_CHARACTERS) {
            return false;
        }

        $started = Product::where('store_id', $product->store_id)->whereNotNull('opening_triggers')->get(['id', 'opening_triggers'])
            ->flatMap(fn (Product $candidate) => collect((array) $candidate->opening_triggers)
                ->map(fn ($trigger) => self::normalize((string) $trigger))
                ->filter(fn (string $trigger) => str_starts_with($trigger, $text))
                ->map(fn () => $candidate->id));

        return $started->count() === 1 && $started->first() === $product->id;
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
