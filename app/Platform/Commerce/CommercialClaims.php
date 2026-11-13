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

    /** What stands for the product's whole name while checking a reply: it cannot be read as an ordinary word. */
    private const PRODUCT_REFERENCE = 'referenciadelproducto';

    /** Shorter names are too likely to be ordinary words. */
    private const MIN_NAME_LENGTH = 3;

    /** Words of buying or paying a fallback reply never uses (fallbackReplyViolation()). */
    private const PURCHASE_TERMS = ['pagar', 'pago', 'pagos', 'comprar', 'compra', 'compras', 'precio', 'precios', 'tarifa', 'tarifas', 'mensualidad', 'suscripcion', 'suscribete', 'suscribirte', 'oferta', 'ofertas', 'descuento', 'promocion'];

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

    /**
     * Why a guided funnel's reply after the free replies cannot go out, or
     * null. It must invite to the plans and every part of it must be allowed: an
     * opening recognition, a short mention of the service or a courtesy.
     * Anything else counts as service, whatever else the sentence mentions.
     * The product's whole name or an alias mentions the service; one word of it
     * does not, as it may be an ordinary word of the topic. Conservative on
     * purpose, like the rest of this class: a doubtful text gets the Core's
     * fixed reply. This reduces what a model could give away; it cannot prove
     * there is none.
     *
     * @param  string  $productName  what "gratis" must not be said about
     * @param  list<string>  $aliases  other whole names of the product
     */
    public function limitReplyViolation(string $text, string $productName = '', array $aliases = []): ?string
    {
        if ($reason = $this->funnelViolation($text, $productName)) {
            return $reason;
        }

        $rules = (array) config('botinker.sales_funnels.guided.after_limit_rules', []);
        $names = $this->namePatterns([$productName, ...$aliases]);
        $plain = preg_replace($names, self::PRODUCT_REFERENCE, Str::ascii(mb_strtolower($text)));
        $rules['service_terms'] = [...(array) ($rules['service_terms'] ?? []), self::PRODUCT_REFERENCE];
        $sentences = array_values(array_filter(array_map('trim', preg_split('/[.!?;:]+|\n+/u', $plain)), fn (string $sentence) => $sentence !== ''));

        return match (true) {
            mb_strlen($text) > (int) ($rules['max_characters'] ?? 400) => 'too_long',
            count($sentences) > (int) ($rules['max_sentences'] ?? 4) => 'too_many_sentences',
            preg_match('/^\s*(?:[-*•]|\d+\s*[.):])\s/mu', $text) === 1 => 'list',
            $this->quotesAnExample($text, $names) => 'quoted_example',
            $this->mentions($plain, (array) ($rules['instruction_terms'] ?? [])) => 'instruction',
            ! $this->onlyAllowedClauses($sentences, $rules) => 'not_commercial',
            ! $this->mentions($plain, (array) ($rules['commercial_terms'] ?? [])) => 'no_invitation',
            default => null,
        };
    }

    /**
     * Why a commercial turn's own wording (the funnel's strategy, when no
     * verbatim resource answers) cannot go out, or null. Besides
     * funnelViolation(): no content of the service (a list, a quoted
     * example, or an instruction that is not about the plans or the service:
     * "prueba el plan semanal" is selling, "prueba mirarla a los ojos" is
     * the service) and no guarantee or refund stated as a fact, which only
     * the approved material may give. Like the rest of this class, it
     * reduces what a model could give away or make up; it cannot prove there
     * is none.
     *
     * @param  list<string>  $aliases  other whole names of the product
     */
    public function commercialWordingViolation(string $text, string $productName = '', array $aliases = []): ?string
    {
        if ($reason = $this->funnelViolation($text, $productName)) {
            return $reason;
        }

        $rules = (array) config('botinker.sales_funnels.guided.after_limit_rules', []);
        $names = $this->namePatterns([$productName, ...$aliases]);
        $plain = preg_replace($names, self::PRODUCT_REFERENCE, Str::ascii(mb_strtolower($text)));
        $service = [...(array) ($rules['commercial_terms'] ?? []), ...(array) ($rules['service_terms'] ?? []), self::PRODUCT_REFERENCE];
        $sentences = array_values(array_filter(array_map('trim', preg_split('/[.!?;:]+|\n+/u', $plain)), fn (string $sentence) => $sentence !== ''));

        // Denied right before it ("no es una garantía", "no te garantizo", "sin reembolso") is no claim;
        // "si no te funciona te devolvemos" is: the "no" there denies something else.
        $denial = '\b(?:no|sin|ningun|ninguna|nunca)\s+(?:(?:es|son|hay|tiene|tienes|incluye|ofrece|ofrecemos|damos|existe|te|le|lo|la|el|un|una|de)\s+){0,2}';

        foreach ((array) ($rules['claim_terms'] ?? []) as $term) {
            $term = preg_quote((string) $term, '/');

            if (preg_match_all("/\\b{$term}\\b/", $plain) > preg_match_all("/{$denial}{$term}\\b/", $plain)) {
                return 'unbacked_claim';
            }
        }

        foreach ($sentences as $sentence) {
            foreach (preg_split('/,|\s+(?:y|pero)\s+/u', $sentence) as $clause) {
                if ($this->mentions($clause, (array) ($rules['instruction_terms'] ?? [])) && ! $this->mentions($clause, $service)) {
                    return 'instruction';
                }
            }
        }

        return match (true) {
            preg_match('/^\s*(?:[-*•]|\d+\s*[.):])\s/mu', $text) === 1 => 'list',
            $this->quotesAnExample($text, $names) => 'quoted_example',
            default => null,
        };
    }

    /**
     * Why a guided funnel's reply to a greeting, a thanks or another courtesy
     * cannot go out, or null. Nothing commercialWordingViolation() forbids, and
     * as short as a reply after the free replies (max_characters,
     * max_sentences), but with no invitation to the plans: a courtesy is
     * answered as one.
     *
     * @param  list<string>  $aliases  other whole names of the product
     */
    public function courtesyReplyViolation(string $text, string $productName = '', array $aliases = []): ?string
    {
        if ($reason = $this->commercialWordingViolation($text, $productName, $aliases)) {
            return $reason;
        }

        $rules = (array) config('botinker.sales_funnels.guided.after_limit_rules', []);
        $sentences = array_filter(array_map('trim', preg_split('/[.!?;:]+|\n+/u', $text)), fn (string $sentence) => $sentence !== '');

        return match (true) {
            mb_strlen($text) > (int) ($rules['max_characters'] ?? 400) => 'too_long',
            count($sentences) > (int) ($rules['max_sentences'] ?? 4) => 'too_many_sentences',
            default => null,
        };
    }

    /**
     * Why a commercial turn's fallback reply (config
     * sales_funnels.guided.fallback_reply) cannot go out, or null. Nothing
     * commercialWordingViolation() forbids (content, guarantees, amounts),
     * within its own length, and nothing commercial at all: no plans,
     * subscription, purchase or payment, so it never offers anything.
     *
     * @param  list<string>  $aliases  other whole names of the product
     */
    public function fallbackReplyViolation(string $text, string $productName = '', array $aliases = []): ?string
    {
        if ($reason = $this->commercialWordingViolation($text, $productName, $aliases)) {
            return $reason;
        }

        $limits = (array) config('botinker.sales_funnels.guided.fallback_reply', []);
        $plain = Str::ascii(mb_strtolower($text));
        $sentences = array_filter(array_map('trim', preg_split('/[.!?;:]+|\n+/u', $text)), fn (string $sentence) => $sentence !== '');
        // Amounts and prices with figures are funnelViolation()'s; words such as «cuesta» or «valor» also mean «it is
        // hard» or «worth»: only the unambiguous words of plans, buying and paying count here.
        $commercial = [...(array) config('botinker.sales_funnels.guided.after_limit_rules.commercial_terms', []), ...self::PURCHASE_TERMS];

        return match (true) {
            mb_strlen($text) > (int) ($limits['max_characters'] ?? 250) => 'too_long',
            count($sentences) > (int) ($limits['max_sentences'] ?? 2) => 'too_many_sentences',
            $this->mentions($plain, $commercial) => 'commercial',
            default => null,
        };
    }

    /**
     * The whole names, longest first so an alias containing the name wins,
     * as patterns over lowercase text without accents.
     *
     * @param  array<int, mixed>  $names
     * @return list<string>
     */
    private function namePatterns(array $names): array
    {
        $words = array_map(fn ($name) => preg_split('/[^a-z0-9]+/', Str::ascii(mb_strtolower((string) $name)), -1, PREG_SPLIT_NO_EMPTY), $names);
        $words = array_filter($words, fn (array $parts) => strlen(implode('', $parts)) >= self::MIN_NAME_LENGTH);

        usort($words, fn (array $a, array $b) => strlen(implode('', $b)) <=> strlen(implode('', $a)));

        return array_map(fn (array $parts) => '/\b'.implode('\W+', array_map(fn ($part) => preg_quote($part, '/'), $parts)).'\b/', $words);
    }

    /**
     * A quoted phrase is an example, unless it is the product's own name.
     *
     * @param  list<string>  $names
     */
    private function quotesAnExample(string $text, array $names): bool
    {
        preg_match_all('/[«“"]([^»”"]*\s[^»”"]*)[»”"]/u', $text, $quotes);

        foreach ($quotes[1] as $quote) {
            if (trim((string) preg_replace($names, '', Str::ascii(mb_strtolower($quote)))) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $sentences
     * @param  array<string, mixed>  $rules
     */
    private function onlyAllowedClauses(array $sentences, array $rules): bool
    {
        $service = [...(array) ($rules['commercial_terms'] ?? []), ...(array) ($rules['service_terms'] ?? [])];

        foreach ($sentences as $index => $sentence) {
            foreach (preg_split('/,|\s+(?:y|pero)\s+/u', $sentence) as $clause) {
                $clause = trim($clause, " \t-()'\"");

                $allowed = $clause === ''
                    || in_array($clause, (array) ($rules['courtesies'] ?? []), true)
                    || ($this->mentions($clause, $service) && mb_strlen($clause) <= (int) ($rules['max_service_characters'] ?? 100))
                    || ($index === 0 && $this->startsWithAny($clause, (array) ($rules['acknowledgments'] ?? [])) && mb_strlen($clause) <= (int) ($rules['max_acknowledgment_characters'] ?? 50));

                if (! $allowed) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @param  list<string>  $terms */
    private function mentions(string $text, array $terms): bool
    {
        return $terms !== [] && preg_match('/\b(?:'.implode('|', array_map(fn ($term) => preg_quote((string) $term, '/'), $terms)).')\b/', $text) === 1;
    }

    /** @param  list<string>  $terms */
    private function startsWithAny(string $text, array $terms): bool
    {
        return array_filter($terms, fn ($term) => preg_match('/^'.preg_quote((string) $term, '/').'\b/', $text) === 1) !== [];
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
