<?php

namespace App\Platform\Commerce;

use Illuminate\Support\Str;

/**
 * Deterministic check of commercial text against what only the Core may
 * state: prices, amounts and conditions come from the plans
 * (CommerceResponder), access from AccessGate. It recognizes; it never
 * rewrites.
 *
 * - amount(): prices, amounts, currencies, percentages and "2x1" offers.
 *   Applies to resources sent verbatim (upload and delivery) and to the
 *   AI's wording. Years (1900–2099) and small numbers ("3 sesiones",
 *   "30 días") are not amounts.
 * - violation(): amount() plus what the AI's wording may never claim:
 *   gratuity, discounts, access granted, and the classifier's own price and
 *   payment-status terms (CommerceResponder::priceTerms()).
 *
 * Conservative on purpose: a doubtful text is reported, and its caller
 * keeps the deterministic reply.
 */
final class CommercialClaims
{
    /** @var array<string, string> reason => pattern */
    private const AMOUNT_PATTERNS = [
        'currency' => '/[$€£]|\b(?:cop|usd|eur|mxn|ars|clp|pesos?|d[oó]lares?|euros?)\b/iu',
        'amount' => '/\b\d{1,3}(?:[.,]\d{3})+\b|\b\d{5,}\b|\b(?!(?:19|20)\d{2}\b)\d{4}\b/u',
        'percent' => '/\d\s*%|\bpor\s*ciento\b/iu',
        'offer' => '/\b\d+\s*x\s*\d+\b/iu',
    ];

    /** @var array<string, string> reason => pattern */
    private const CLAIM_PATTERNS = [
        'free' => '/\bgratis\b|\bgratuit(?:[oa]s?|amente)\b|\bsin\s+(?:costo|cargo|pagar)\b|\bno\s+(?:cuesta|tiene\s+costo)\b/iu',
        'discount' => '/\bdescuentos?\b|\bpromoci[oó]n(?:es)?\b|\bpromos?\b|\brebajas?\b|\bcup[oó]n(?:es)?\b/iu',
        // Access granted now, never a future one ("tendrás acceso" describes the service).
        'access' => '/\b(?:ya\s+)?(?:tienes|obtienes|te\s+(?:doy|damos|activo|activamos|habilito|habilitamos|concedo|concedemos|regalo|regalamos|dejo))\s+(?:(?:el|tu|un)\s+)?acceso\b'
            .'|\b(?:tu\s+)?(?:acceso|suscripci[oó]n|cuenta)\s+(?:ya\s+)?(?:est[aá]|queda|qued[oó])\s+(?:activ|habilitad|list)/iu',
    ];

    /** The kind of price or amount the text states, or null. */
    public function amount(string $text): ?string
    {
        foreach (self::AMOUNT_PATTERNS as $reason => $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return $reason;
            }
        }

        return null;
    }

    /**
     * What a funnel's reply states that only the Core may state, or null.
     * A funnel converses about any vertical, where figures and "gratis" are
     * ordinary ("2.000 calorías", "10% de grasa", "Decreto 1072", "un consejo
     * gratis"): they count only when the same sentence makes them a commercial
     * condition, a price, a discount or the product given away.
     *
     * @param  string  $productName  what "gratis" must not be said about
     */
    public function funnelViolation(string $text, string $productName = ''): ?string
    {
        $productWords = array_filter(
            preg_split('/[^a-z0-9]+/', Str::ascii(mb_strtolower($productName)), -1, PREG_SPLIT_NO_EMPTY),
            fn (string $word) => strlen($word) >= 4,
        );
        $subjects = implode('|', ['suscripci[oa]n', 'membres[ií]a', 'planes', 'plan', 'mensualidad', 'acceso', ...array_map(fn (string $word) => preg_quote($word, '/'), $productWords)]);

        foreach (preg_split('/(?<![\d])[.!?]+(?![\d])|\n+/u', Str::ascii(mb_strtolower($text))) as $sentence) {
            $hasFigure = preg_match('/\d/', $sentence) === 1;

            $reason = match (true) {
                preg_match('/[$]\s*\d|\d\s*[$]|\d[\d.,]*\s*(?:cop|usd|eur|mxn|ars|clp|pesos?|dolares?|euros?)\b|\b(?:cop|usd|eur|mxn|ars|clp)\s*\d/', $sentence) === 1 => 'currency',
                $hasFigure && preg_match('/\b(?:precio|precios|cuesta|cuestan|costo|costos|tarifa|tarifas|mensualidad|inversion|valor|pagas|pagar|paga)\b|\bvale\s+\d/', $sentence) === 1 => 'price',
                preg_match('/\b(?:descuentos?|promocion(?:es)?|promos?|rebajas?|cupon(?:es)?|ofertas?)\b/', $sentence) === 1
                    && ($hasFigure || preg_match('/\b(?:tienes|tenemos|ofrecemos|te\s+doy|te\s+damos|obtienes|aplica|aplicamos|hay)\b/', $sentence) === 1) => 'discount',
                preg_match('/\bgratis\b|\bgratuit(?:[oa]s?|amente)\b|\bsin\s+(?:costo|cargo|pagar)\b|\bno\s+(?:cuesta|tiene\s+costo)\b/', $sentence) === 1
                    && preg_match("/\\b(?:{$subjects})\\b/", $sentence) === 1 => 'free',
                preg_match('/\b(?:pago|comprobante)\b.*\b(?:confirmad|aprobad|verificad|recibid|reflejad)\w*/', $sentence) === 1 => 'payment',
                preg_match(self::CLAIM_PATTERNS['access'], $sentence) === 1 => 'access',
                default => null,
            };

            if ($reason !== null) {
                return $reason;
            }
        }

        return null;
    }

    /** What the text states that only the Core may state, or null. */
    public function violation(string $text): ?string
    {
        if ($reason = $this->amount($text)) {
            return $reason;
        }

        foreach (self::CLAIM_PATTERNS as $reason => $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return $reason;
            }
        }

        $words = preg_split('/[^a-z0-9]+/', Str::ascii(mb_strtolower($text)), -1, PREG_SPLIT_NO_EMPTY);

        return array_intersect($words, CommerceResponder::priceTerms()) !== [] ? 'price_term' : null;
    }
}
