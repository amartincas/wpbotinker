<?php

namespace App\Platform\Memory;

use App\Models\Conversation;
use App\Models\Customer;
use App\Models\CustomerFact;
use App\Models\Product;
use App\Platform\Contracts\DomainModule;
use App\Platform\Domains\DomainRegistry;
use Illuminate\Support\Facades\Log;

/**
 * Asks for the facts a product requires, one at a time and without the
 * LLM, and interprets the customer's answers. Owns two pending states of
 * the conversation:
 *
 * - fact_request: waiting for the value of a fact;
 * - fact_share_consent: waiting for permission to reuse a person fact the
 *   customer already gave to another domain.
 *
 * Validation belongs to whoever defines the fact: the Core catalog for
 * person facts, the domain module for its own facts.
 */
class FactCollector
{
    public const PENDING_FACT = 'fact_request';

    public const PENDING_CONSENT = 'fact_share_consent';

    private const YES = ['si', 'sí', 'claro', 'acepto', 'autorizo', 'ok', 'de acuerdo', 'dale', 'listo'];

    private const NO = ['no', 'no acepto', 'no autorizo', 'prefiero que no'];

    /** Characters kept of the customer's original query. */
    private const MAX_ORIGIN_LENGTH = 1000;

    public function __construct(
        private readonly CustomerMemory $memory,
        private readonly PersonFacts $personFacts,
        private readonly DomainRegistry $domains,
    ) {}

    public function answerPending(Conversation $conversation, Customer $customer, string $message, ?string $sourceWamid = null): PendingFactAnswer
    {
        $pending = $conversation->pending_state;
        $type = $pending['type'] ?? null;

        if (! in_array($type, [self::PENDING_FACT, self::PENDING_CONSENT], true)) {
            return PendingFactAnswer::none();
        }

        $domainKey = (string) ($pending['domain_key'] ?? '');
        $productId = (int) ($pending['product_id'] ?? 0);
        $fact = $this->domains->has($domainKey, $conversation->store_id)
            ? collect($this->domains->get($domainKey, $conversation->store_id)->facts())->firstWhere('key', $pending['fact_key'] ?? null)
            : null;

        if ($fact === null || $productId === 0) {
            $conversation->update(['pending_state' => null]);

            return PendingFactAnswer::none();
        }

        $domain = $this->domains->get($domainKey, $conversation->store_id);
        $origin = is_string($pending['origin_message'] ?? null) ? $pending['origin_message'] : null;

        return $type === self::PENDING_FACT
            ? $this->answerFactRequest($conversation, $customer, $domain, $fact, $productId, $message, $sourceWamid, $origin)
            : $this->answerConsent($conversation, $customer, $domain, $fact, $productId, $message, $origin);
    }

    /**
     * The question for the first required fact the domain cannot read yet
     * (or the permission to reuse it), or null when nothing is missing.
     *
     * @param  string|null  $originMessage  the customer's query that led to the question: kept
     *                                      while it is pending, so the answer can still serve it
     */
    public function require(Conversation $conversation, Customer $customer, Product $product, DomainModule $domain, ?string $rejectionReason = null, ?string $originMessage = null): ?string
    {
        $required = $product->required_facts ?? [];
        $this->domains->assertProductConfiguration($domain->key(), $required, storeId: $product->store_id);

        $definitions = collect($domain->facts())->keyBy('key');
        $visible = $this->memory->visibleFacts($customer, $domain);
        $prefix = $rejectionReason ? "{$rejectionReason}\n\n" : '';

        foreach ($required as $key) {
            if (array_key_exists($key, $visible)) {
                continue;
            }

            /** @var FactDefinition $fact */
            $fact = $definitions[$key];

            if ($fact->isPerson() && $this->memory->hasCurrent($customer, $fact, $domain->key())) {
                // Still waiting for "autorizo"/"sí", so the customer can authorize at any time.
                $this->setPending($conversation, self::PENDING_CONSENT, $domain, $product, $key, $originMessage);

                // Already refused: a short reminder instead of asking again.
                if ($this->memory->hasDeclined($customer, $key, $domain->key())) {
                    return "Sin tu {$this->personFacts->label($key)} no puedo continuar con «{$product->name}». "
                        .'Si cambias de opinión, escribe «autorizo».';
                }

                return $prefix."Para continuar con «{$product->name}» necesito tu {$this->personFacts->label($key)}. "
                    .'Ya nos diste ese dato en otro servicio. ¿Me autorizas a usarlo aquí? Responde sí o no.';
            }

            $this->setPending($conversation, self::PENDING_FACT, $domain, $product, $key, $originMessage);

            return $prefix.($fact->isPerson() ? $this->personFacts->question($key) : $fact->question);
        }

        return null;
    }

