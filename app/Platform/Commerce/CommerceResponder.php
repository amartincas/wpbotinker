<?php

namespace App\Platform\Commerce;

use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Store;
use App\Models\Subscription;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The commercial side of the conversation, for subscription products:
 * prices and plans, starting a payment (plan choice included), payment
 * status, and the reply when the protected service is blocked.
 *
 * Every text here is a fixed Core template filled with persisted data
 * (plans, payments, subscriptions, the store's payment instructions):
 * prices, durations and conditions never come from the LLM. Requests are
 * recognized deterministically (see intent()) and only for subscription
 * products, so open products and other topics are never affected.
 */
class CommerceResponder
{
    public const PENDING_PLAN = 'commerce_plan_selection';

    /** Hours a started payment waits for its receipt. */
    public const PAYMENT_WINDOW_HOURS = 48;

    private const INTENT_STATUS = 'status';

    private const INTENT_PRICES = 'prices';

    private const INTENT_PAY = 'pay';

    /** Commercial terms by intent; the first intent with a term present wins. */
    private const TERMS = [
        self::INTENT_STATUS => ['estado', 'comprobante', 'pague', 'pagado', 'transferi', 'consigne'],
        self::INTENT_PRICES => ['precio', 'precios', 'cuesta', 'cuestan', 'vale', 'valor', 'costo', 'costos', 'tarifa', 'tarifas', 'plan', 'planes', 'mensualidad'],
        self::INTENT_PAY => ['pagar', 'pago', 'pagos', 'suscribirme', 'suscribir', 'suscribo', 'suscripcion', 'renovar', 'renuevo', 'renovacion', 'comprar', 'compro', 'contratar', 'adquirir', 'membresia'],
    ];

    /**
     * Terms that also carry an everyday meaning ("vale la pena", "el valor
     * de…", "un plan de comidas", "tu estado de ánimo"): a text using them
     * is not, by that alone, stating a price or a payment fact.
     */
    private const EVERYDAY_TERMS = ['vale', 'valor', 'plan', 'planes', 'estado'];

    /**
     * What a commercial term may refer to, besides the product's own name.
     * Only unambiguous words: "plan", "servicio" or "mensualidad" also name
     * everyday things ("pagar el servicio de luz").
     */
    private const OBJECTS = ['suscripcion', 'membresia'];

    /** Words that carry no meaning for the classification. "no" is NOT here: "no quiero pagar" is not a request. */
    private const FILLER = [
        'hola', 'buenas', 'buenos', 'dias', 'tardes', 'noches', 'por', 'favor', 'gracias', 'quiero', 'quisiera', 'deseo',
        'me', 'gustaria', 'como', 'puedo', 'cual', 'cuales', 'es', 'son', 'el', 'la', 'los', 'las', 'lo', 'de', 'del', 'que',
        'un', 'una', 'mi', 'mis', 'tu', 'su', 'sus', 'para', 'a', 'al', 'y', 'o', 'en', 'con', 'hay', 'tiene', 'tienen',
        'tienes', 'saber', 'dime', 'dame', 'informacion', 'info', 'sobre', 'cuanto', 'cuanta', 'necesito', 'ya', 'hacer',
        'hago', 'hice', 'realizar', 'realice', 'se', 'te', 'esta', 'este', 'ahora', 'otra', 'vez', 'si', 'ver', 'consultar',
    ];

    /**
     * A reply when the message is a commercial request about a subscription
     * product (or answers a pending plan choice); null otherwise.
     *
     * @param  Collection<int, Product>  $catalog  the store's catalog (ProductResolver)
     */
    public function respond(Store $store, Conversation $conversation, Customer $customer, string $message, Collection $catalog, ?Product $target): ?CommerceReply
    {
        $text = $this->normalize($message);
        $pending = $conversation->pending_state;

        if (($pending['type'] ?? null) === self::PENDING_PLAN) {
            $product = $catalog->firstWhere('id', $pending['product_id'] ?? null);

            if ($product && preg_match('/^\s*(\d{1,2})\s*\.?\s*$/', $text, $match)) {
                $plans = $this->plans($product);
                $plan = $plans->firstWhere('id', $pending['plan_ids'][(int) $match[1] - 1] ?? null);

                return $plan
                    ? $this->startPayment($store, $conversation, $customer, $product, $plan)
                    : $this->reply($product, "Responde con el número del plan que quieres:\n".$this->planLines($plans));
            }

            $conversation->update(['pending_state' => null]);
        }

        if ($target === null || ! $target->requiresSubscription() || ($intent = $this->intent($text, $target)) === null) {
            return null;
        }

        $conversation->update(['current_product_id' => $target->id]);

        Log::info('COMMERCE_REQUEST', ['store_id' => $store->id, 'customer_id' => $customer->id, 'product_id' => $target->id, 'intent' => $intent]);

        return match ($intent) {
            self::INTENT_STATUS => $this->reply($target, $this->statusText($customer, $target)),
            self::INTENT_PRICES => $this->reply($target, $this->subscriptionLine($customer, $target).$this->offerText($target)),
            self::INTENT_PAY => $this->pay($store, $conversation, $customer, $target),
        };
    }

    /**
     * The price and payment-status terms of this classifier that, in a text,
     * state a transactional fact (e.g. "precio", "mensualidad", "pagado"),
     * normalized like the classifier (lowercase, no accents). Reused by
     * CommercialClaims so both rules stay one list.
     *
     * @return list<string>
     */
    public static function priceTerms(): array
    {
        return array_values(array_diff([...self::TERMS[self::INTENT_PRICES], ...self::TERMS[self::INTENT_STATUS]], self::EVERYDAY_TERMS));
    }

    /**
     * Whether the message is only a commercial request that names no product
     * ("precios", "quiero pagar", "estado de mi pago"): the customer wants
     * to buy or pay, but the product they mean is unknown.
     */
    public function isProductlessRequest(string $message): bool
    {
        $words = array_values(array_diff(preg_split('/[^a-z0-9]+/', $this->normalize($message), -1, PREG_SPLIT_NO_EMPTY), self::FILLER));
        $terms = array_merge(...array_values(self::TERMS));

        return array_intersect($words, $terms) !== [] && array_diff($words, $terms, self::OBJECTS) === [];
    }

    /** What a customer without access gets instead of the protected service. */
    public function blockedReply(Store $store, Product $product, AccessDecision $access): string
    {
        $payment = $access->openPayment;

        if ($payment !== null) {
            return $this->openPaymentText($store, $product, $payment);
        }

        return $this->accessStateLine($product, $access, withoutSubscription: true).$this->offerText($product);
    }

    /**
     * The offer that follows a commercial reply (CommercialAdvisor) to a
     * customer without access: the plans, preceded only by a state the
     * customer should know (an expired or revoked subscription). "You need
     * a subscription" would just repeat what the reply already explained.
     * With an open payment it is exactly blockedReply().
     */
    public function offerAfterCommercialReply(Store $store, Product $product, AccessDecision $access): string
    {
        if ($access->openPayment !== null) {
            return $this->blockedReply($store, $product, $access);
        }

        return $this->accessStateLine($product, $access, withoutSubscription: false).$this->offerText($product);
    }

    /** Why the customer has no access, as the line before the offer ('' when not worth saying). */
    private function accessStateLine(Product $product, AccessDecision $access, bool $withoutSubscription): string
    {
        return match ($access->reason) {
            AccessDecision::EXPIRED => "Tu suscripción a «{$product->name}» venció el {$access->subscription->expires_at->format('d/m/Y')}. Para seguir usándolo, renuévala.\n\n",
            AccessDecision::REVOKED => "Tu acceso a «{$product->name}» no está activo.\n\n",
            default => $withoutSubscription ? "Para usar «{$product->name}» necesitas una suscripción.\n\n" : '',
        };
    }

    /** The offer that follows a delivered free sample. */
    public function afterSampleText(Product $product): string
    {
        // The product's own closing when configured; the plans always come from the catalog.
        $closing = trim((string) $product->sample_closing_message) ?: "Esta fue tu muestra gratuita de «{$product->name}».";

        return $closing."\n\n".$this->offerText($product);
    }

    private function pay(Store $store, Conversation $conversation, Customer $customer, Product $product): CommerceReply
    {
        $open = $this->openPayment($customer, $product);

        if ($open !== null) {
            return $this->reply($product, $this->openPaymentText($store, $product, $open));
        }

        $plans = $this->plans($product);

        if ($plans->isEmpty() || blank($store->payment_instructions)) {
            return $this->reply($product, "Por ahora no es posible pagar «{$product->name}» por este medio. Escríbenos más tarde.");
        }

        if ($plans->count() === 1) {
            return $this->startPayment($store, $conversation, $customer, $product, $plans->first());
        }

        $conversation->update(['pending_state' => [
            'type' => self::PENDING_PLAN,
            'product_id' => $product->id,
            'plan_ids' => $plans->pluck('id')->all(),
        ]]);

        return $this->reply($product, "¿Qué plan de «{$product->name}» quieres?\n".$this->planLines($plans)."\n\nResponde con el número.");
    }

    private function startPayment(Store $store, Conversation $conversation, Customer $customer, Product $product, Plan $plan): CommerceReply
    {
        $conversation->update(['pending_state' => null, 'current_product_id' => $product->id]);

        if (blank($store->payment_instructions)) {
            return $this->reply($product, "Por ahora no es posible pagar «{$product->name}» por este medio. Escríbenos más tarde.");
        }

        try {
            $payment = Payment::start($customer, $plan, now()->addHours(self::PAYMENT_WINDOW_HOURS));
        } catch (UniqueConstraintViolationException) {
            // Another request started one first: show that one.
            $payment = $this->openPayment($customer, $product);
        }

        if ($payment === null) {
            return $this->reply($product, "No pude iniciar el pago de «{$product->name}». Intenta de nuevo en unos minutos.");
        }

        Log::info('PAYMENT_STARTED', ['store_id' => $store->id, 'customer_id' => $customer->id, 'product_id' => $product->id, 'payment_id' => $payment->id]);

        return $this->reply($product, $this->openPaymentText($store, $product, $payment));
    }

    private function openPaymentText(Store $store, Product $product, Payment $payment): string
    {
        return match ($payment->status) {
            Payment::STATUS_PENDING => "Pago de «{$product->name}», plan {$payment->plan_name}: {$this->money($payment->amount, $payment->currency)} por {$payment->duration_days} días.\n\n"
                .trim((string) $store->payment_instructions)
                ."\n\nCuando pagues, envía el comprobante por este chat. Tu acceso se activa cuando confirmemos el pago.",
            Payment::STATUS_UNDER_REVIEW => "Tu pago de «{$product->name}» está en revisión. Te avisaremos cuando quede confirmado.",
            Payment::STATUS_NOT_REFLECTED => "Tu pago de «{$product->name}» aún no se ha confirmado. Lo seguimos revisando.",
        };
    }

    private function statusText(Customer $customer, Product $product): string
    {
        $open = $this->openPayment($customer, $product);

        if ($open !== null) {
            return $this->openPaymentText($customer->store, $product, $open);
        }

        $line = trim($this->subscriptionLine($customer, $product));

        return $line !== '' ? $line : "No tienes pagos en curso para «{$product->name}». Escribe «pagar» si quieres suscribirte.";
    }

    private function subscriptionLine(Customer $customer, Product $product): string
    {
        $subscription = Subscription::where('customer_id', $customer->id)->where('product_id', $product->id)->first();

        return match (true) {
            $subscription?->isValidAt() => "Tu suscripción a «{$product->name}» está activa hasta el {$subscription->expires_at->format('d/m/Y')}.\n\n",
            $subscription?->hasExpired() => "Tu suscripción a «{$product->name}» venció el {$subscription->expires_at->format('d/m/Y')}.\n\n",
            default => '',
        };
    }

    private function offerText(Product $product): string
    {
        $plans = $this->plans($product);

        if ($plans->isEmpty()) {
            return "Por ahora «{$product->name}» no tiene planes disponibles. Escríbenos más tarde.";
        }

        return "Planes de «{$product->name}»:\n".$this->planLines($plans)."\n\nEscribe «pagar» para suscribirte.";
    }

    /**
     * @param  Collection<int, Plan>  $plans
     */
    private function planLines(Collection $plans): string
    {
        return $plans->values()
            ->map(fn (Plan $plan, int $i) => ($i + 1).". {$plan->name}: {$this->money($plan->price, $plan->currency)} por {$plan->duration_days} días")
            ->implode("\n");
    }

    /**
     * @return Collection<int, Plan>
     */
    private function plans(Product $product): Collection
    {
        return Plan::where('product_id', $product->id)->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get();
    }

    private function openPayment(Customer $customer, Product $product): ?Payment
    {
        return Payment::where('customer_id', $customer->id)
            ->where('product_id', $product->id)
            ->whereIn('status', Payment::OPEN_STATUSES)
            ->latest('id')
            ->first();
    }

    private function money(string|float $amount, string $currency): string
    {
        $decimals = fmod((float) $amount, 1.0) == 0.0 ? 0 : 2;

        return '$'.number_format((float) $amount, $decimals, ',', '.')." {$currency}";
    }

    /**
     * The commercial intent of an explicit request, or null. A message is a
     * request when it has a commercial term and either (a) all its
     * meaningful words are commercial ("pagar", "¿cuál es el precio?",
     * "estado de mi pago") or (b) it names what the term refers to: the
     * subscription, a plan, or the product itself ("quiero pagar la
     * Lectura"). So "¿debería comprar una casa?" is a service question.
     */
    private function intent(string $text, Product $product): ?string
    {
        $words = array_values(array_diff(preg_split('/[^a-z0-9]+/', $text, -1, PREG_SPLIT_NO_EMPTY), self::FILLER));
        $terms = array_merge(...array_values(self::TERMS));
        $objects = [...self::OBJECTS, ...$this->productWords($product)];

        if (array_intersect($words, $terms) === []) {
            return null;
        }

        $allCommercial = array_diff($words, $terms, $objects) === [];
        $namesObject = array_intersect($words, $objects) !== [];

        if (! $allCommercial && ! $namesObject) {
            return null;
        }

        foreach (self::TERMS as $intent => $intentTerms) {
            if (array_intersect($words, $intentTerms) !== []) {
                return $intent;
            }
        }

        return null;
    }

    /**
     * Words of the product's name and aliases (3+ letters).
     *
     * @return list<string>
     */
    private function productWords(Product $product): array
    {
        return collect([$product->name, ...($product->aliases ?? [])])
            ->flatMap(fn ($name) => preg_split('/[^a-z0-9]+/', $this->normalize((string) $name), -1, PREG_SPLIT_NO_EMPTY))
            ->filter(fn (string $word) => strlen($word) >= 3 && ! in_array($word, self::FILLER, true))
            ->values()
            ->all();
    }

    private function normalize(string $text): string
    {
        return Str::ascii(mb_strtolower(trim($text)));
    }

    private function reply(Product $product, string $text): CommerceReply
    {
        return new CommerceReply($text, $product->domain_key);
    }
}
