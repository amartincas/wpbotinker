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
        $fact = $this->domains->has($domainKey)
            ? collect($this->domains->get($domainKey)->facts())->firstWhere('key', $pending['fact_key'] ?? null)
            : null;

        if ($fact === null || $productId === 0) {
            $conversation->update(['pending_state' => null]);

            return PendingFactAnswer::none();
        }

        $domain = $this->domains->get($domainKey);

        return $type === self::PENDING_FACT
            ? $this->answerFactRequest($conversation, $customer, $domain, $fact, $productId, $message, $sourceWamid)
            : $this->answerConsent($conversation, $customer, $domain, $fact, $productId, $message);
    }

    /**
     * The question for the first required fact the domain cannot read yet
     * (or the permission to reuse it), or null when nothing is missing.
     */
    public function require(Conversation $conversation, Customer $customer, Product $product, DomainModule $domain, ?string $rejectionReason = null): ?string
    {
        $required = $product->required_facts ?? [];
        $this->domains->assertProductConfiguration($domain->key(), $required);

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
                $this->setPending($conversation, self::PENDING_CONSENT, $domain, $product, $key);

                // Already refused: a short reminder instead of asking again.
                if ($this->memory->hasDeclined($customer, $key, $domain->key())) {
                    return "Sin tu {$this->personFacts->label($key)} no puedo continuar con «{$product->name}». "
                        .'Si cambias de opinión, escribe «autorizo».';
                }

                return $prefix."Para continuar con «{$product->name}» necesito tu {$this->personFacts->label($key)}. "
                    .'Ya nos diste ese dato en otro servicio. ¿Me autorizas a usarlo aquí? Responde sí o no.';
            }

            $this->setPending($conversation, self::PENDING_FACT, $domain, $product, $key);

            return $prefix.($fact->isPerson() ? $this->personFacts->question($key) : $fact->question);
        }

        return null;
    }

    private function answerFactRequest(Conversation $conversation, Customer $customer, DomainModule $domain, FactDefinition $fact, int $productId, string $message, ?string $sourceWamid): PendingFactAnswer
    {
        $validation = $fact->isPerson()
            ? $this->personFacts->validate($fact->key, $message)
            : $domain->validateFact($fact->key, $message);

        if (! $validation->valid) {
            Log::info('FACT_ANSWER_REJECTED', ['domain_key' => $domain->key(), 'fact_key' => $fact->key, 'customer_id' => $customer->id]);

            return PendingFactAnswer::rejected($productId, $validation->reason);
        }

        $this->memory->record($customer, $domain->key(), $fact, $validation->value, CustomerFact::SOURCE_CUSTOMER_ANSWER, $sourceWamid);
        $conversation->update(['pending_state' => null]);

        Log::info('FACT_RECORDED', ['domain_key' => $domain->key(), 'fact_key' => $fact->key, 'customer_id' => $customer->id]);

        return PendingFactAnswer::answered($productId);
    }

    private function answerConsent(Conversation $conversation, Customer $customer, DomainModule $domain, FactDefinition $fact, int $productId, string $message): PendingFactAnswer
    {
        $answer = mb_strtolower(preg_replace('/^[\s¡!.,]+|[\s¡!.,]+$/u', '', $message));

        if (in_array($answer, self::YES, true)) {
            $this->memory->grant($customer, $fact->key, $domain->key());
            $conversation->update(['pending_state' => null]);

            Log::info('FACT_SHARE_GRANTED', ['domain_key' => $domain->key(), 'fact_key' => $fact->key, 'customer_id' => $customer->id]);

            return PendingFactAnswer::answered($productId);
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

        return PendingFactAnswer::rejected($productId, 'Responde sí o no, por favor.');
    }

    private function setPending(Conversation $conversation, string $type, DomainModule $domain, Product $product, string $factKey): void
    {
        $conversation->update(['pending_state' => [
            'type' => $type,
            'domain_key' => $domain->key(),
            'product_id' => $product->id,
            'fact_key' => $factKey,
        ]]);
    }
}