    private function answerFactRequest(Conversation $conversation, Customer $customer, DomainModule $domain, FactDefinition $fact, int $productId, string $message, ?string $sourceWamid, ?string $origin): PendingFactAnswer
    {
        $validation = $fact->isPerson()
            ? $this->personFacts->validate($fact->key, $message)
            : $domain->validateFact($fact->key, $message);

        if (! $validation->valid) {
            Log::info('FACT_ANSWER_REJECTED', ['domain_key' => $domain->key(), 'fact_key' => $fact->key, 'customer_id' => $customer->id]);

            return PendingFactAnswer::rejected($productId, $validation->reason, $origin);
        }

        $this->memory->record($customer, $domain->key(), $fact, $validation->value, CustomerFact::SOURCE_CUSTOMER_ANSWER, $sourceWamid);
        $conversation->update(['pending_state' => null]);

        Log::info('FACT_RECORDED', ['domain_key' => $domain->key(), 'fact_key' => $fact->key, 'customer_id' => $customer->id]);

        return PendingFactAnswer::answered($productId, $origin);
    }

    private function answerConsent(Conversation $conversation, Customer $customer, DomainModule $domain, FactDefinition $fact, int $productId, string $message, ?string $origin): PendingFactAnswer
    {
        $answer = mb_strtolower(preg_replace('/^[\s¡!.,]+|[\s¡!.,]+$/u', '', $message));

        if (in_array($answer, self::YES, true)) {
            $this->memory->grant($customer, $fact->key, $domain->key());
            $conversation->update(['pending_state' => null]);

            Log::info('FACT_SHARE_GRANTED', ['domain_key' => $domain->key(), 'fact_key' => $fact->key, 'customer_id' => $customer->id]);

            return PendingFactAnswer::answered($productId, $origin);
        }

        if (in_array($answer, self::NO, true)) {
            $this->memory->decline($customer, $fact->key, $domain->key());
            $conversation->update(['pending_state' => null]);

            Log::info('FACT_SHARE_DECLINED', ['domain_key' => $domain->key(), 'fact_key' => $fact->key, 'customer_id' => $customer->id]);

            return PendingFactAnswer::reply(
                "Entendido, no usaré ese dato. Sin tu {$this->personFacts->label($fact->key)} no puedo continuar con este servicio; si cambias de opinión, escríbeme.",
                $domain->key(),
            );
        }

        return PendingFactAnswer::rejected($productId, 'Responde sí o no, por favor.', $origin);
    }

    private function setPending(Conversation $conversation, string $type, DomainModule $domain, Product $product, string $factKey, ?string $originMessage = null): void
    {
        $origin = mb_substr(trim((string) $originMessage), 0, self::MAX_ORIGIN_LENGTH);

        $conversation->update(['pending_state' => array_filter([
            'type' => $type,
            'domain_key' => $domain->key(),
            'product_id' => $product->id,
            'fact_key' => $factKey,
            'origin_message' => $origin !== '' ? $origin : null,
        ], fn ($value) => $value !== null)]);
    }
}
