<?php

namespace App\Platform\Commerce;

use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Store;
use App\Models\Subscription;
use Carbon\Carbon;
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

    /** The id of a plan's reply button, before the plan's id. */
    public const PLAN_CHOICE = 'plan:';

    /** What WhatsApp accepts in a reply-buttons message. */
    private const MAX_PLAN_BUTTONS = 3;

    private const MAX_BUTTON_TITLE = 20;

    private const MAX_BUTTON_BODY = 1024;

    private const INTENT_STATUS = 'status';

    private const INTENT_PRICES = 'prices';

    private const INTENT_PAY = 'pay';

    /** Wants to buy and also has a question: asked first, no payment starts. */
    private const INTENT_CLARIFY = 'clarify';

    /**
     * The payment terms that are actions. The others ("suscripción",
     * "membresía") only name the thing: "¿la suscripción se renueva sola?"
     * talks about it without asking to buy it. "Pago" can be either: see
     * paysAsAction().
     */
    private const PURCHASE_VERBS = ['pagar', 'suscribirme', 'suscribir', 'suscribo', 'renovar', 'renuevo', 'comprar', 'compro', 'contratar', 'adquirir'];

    /** "Pago" after one of these is the noun ("el pago de…"), not the action ("¿cómo pago…?"). */
    private const PAYMENT_NOUN = '/\b(?:el|del|al|un|mi|tu|su|este|ese|de|sin|ningun|otro)\s+pago\b/';

    /** The noun used as an action: "quiero hacer el pago". */
    private const PAYMENT_ACTION = '/\b(?:hacer|hago|realizar|realizo|efectuar|efectuo)\s+(?:(?:el|un|mi)\s+)?pago\b/';

    /** Asking how the service or the payment is, not asking to buy it. */
    private const CONSULTATION = '/\b(?:incluyen?|incluido|funcionan?|consiste|sirven?|beneficios?|ventajas?|diferencias?|obtengo|obtener|recibo|recibir|recomiendas?|renueva|seguros?|segura|seguridad|confiables?)\b|\b(?:es|son)\s+(?:el|los|mi|mis)\s+pagos?\b/';

    /** The customer says there is something to resolve first. */
    private const DOUBT = '/\b(?:dudas?|preguntas?|antes de)\b/';

    /** Words of a doubt, which say nothing about what is being bought. */
    private const DOUBT_WORDS = ['pero', 'tengo', 'duda', 'dudas', 'pregunta', 'preguntas', 'antes', 'incluye', 'incluyen', 'funciona', 'funcionan', 'seguro', 'segura'];

    private const NEGATION = '/\b(?:no|nunca)\s+(?:quiero|quisiera|deseo|voy a|pienso|necesito|puedo|me interesa)\b|\b(?:todavia|aun)\s+no\b/';

    /** The customer states the wish: "quiero…", as opposed to asking about it. */
    private const WISH = '/\b(?:quiero|quisiera|deseo|voy a|necesito|me gustaria|me interesa|prefiero|elijo|tomo)\b/';

    /** A message that opens as a question about something ("¿qué pasa si…?"), which "¿cómo pago?" does not. */
    private const QUESTION = '/^\W*(?:que|cuales?|para que|por que|si)\b/';

    private const CHOOSING_WORDS = ['plan', 'planes', 'interesa', 'prefiero', 'elijo', 'tomo'];

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

            // The plan may also be chosen by its name, when only one fits.
            if ($product && preg_match(self::NEGATION, $text) !== 1 && ($named = $this->namedPlans($text, $this->plans($product)))->count() === 1) {
                return $this->startPayment($store, $conversation, $customer, $product, $named->first());
            }

            $conversation->update(['pending_state' => null]);
        }

        // Only a number, right after the plan list was shown: its plan in that list.
        if ($target !== null && $target->requiresSubscription() && preg_match('/^\s*(\d{1,2})\s*\.?\s*$/', $text, $match)
            && $this->offerRecentlyShown($target, $conversation) && ! $this->hasValidSubscription($customer, $target)
            && ($plan = $this->plans($target)->get((int) $match[1] - 1)) !== null) {
            Log::info('COMMERCE_REQUEST', ['store_id' => $store->id, 'customer_id' => $customer->id, 'product_id' => $target->id, 'intent' => 'plan_number']);

            return $this->payFor($store, $conversation, $customer, $target, $plan);
        }

        if ($target === null || ! $target->requiresSubscription() || ($intent = $this->intent($text, $target)) === null) {
            return null;
        }

        $conversation->update(['current_product_id' => $target->id]);

        Log::info('COMMERCE_REQUEST', ['store_id' => $store->id, 'customer_id' => $customer->id, 'product_id' => $target->id, 'intent' => $intent]);

        return match ($intent) {
            self::INTENT_STATUS => $this->reply($target, $this->statusText($customer, $target)),
            self::INTENT_PRICES => $this->pricesReply($conversation, $customer, $target),
            self::INTENT_PAY => $this->pay($store, $conversation, $customer, $target, $text),
            self::INTENT_CLARIFY => $this->reply($target, 'Claro. Cuéntame tu duda y te ayudo. Cuando quieras pagar, escribe «pagar».'),
        };
    }

    /**
     * The plan a customer picked with a reply button of a plan list
     * (planButtons()), whose id is "plan:{id}". It counts only for the
     * conversation's own product, among its active plans, for a customer
     * without a valid subscription: anything else is not this choice, and
     * null lets the button's title go on as text. A button that no longer
     * fits (another product's, a deactivated plan) starts no payment: the
     * customer is asked again, with the current plans.
     */
    public function choosePlan(Store $store, Conversation $conversation, Customer $customer, string $choice): ?CommerceReply
    {
        $product = $conversation->current_product_id ? Product::where('store_id', $store->id)->find($conversation->current_product_id) : null;

        if ($product === null || ! $product->requiresSubscription() || $this->hasValidSubscription($customer, $product)) {
            return null;
        }

        $id = preg_match('/^'.preg_quote(self::PLAN_CHOICE, '/').'(\d{1,18})$/', $choice, $match) === 1 ? (int) $match[1] : null;
        $plan = $id !== null ? $this->plans($product)->firstWhere('id', $id) : null;

        Log::info('COMMERCE_REQUEST', ['store_id' => $store->id, 'customer_id' => $customer->id, 'product_id' => $product->id, 'intent' => 'plan_button', 'valid' => $plan !== null]);

        return $plan !== null
            ? $this->payFor($store, $conversation, $customer, $product, $plan)
            : $this->pay($store, $conversation, $customer, $product, '', confirmPlan: true);
    }

    /**
     * A reply button per plan when $text carries the product's plan list and
     * the customer has no valid subscription, within what WhatsApp accepts
     * (MAX_PLAN_BUTTONS, unique titles of MAX_BUTTON_TITLE characters, a body
     * of MAX_BUTTON_BODY); null otherwise, and the text goes alone.
     *
     * @return list<array{id: string, title: string}>|null
     */
    public function planButtons(?Product $product, ?Customer $customer, string $text): ?array
    {
        if ($product === null || $customer === null || ! $product->requiresSubscription() || mb_strlen($text) > self::MAX_BUTTON_BODY) {
            return null;
        }

        $plans = $this->plans($product);
        $titles = $plans->map(fn (Plan $plan) => trim((string) $plan->name));

        if ($plans->isEmpty() || $plans->count() > self::MAX_PLAN_BUTTONS || ! str_contains($text, $this->planLines($plans))
            || $titles->contains(fn (string $title) => $title === '' || mb_strlen($title) > self::MAX_BUTTON_TITLE)
            || $titles->unique(fn (string $title) => mb_strtolower($title))->count() !== $titles->count()
            || $this->hasValidSubscription($customer, $product)) {
            return null;
        }

        return $plans->values()->map(fn (Plan $plan, int $i) => ['id' => self::PLAN_CHOICE.$plan->id, 'title' => $titles[$i]])->all();
    }

    /** The plan's payment, or the one already open for the product: never a second one. */
    private function payFor(Store $store, Conversation $conversation, Customer $customer, Product $product, Plan $plan): CommerceReply
    {
        $open = $this->openPayment($customer, $product);

        return $open !== null
            ? $this->reply($product, $this->openPaymentText($store, $product, $open))
            : $this->startPayment($store, $conversation, $customer, $product, $plan);
    }

    private function hasValidSubscription(Customer $customer, Product $product): bool
    {
        return (bool) Subscription::where('customer_id', $customer->id)->where('product_id', $product->id)->first()?->isValidAt();
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

    /**
     * What a customer without access gets instead of the protected service.
     * With the conversation, the plan list follows the offer frequency
     * (see unaskedOffer()); without it, it is always shown.
     */
    public function blockedReply(Store $store, Product $product, AccessDecision $access, ?Conversation $conversation = null): string
    {
        $payment = $access->openPayment;

        if ($payment !== null) {
            return $this->openPaymentText($store, $product, $payment);
        }

        return $this->accessStateLine($product, $access, withoutSubscription: true).$this->unaskedOffer($product, $conversation);
    }

    /**
     * The offer that follows a commercial reply (CommercialAdvisor) to a
     * customer without access: the plans, preceded only by a state the
     * customer should know (an expired or revoked subscription). "You need
     * a subscription" would just repeat what the reply already explained.
     * With an open payment it is exactly blockedReply().
     */
    public function offerAfterCommercialReply(Store $store, Product $product, AccessDecision $access, ?Conversation $conversation = null): string
    {
        if ($access->openPayment !== null) {
            return $this->blockedReply($store, $product, $access);
        }

        return $this->accessStateLine($product, $access, withoutSubscription: false).$this->unaskedOffer($product, $conversation);
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

    /**
     * The offer that follows the last free reply: the product's closing
     * when configured, then the plans, which always come from the catalog.
     * Once it went out, the caller records it (recordOfferShown()).
     */
    public function afterSampleText(Product $product): string
    {
        $closing = trim((string) $product->sample_closing_message);

        return ($closing !== '' ? $closing."\n\n" : '').$this->offerText($product);
    }

    /**
     * The offer a funnel shows: the product's closing, the plans from the
     * catalog and the funnel's call to action. Whoever sends it records it,
     * once it went out (recordOfferShown()).
     */
    public function funnelOffer(Product $product, ?string $cta): string
    {
        $closing = trim((string) $product->sample_closing_message);

        return ($closing !== '' ? $closing."\n\n" : '').$this->offerText($product, $cta);
    }

    /** The active plans, for a text that presents them; empty without plans. */
    public function plansBlock(Product $product): string
    {
        return $this->planLines($this->plans($product));
    }

    /** The active plans without their amounts, which only the Core states: for the model of a guided funnel. */
    public function planSummary(Product $product): string
    {
        return $this->plans($product)
            ->map(fn (Plan $plan) => "- {$plan->name}: {$plan->duration_days} días")
            ->implode("\n");
    }

    /**
     * The message only chooses a plan by its stored name ("el semanal"),
     * with nothing else asked: no negation, question or doubt about it.
     */
    public function choosesPlan(string $message, Product $product): bool
    {
        $text = $this->normalize($message);
        $named = $this->namedPlans($text, $this->plans($product));

        if ($named->isEmpty() || preg_match(self::NEGATION, $text) === 1 || preg_match(self::CONSULTATION, $text) === 1 || preg_match(self::DOUBT, $text) === 1) {
            return false;
        }

        $words = array_diff(preg_split('/[^a-z0-9]+/', $text, -1, PREG_SPLIT_NO_EMPTY), self::FILLER);

        return array_diff($words, self::CHOOSING_WORDS, $this->planWords($named)) === [];
    }

    /**
     * A purchase a funnel decided on, through the same validations as any
     * other: open payments, available plans, payment instructions, the plan
     * named. With $confirmPlan, a plan the customer did not name is never
     * assumed, not even the only one: they choose it from the list.
     */
    public function startPurchase(Store $store, Conversation $conversation, Customer $customer, Product $product, string $message, bool $confirmPlan): CommerceReply
    {
        $conversation->update(['current_product_id' => $product->id]);

        Log::info('COMMERCE_REQUEST', ['store_id' => $store->id, 'customer_id' => $customer->id, 'product_id' => $product->id, 'intent' => 'funnel_purchase']);

        return $this->pay($store, $conversation, $customer, $product, $this->normalize($message), $confirmPlan);
    }

    /** The plan list of the product was just shown in this conversation. */
    public function recordOfferShown(Conversation $conversation, Product $product): void
    {
        $shown = $conversation->offers_shown ?? [];
        $shown[(string) $product->id] = now()->toIso8601String();

        $conversation->update(['offers_shown' => $shown]);
    }

    /** What WhatsApp took may be a reminder or a payment instead: only the plan list itself counts. */
    public function recordOfferIfSent(Conversation $conversation, Product $product, string $sentText): void
    {
        if ($this->plans($product)->isNotEmpty() && str_contains($sentText, $this->offerText($product))) {
            $this->recordOfferShown($conversation, $product);
        }
    }

    /**
     * The plan list when the customer did not ask for it: shown at most
     * once every botinker.free_interactions.offer_interval_hours per
     * conversation and product, otherwise a single line saying how to see
     * it. Asking for prices, plans or to pay always shows it. Whoever sends
     * it records it, once it went out (recordOfferIfSent()).
     */
    private function unaskedOffer(Product $product, ?Conversation $conversation): string
    {
        if ($conversation === null || $this->plans($product)->isEmpty()) {
            return $this->offerText($product);
        }

        if ($this->offerRecentlyShown($product, $conversation)) {
            return $this->offerPointer();
        }

        return $this->offerText($product);
    }

    /** How to see the plans, in one line: what goes instead of the plan list itself. */
    public function offerPointer(): string
    {
        return 'Escribe «planes» para ver los precios o «pagar» para suscribirte.';
    }

    /** Whether this conversation saw the product's offer within the configured interval. */
    public function offerRecentlyShown(Product $product, Conversation $conversation): bool
    {
        $last = $conversation->offers_shown[(string) $product->id] ?? null;
        $hours = max(0, (int) config('botinker.free_interactions.offer_interval_hours', 24));

        return $last !== null && $hours > 0 && Carbon::parse($last)->gt(now()->subHours($hours));
    }

    /** Asked for: the plan list is always shown, and counts as shown. */
    private function pricesReply(Conversation $conversation, Customer $customer, Product $product): CommerceReply
    {
        if ($this->plans($product)->isNotEmpty()) {
            $this->recordOfferShown($conversation, $product);
        }

        return $this->reply($product, $this->subscriptionLine($customer, $product).$this->offerText($product));
    }

    /**
     * Starts the payment of the plan the message names, or of the only one
     * when it names none. A plan named that cannot be told for certain
     * (several fit, or none) is never guessed: the customer chooses.
     */
    private function pay(Store $store, Conversation $conversation, Customer $customer, Product $product, string $text, bool $confirmPlan = false): CommerceReply
    {
        $open = $this->openPayment($customer, $product);

        if ($open !== null) {
            return $this->reply($product, $this->openPaymentText($store, $product, $open));
        }

        $plans = $this->plans($product);

        if ($plans->isEmpty() || blank($store->payment_instructions)) {
            return $this->reply($product, "Por ahora no es posible pagar «{$product->name}» por este medio. Escríbenos más tarde.");
        }

        $named = $this->namedPlans($text, $plans);

        if ($named->count() === 1) {
            return $this->startPayment($store, $conversation, $customer, $product, $named->first());
        }

        if ($plans->count() === 1 && $named->isEmpty() && ! $confirmPlan && ! $this->mentionsPlan($text)) {
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

    private function offerText(Product $product, ?string $cta = null): string
    {
        $plans = $this->plans($product);

        if ($plans->isEmpty()) {
            return "Por ahora «{$product->name}» no tiene planes disponibles. Escríbenos más tarde.";
        }

        return "Planes de «{$product->name}»:\n".$this->planLines($plans)."\n\n".($cta ?? 'Escribe «pagar» para suscribirte.');
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
     *
     * Referring to the product is not asking to buy it, though: a question
     * about it ("¿qué incluye la suscripción?") is no request and follows
     * the conversation, a wish to buy with a doubt asks first, and only an
     * explicit wish to buy reaches the payment.
     */
    private function intent(string $text, Product $product): ?string
    {
        $words = array_values(array_diff(preg_split('/[^a-z0-9]+/', $text, -1, PREG_SPLIT_NO_EMPTY), self::FILLER));
        $terms = array_merge(...array_values(self::TERMS));
        $objects = [...self::OBJECTS, ...$this->productWords($product)];
        $named = $this->namedPlans($text, $this->plans($product));
        $negated = preg_match(self::NEGATION, $text) === 1;
        $wish = preg_match(self::WISH, $text) === 1;
        $consults = preg_match(self::CONSULTATION, $text) === 1;
        $doubts = preg_match(self::DOUBT, $text) === 1;

        // "Quiero el semanal": choosing a plan by its name needs no other word.
        if ($wish && ! $negated && ! $consults && ! $doubts && $named->isNotEmpty()
            && array_diff($words, self::CHOOSING_WORDS, $this->planWords($named)) === []) {
            return self::INTENT_PAY;
        }

        if (array_intersect($words, $terms) === []) {
            return null;
        }

        $allCommercial = array_diff($words, $terms, $objects) === [];
        $namesObject = array_intersect($words, $objects) !== [] || $named->isNotEmpty() || $this->mentionsPlan($text);
        $verb = array_intersect($words, self::PURCHASE_VERBS) !== [] || (in_array('pago', $words, true) && $this->paysAsAction($text));

        if ($verb && $wish && ! $negated && ($consults || $doubts)
            && ($namesObject || array_diff($words, $terms, $objects, self::DOUBT_WORDS) === [])) {
            return self::INTENT_CLARIFY;
        }

        if (! $allCommercial && ! $namesObject) {
            return null;
        }

        if (array_intersect($words, self::TERMS[self::INTENT_STATUS]) !== []) {
            return self::INTENT_STATUS;
        }

        if (array_intersect($words, array_diff(self::TERMS[self::INTENT_PRICES], ['plan', 'planes'])) !== []) {
            return self::INTENT_PRICES;
        }

        if ($consults || $doubts) {
            return null;
        }

        if (! $verb && array_intersect($words, ['plan', 'planes']) !== []) {
            return self::INTENT_PRICES;
        }

        if ($negated) {
            return null;
        }

        if (! $verb) {
            return $allCommercial ? self::INTENT_PAY : null;
        }

        $forPaying = preg_match('/\bpara\s+(?:'.implode('|', self::PURCHASE_VERBS).')\b/', $text) === 1;

        return preg_match(self::QUESTION, $text) === 1 && ! $wish && ! $forPaying ? null : self::INTENT_PAY;
    }

    /** Whether "pago" in the message is the action ("¿cómo pago…?", "hacer el pago"), not the noun ("el pago de…"). */
    private function paysAsAction(string $text): bool
    {
        return preg_match(self::PAYMENT_ACTION, $text) === 1 || preg_match(self::PAYMENT_NOUN, $text) !== 1;
    }

    /**
     * The plans the message names, by their stored name or by their duration
     * with its quantity ("7 días", "1 semana", "un mes"). When one name is
     * inside another ("Mensual" and "Mensual premium"), the longer one wins;
     * plans sharing a duration all match it, so a choice among them is asked.
     *
     * @param  Collection<int, Plan>  $plans
     * @return Collection<int, Plan>
     */
    private function namedPlans(string $text, Collection $plans): Collection
    {
        $text = preg_replace('/[^a-z0-9]+/', ' ', $text);
        $byName = $plans->filter(fn (Plan $plan) => $this->planName($plan) !== '' && preg_match('/\b'.preg_quote($this->planName($plan), '/').'\b/', $text) === 1);
        $byName = $byName->reject(fn (Plan $plan) => $byName->contains(
            fn (Plan $other) => $other->id !== $plan->id && $this->planName($other) !== $this->planName($plan) && str_contains($this->planName($other), $this->planName($plan))
        ));
        $byDuration = $plans->filter(fn (Plan $plan) => array_filter($this->durationPatterns($plan), fn (string $pattern) => preg_match('/\b'.$pattern.'\b/', $text) === 1) !== []);

        return $byName->merge($byDuration)->unique('id')->values();
    }

    /**
     * How a message states a plan's duration, from its stored days: always
     * with the quantity, so "esta semana" or "el mes que viene" name none.
     *
     * @return list<string> patterns over lowercase text without accents
     */
    private function durationPatterns(Plan $plan): array
    {
        $days = (int) $plan->duration_days;

        if ($days < 1) {
            return [];
        }

        $patterns = [$days === 1 ? '(?:1|un)\s+dia' : "{$days}\s+dias"];

        if ($days % 7 === 0) {
            $patterns[] = $days === 7 ? '(?:1|una)\s+semana' : ($days / 7).'\s+semanas';
        }

        if ($days >= 28 && $days <= 31) {
            $patterns[] = '(?:1|un)\s+mes';
        } elseif ($days % 30 === 0) {
            $patterns[] = ($days / 30).'\s+meses';
        }

        return $patterns;
    }

    /**
     * The words of a plan's duration, so a message made only of them chooses it.
     *
     * @return list<string>
     */
    private function durationWords(Plan $plan): array
    {
        $days = (int) $plan->duration_days;

        return $days < 1 ? [] : array_map('strval', [
            $days, 'dia', 'dias', 'un',
            ...($days % 7 === 0 ? [$days / 7, 'una', 'semana', 'semanas'] : []),
            ...($days >= 28 && $days <= 31 ? [1, 'mes'] : ($days % 30 === 0 ? [$days / 30, 'meses'] : [])),
        ]);
    }

    /** "plan semanal" refers to a plan; "plan de comidas" or "mi plan" does not say which one. */
    private function mentionsPlan(string $text): bool
    {
        preg_match_all('/\bplan(?:es)?\s+([a-z0-9]+)/', $text, $matches);

        return array_diff($matches[1], self::FILLER) !== [];
    }

    /** The plan's name to look for in a message: without the word "plan" when it says more. */
    private function planName(Plan $plan): string
    {
        $name = trim(preg_replace('/[^a-z0-9]+/', ' ', $this->normalize((string) $plan->name)));

        return preg_replace('/^plan(?:es)?\s+(?=\S)/', '', $name);
    }

    /**
     * @param  Collection<int, Plan>  $plans
     * @return list<string>
     */
    private function planWords(Collection $plans): array
    {
        return $plans->flatMap(fn (Plan $plan) => [...explode(' ', $this->planName($plan)), ...$this->durationWords($plan)])->values()->all();
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
