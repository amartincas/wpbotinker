<?php

namespace App\Platform\Conversation;

use App\Factories\AIServiceFactory;
use App\Models\Conversation;
use App\Models\Payment;
use App\Models\Product;
use App\Models\SalesFunnel;
use App\Models\SampleDelivery;
use App\Models\Store;
use App\Models\WhatsAppMessage;
use App\Platform\Capabilities\CapabilityRunner;
use App\Platform\Commerce\AccessDecision;
use App\Platform\Commerce\AccessGate;
use App\Platform\Commerce\CommerceResponder;
use App\Platform\Commerce\CommercialAdvisor;
use App\Platform\Commerce\CommercialClaims;
use App\Platform\Commerce\FreeTurn;
use App\Platform\Commerce\OpeningMessage;
use App\Platform\Commerce\SampleLedger;
use App\Platform\Contracts\DomainModule;
use App\Platform\Contracts\ProvidesRetrievalGuide;
use App\Platform\Domains\DomainRegistry;
use App\Platform\Funnel\FunnelDefinition;
use App\Platform\Funnel\FunnelStep;
use App\Platform\Funnel\GuidedFunnel;
use App\Platform\Funnel\SalesFunnelRunner;
use App\Platform\Funnel\StageReply;
use App\Platform\Knowledge\KnowledgeAccess;
use App\Platform\Knowledge\KnowledgePlanner;
use App\Platform\Knowledge\KnowledgeRetriever;
use App\Platform\Memory\CustomerMemory;
use App\Platform\Memory\FactCollector;
use App\Services\WhatsAppService;
use App\Services\WhatsAppStatusTracker;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Conversation flow for stores in 'knowledge' mode (see
 * App\Models\Store::MODE_KNOWLEDGE). It only orchestrates Core services, in
 * order, and holds no domain or memory rules of its own:
 *
 *   FactCollector (pending answer) → CommerceResponder (commercial
 *   requests, always served) → ProductResolver (or clarification)
 *   → AccessGate → FactCollector (missing facts) → AccessGate again at
 *   the entry of the protected service (full service, free sample via
 *   SampleLedger, or the commercial answer, with CommercialAdvisor's
 *   configured resources when they fit) → CustomerMemory (visible
 *   facts) → CapabilityRunner (computed result) → KnowledgeRetriever
 *   → PromptAssembler → LLM → reply.
 *
 * It never touches the sales flow (leads, Conversions API, catalog images).
 */
class ConversationPipeline
{
    private const HISTORY_LIMIT = 10;

    /** How long a generated sample waits for a retry (the job's retry window). */
    private const SAMPLE_CACHE_MINUTES = 10;

    /** Characters kept of the question a clarification interrupted. */
    private const MAX_ORIGIN_LENGTH = 1000;

    /** How close (percent) a fallback reply may come to a recent reply before it counts as said again. */
    private const REPEATED_SIMILARITY = 85;

    /** The store's welcome, still to be put before this turn's first reply (see withWelcome()). */
    private ?string $welcome = null;

    /**
     * Set by answer() when a free sample found no section of its topic for
     * the query (not a failure): the sources do not cover it.
     */
    private bool $uncovered = false;

    /** This turn's conversation and customer: who send() may add plan buttons for. */
    private ?Conversation $conversation = null;

    private $customer = null;

    public function __construct(
        private readonly ProductResolver $resolver,
        private readonly DomainRegistry $domains,
        private readonly CustomerMemory $memory,
        private readonly FactCollector $facts,
        private readonly KnowledgeRetriever $knowledge,
        private readonly PromptAssembler $prompts,
        private readonly CapabilityRunner $capabilities,
        private readonly AccessGate $access,
        private readonly SampleLedger $samples,
        private readonly CommerceResponder $commerce,
        private readonly KnowledgePlanner $planner,
        private readonly CommercialAdvisor $advisor,
        private readonly TopicClassifier $topics,
        private readonly OpeningMessage $openings,
        private readonly SalesFunnelRunner $funnels,
        private readonly KnowledgeAccess $knowledgeAccess,
        private readonly CommercialClaims $claims,
    ) {}

    /**
     * @param  string|null  $choiceId  the id of the reply button the customer tapped, if the message is one
     */
    public function handle(Store $store, string $from, string $messageBody, ?int $adProductId = null, ?string $wamid = null, ?string $choiceId = null): void
    {
        $conversation = Conversation::firstOrCreate(
            ['store_id' => $store->id, 'customer_phone' => $from],
            ['last_session_at' => now()],
        );
        $customer = $this->memory->customerFor($store, $from);
        $this->welcome = $this->welcomeFor($store, $from);
        $this->uncovered = false;
        $this->conversation = $conversation;
        $this->customer = $customer;

        // A plan's button goes straight to its payment, before anything else
        // reads the message: no free reply, no model.
        if ($choiceId !== null && ($chosen = $this->commerce->choosePlan($store, $conversation, $customer, $choiceId)) !== null) {
            $this->reply($store, $from, $messageBody, $chosen->text, $chosen->domainKey);

            return;
        }

        $pending = $this->facts->answerPending($conversation, $customer, $messageBody, $wamid);

        if ($pending->reply !== null) {
            $this->reply($store, $from, $messageBody, $pending->reply, $pending->replyDomainKey);

            return;
        }

        // Commercial requests (prices, plans, paying, renewing, payment
        // status) are always served, with or without access.
        $catalog = $this->resolver->catalog($store);
        $target = $this->commercialTarget($store, $conversation, $messageBody, $adProductId, $catalog);

        if ($commercial = $this->commerce->respond($store, $conversation, $customer, $messageBody, $catalog, $target)) {
            $this->reply($store, $from, $messageBody, $commercial->text, $commercial->domainKey);

            return;
        }

        $product = $pending->answeredProductId ? $this->resolver->catalogProduct($store, $pending->answeredProductId) : null;
        $clarifiedQuery = null;

        if ($product === null) {
            $resolution = $this->resolver->resolve($store, $conversation, $messageBody, $adProductId);

            // Nothing pointed to a product: a store that configured its open
            // question asks (or identifies the topic) instead of listing the catalog.
            if ($resolution->unidentified && filled($store->topic_prompt_message)) {
                $resolution = $this->identifyTopic($store, $from, $messageBody, $catalog);

                if ($resolution === null) {
                    return;
                }
            }

            if ($resolution->needsClarification()) {
                // The question asked is kept: once the customer picks an
                // option, it is what gets answered, not the choice itself.
                // A clarification asked again keeps the first question.
                $previous = $conversation->pending_state;
                $origin = ($previous['type'] ?? null) === ProductResolver::PENDING_CLARIFICATION && filled($previous['origin_message'] ?? null)
                    ? $previous['origin_message']
                    : mb_substr(trim($messageBody), 0, self::MAX_ORIGIN_LENGTH);

                $conversation->update(['pending_state' => [
                    'type' => ProductResolver::PENDING_CLARIFICATION,
                    'product_ids' => $resolution->options->pluck('id')->all(),
                    'origin_message' => $origin,
                ]]);

                Log::info('PIPELINE_CLARIFICATION', [
                    'store_id' => $store->id,
                    'customer_phone' => $from,
                    'option_count' => $resolution->options->count(),
                ]);

                $this->reply($store, $from, $messageBody, $this->clarificationText($resolution->options));

                return;
            }

            $product = $resolution->product;

            // The customer only picked an option: what gets answered is the
            // question the clarification interrupted.
            if ($resolution->answersClarification && filled($conversation->pending_state['origin_message'] ?? null)) {
                $clarifiedQuery = (string) $conversation->pending_state['origin_message'];
            }
        }

        $domain = $product ? $this->domains->get($product->domain_key, $store->id) : null;

        $conversation->update(['current_product_id' => $product?->id, 'pending_state' => null]);

        // A customer without access to a product with an active funnel goes
        // through the funnel; without a funnel, nothing below changes.
        if ($product && $domain && $product->activeFunnel() !== null && $this->funnels->appliesTo($product, $access = $this->access->decide($customer, $product))) {
            $this->runFunnel($store, $conversation, $from, $messageBody, $clarifiedQuery ?? $messageBody, $customer, $product, $domain, $access, $wamid);

            return;
        }

        // Commercial access, from persisted state only. Without access and
        // without a sample to give, no facts are asked: the customer gets
        // the commercial answer straight away.
        if ($product && ($access = $this->access->decide($customer, $product)) && ! $access->allowed && ! $access->sampleAvailable) {
            $this->replyBlocked($store, $conversation, $from, $messageBody, $customer, $product, $domain, $access);

            return;
        }

        // What the customer asked: when this message only answered a question
        // for the product's data, or only picked an option, the query that
        // led to that question.
        $query = ($product ? $pending->originFor($product->id) : null) ?? $clarifiedQuery ?? $messageBody;

        if ($product && $domain && ($question = $this->facts->require($conversation, $customer, $product, $domain, $pending->rejectionFor($product->id), $query))) {
            Log::info('PIPELINE_FACT_REQUESTED', ['store_id' => $store->id, 'customer_phone' => $from, 'domain_key' => $domain->key()]);

            $this->reply($store, $from, $messageBody, $question, $domain->key());

            return;
        }

        // Checked again at the entry of the protected service: the state may
        // have changed while facts were being collected. Without access, the
        // only way in is the free sample, reserved before it is generated.
        $sample = null;
        $freeTurn = null;

        if ($product && ! ($access = $this->access->decide($customer, $product))->allowed) {
            $sample = $access->sampleAvailable ? $this->samples->reserve($customer, $product) : null;

            if ($sample === null) {
                $this->replyBlocked($store, $conversation, $from, $messageBody, $customer, $product, $domain, $access);

                return;
            }

            $freeTurn = new FreeTurn($sample->turns_used, SampleLedger::limit());
        }

        // The product's configured opening, exactly as written, is the first
        // free reply when the message is one of its triggers; it replaces
        // the store's generic welcome.
        $opening = $freeTurn?->number === 1 ? $this->openings->for($product, $messageBody) : null;

        if ($opening !== null) {
            $this->welcome = null;

            Log::info('PIPELINE_OPENING', ['store_id' => $store->id, 'customer_phone' => $from, 'product_id' => $product->id]);
        }

        try {
            // A retry of the same message reuses the sample already generated.
            $answer = $opening ?? ($sample ? $this->rememberedSample($store, $wamid) : null);
            $answer ??= $this->answer($store, $from, $messageBody, $query, $customer, $product, $domain, $freeTurn);
        } catch (Throwable $e) {
            // Nothing was delivered: the sample stays available.
            if ($sample) {
                $this->samples->release($sample);
            }

            throw $e;
        }

        if ($answer === null) {
            if ($sample) {
                $this->samples->release($sample);
            }

            // The sources do not cover the query: say so (the sample stays
            // available). Anything else failed: the customer may retry.
            $text = $this->uncovered && filled($store->topic_unavailable_message)
                ? trim($store->topic_unavailable_message)
                : "No pude completar tu solicitud sobre «{$product->name}» en este momento. Intenta de nuevo en unos minutos.";

            $this->reply($store, $from, $messageBody, $text, $domain?->key());

            return;
        }

        if ($sample) {
            // Only the last free reply carries the offer; the others leave
            // the conversation open.
            $this->rememberSample($store, $wamid, $answer);
            $this->deliverSample($store, $from, $messageBody, $freeTurn->isLast() ? $answer."\n\n".$this->commerce->afterSampleText($product) : $answer, $domain?->key(), $sample, $wamid);

            if ($freeTurn->isLast()) {
                $this->commerce->recordOfferShown($conversation, $product);
            }

            return;
        }

        $this->reply($store, $from, $messageBody, $answer, $domain?->key());
    }

    /**
     * Executes what the funnel's Runner decides, with the same services as
     * everything else: AccessGate already decided, SampleLedger reserves the
     * free replies, CommerceResponder owns plans and payments. Who already
     * was a customer, or has a payment open, gets the Core's answer.
     *
     * Every reply goes through deliverTurn(), which fails the job if
     * WhatsApp did not take it: until then nothing is spent, recorded or
     * moved, so the queue's retry finds everything as it was. Once sent,
     * nothing fails the job, or the retry would send it again.
     */
    private function runFunnel(Store $store, Conversation $conversation, string $from, string $messageBody, string $query, $customer, Product $product, DomainModule $domain, AccessDecision $access, ?string $wamid): void
    {
        $funnel = $product->activeFunnel();
        // A prospect of a guided funnel with a payment open keeps conversing: the Core never starts a
        // second one (CommerceResponder::pay()). Only the retry of the purchase that opened it gets its message again.
        $paymentBlocks = $access->openPayment !== null
            && (! $funnel->funnelDefinition()->guided || Cache::has($this->purchaseDestinationKey($access->openPayment)));

        if (! $this->funnels->isProspect($access) || $paymentBlocks) {
            $this->replyBlocked($store, $conversation, $from, $messageBody, $customer, $product, $domain, $access);
            $this->finishPurchase($store, $conversation, $product, $access, $wamid);

            return;
        }

        $step = $this->funnels->begin($conversation, $product, $funnel, $access, $messageBody, $query);

        Log::info('FUNNEL_STEP', ['store_id' => $store->id, 'product_id' => $product->id, 'version' => $funnel->version, 'kind' => $step->kind, 'stage' => $step->stage?->key, 'next' => $step->nextStage]);

        if ($step->kind === FunnelStep::BLOCKED) {
            $this->replyBlocked($store, $conversation, $from, $messageBody, $customer, $product, $domain, $access);

            return;
        }

        if ($step->kind === FunnelStep::READ) {
            $this->commercialTurn($store, $conversation, $from, $messageBody, $query, $customer, $product, $domain, $funnel, $step, $access, $wamid);

            return;
        }

        if ($step->kind === FunnelStep::ACTION && $step->action === FunnelDefinition::ACTION_START_PAYMENT) {
            // In a guided funnel only a plan the customer chose starts a payment, even with one plan to choose from.
            $this->deliverPurchase($store, $conversation, $from, $messageBody, $customer, $product, $domain, $funnel, $step->definition, $step->definition->guided, $step->nextStage, $step->interactions);

            return;
        }

        if ($step->kind === FunnelStep::ACTION) {
            $this->deliverTurn($store, $from, $messageBody, $this->funnelAction($store, $conversation, $messageBody, $customer, $product, $step->definition, $step->action, false), $domain->key());
            $this->afterFunnelOffer($step->action === FunnelDefinition::ACTION_SHOW_OFFER, $conversation, $product);
            $this->funnels->advance($conversation, $product, $funnel, $step->nextStage, $step->interactions);

            return;
        }

        if ($step->kind === FunnelStep::LITERAL) {
            $reservation = null;

            try {
                // A literal goes out as configured, in place of the store's welcome.
                $this->welcome = null;
                $reservation = $step->useFreeReply && $access->sampleAvailable ? $this->samples->reserve($customer, $product) : null;
                $this->deliverTurn($store, $from, $messageBody, $this->fillPlaceholders($step->text, $product), $domain->key());
                $this->afterFunnelOffer($this->carriesPlans($step->text), $conversation, $product);
                $this->funnels->advance($conversation, $product, $funnel, $step->nextStage, 0);
            } catch (Throwable $e) {
                // Nothing was delivered: the free reply stays available and the state does not move.
                $this->releaseFunnelReply($reservation);

                throw $e;
            }

            return;
        }

        $this->converse($store, $conversation, $from, $messageBody, $query, $customer, $product, $domain, $funnel, $step, $access, $wamid);
    }

    /**
     * A turn of the funnel's AI stage that spends a free reply when the step
     * uses one; in a guided funnel, the free reply the Core granted to a
     * request for content (see commercialTurn()).
     */
    private function converse(Store $store, Conversation $conversation, string $from, string $messageBody, string $query, $customer, Product $product, DomainModule $domain, SalesFunnel $funnel, FunnelStep $step, AccessDecision $access, ?string $wamid): void
    {
        $reservation = null;

        try {
            // Tied to this message: a retry, even after the worker died before sending, gets it back instead of another.
            $reservation = $step->useFreeReply ? $this->samples->reserve($customer, $product, $wamid) : null;

            if ($step->useFreeReply && $reservation === null) {
                if ($step->definition->guided) {
                    // Spent meanwhile: the request gets what any other without a free reply gets.
                    $this->commercialTurn($store, $conversation, $from, $messageBody, $query, $customer, $product, $domain, $funnel, FunnelStep::read($step->definition, $step->stage, $step->interactions), $access, $wamid, mayGiveFree: false);
                } else {
                    $this->replyBlocked($store, $conversation, $from, $messageBody, $customer, $product, $domain, $access);
                }

                return;
            }

            $freeTurn = $reservation !== null ? new FreeTurn($reservation->turns_used, SampleLedger::limit($product)) : null;
            $facts = $this->advisor->shareableFacts($domain, $this->memory->visibleFacts($customer, $domain));
            $knowledge = $this->knowledgeAccess->forStage($store, $domain, $product, $access, $step->stage, $query, $facts);

            // A retry of the same message reuses the answer already generated.
            $raw = $this->rememberedSample($store, $wamid);

            if ($raw === null) {
                $raw = AIServiceFactory::make($store)->getResponse(
                    $messageBody,
                    $this->prompts->buildFunnelStage($store, $domain, $product, $step->definition, $step->stage, $facts, $knowledge['reference'], $freeTurn, $query !== $messageBody ? $query : null, $step->definition->guided ? $this->commerce->planSummary($product) : null),
                    $this->history($store, $from, $domain->key()),
                );
                $this->rememberSample($store, $wamid, $raw);
            }

            $reply = StageReply::parse($raw, $step->stage->hasIntentRules(), $step->definition->intents);
            $violation = $reply->reply !== null ? $this->claims->funnelViolation($reply->reply, $product->name) : 'empty';

            Log::info('FUNNEL_REPLY', ['store_id' => $store->id, 'product_id' => $product->id, 'stage' => $step->stage->key, 'intent' => $reply->intent, 'confidence' => $reply->confidence, 'free_turn' => $freeTurn?->number, 'discarded' => $violation]);

            // A reply that cannot go out spends nothing and moves nothing.
            if ($violation !== null) {
                $this->releaseFunnelReply($reservation, $wamid);
                $unavailable = filled($store->topic_unavailable_message);
                $this->deliverTurn($store, $from, $messageBody, $unavailable ? trim($store->topic_unavailable_message) : $this->commerce->funnelOffer($product, $step->definition->offerCta), $domain->key());
                $this->afterFunnelOffer(! $unavailable, $conversation, $product);
                $this->forgetSample($store, $wamid);

                return;
            }

            $outcome = $this->funnels->afterReply($step, $reply);

            // A purchase answers with the Core's own text, and is no free reply.
            if ($outcome->action === FunnelDefinition::ACTION_START_PAYMENT) {
                $this->releaseFunnelReply($reservation, $wamid);
                $this->deliverPurchase($store, $conversation, $from, $messageBody, $customer, $product, $domain, $funnel, $step->definition, true, $outcome->nextStage, $outcome->interactions);
                $this->forgetSample($store, $wamid);

                return;
            }

            $parts = [$reply->reply, ...$knowledge['verbatim']];

            // A guided funnel's last free reply closes with the offer, and so does
            // the model's own proposal: with how to ask for it when the plans were
            // just shown. A funnel made of stages shows what its rules say.
            $lastFree = $step->definition->guided && $freeTurn?->isLast();
            $proposed = $outcome->action === FunnelDefinition::ACTION_SHOW_OFFER;
            $justShown = $step->definition->guided && ($lastFree || $proposed) && $this->commerce->offerRecentlyShown($product, $conversation);
            $showsOffer = ($proposed || $lastFree) && ! $justShown;

            if ($showsOffer && $access->openPayment !== null) {
                // The plan is already chosen: its payment's reminder, not the plans again.
                $parts[] = $this->commerce->paymentReminder($store, $product, $access->openPayment);
                $showsOffer = false;
            } elseif ($showsOffer) {
                $parts[] = $this->funnelAction($store, $conversation, $messageBody, $customer, $product, $step->definition, FunnelDefinition::ACTION_SHOW_OFFER, true);
            } elseif ($justShown) {
                $parts[] = $this->commerce->offerPointer();
            }

            if ($outcome->text !== null) {
                $parts[] = $this->fillPlaceholders($outcome->text, $product);
            }

            $this->deliverTurn($store, $from, $messageBody, implode("\n\n", $parts), $domain->key());
            $this->samples->settle($customer, $product, $wamid);
            $this->afterFunnelOffer($showsOffer || $this->carriesPlans((string) $outcome->text), $conversation, $product);
            $this->forgetSample($store, $wamid);
            $this->funnels->advance($conversation, $product, $funnel, $outcome->nextStage, $outcome->interactions);
        } catch (Throwable $e) {
            // Nothing was delivered: the free reply stays available and the state does not move.
            $this->releaseFunnelReply($reservation, $wamid);

            throw $e;
        }
    }

    /** Gives back a reservation once: the variable is emptied so nothing releases it again. */
    /**
     * Every message of a guided funnel's stage, whether free replies are left
     * or not. One model call, with the conversation's history and no
     * material, follows the funnel's commercial process and reads the
     * message (intent and question); the Core validates both.
     *
     * - A purchase or the offer, validated (limitActionRejection()).
     * - A request for content ('contenido') is the free reply, while the
     *   customer has one: only then is a free reply spent (converse()).
     *   Without one, the model's reply that says it is part of the full
     *   service, if it passes the strict check, or the Core's fixed reply.
     * - A commercial question (a purchase doubt or objection too): the
     *   product's verbatim commercial resources, exactly as written, within
     *   the same read (literalAnswer()); without any configured, or when it
     *   needs no document, the model's reply in the funnel's strategy
     *   (commercialWordingViolation()); when it needs one and none fits,
     *   undocumented_reply.
     * - Anything else: the model's reply only in the strict shape that gives
     *   no content, or the Core's fixed reply.
     *
     * The reads (after_limit_reads) bound the commercial turns of the
     * conversation; a turn that became a free reply gives its read back, as
     * free replies have their own limit. Once they ran out, a customer with
     * free replies left still gets a grace read per free reply left, and one
     * after each reactivation sent, only so the message can be read: a grace
     * read is no free reply (FunnelStates::takeRead()). Without any read the
     * message is not read: it gets no free reply, only how to ask for the
     * plans (and the fixed reply once the free replies are over).
     *
     * A commercial wording is checked against the approved facts before it
     * goes out (CommercialAdvisor::checkClaims()), only observed and logged
     * for now: it never changes the reply.
     * Only a commercial question gets the
     * plan list, at its configured frequency; any other message, only how to
     * ask for it.
     */
    private function commercialTurn(Store $store, Conversation $conversation, string $from, string $messageBody, string $query, $customer, Product $product, DomainModule $domain, SalesFunnel $funnel, FunnelStep $step, AccessDecision $access, ?string $wamid, bool $mayGiveFree = true): void
    {
        $outcome = null;
        $reply = null;
        $facts = [];
        // Its own cache key: the free reply of this same message caches under the message's.
        $turnKey = $wamid !== null ? "{$wamid}#turn" : null;
        // A free reply this same message reserved before the worker died is still its own.
        $held = $mayGiveFree && $this->samples->holds($customer, $product, $wamid);
        $free = $mayGiveFree && ($access->sampleAvailable || $held);
        $readKind = $this->funnels->takeLimitRead(
            $conversation, $product, $funnel, $step->stage->key, $wamid,
            $free ? $this->samples->remaining($customer, $product) + ($held ? 1 : 0) : 0,
            $free ? $conversation->reactivation_episode_at?->format('Y-m-d H:i:s') : null,
        );
        $canRead = $readKind !== null;

        if ($canRead) {
            try {
                $facts = $this->advisor->shareableFacts($domain, $this->memory->visibleFacts($customer, $domain));

                // A retry of the same message reuses the answer already generated.
                $raw = $this->rememberedSample($store, $turnKey);

                if ($raw === null) {
                    $raw = AIServiceFactory::make($store)->getResponse(
                        $messageBody,
                        $this->prompts->buildFunnelStage($store, $domain, $product, $step->definition, $step->stage, $facts, collect(), null, null, $this->commerce->planSummary($product), commercialTurn: true),
                        $this->history($store, $from, $domain->key()),
                        // The shape of the answer, where the provider supports it; its values are still validated.
                        StageReply::schema($step->definition->intents, PromptAssembler::fallbackEnabled()),
                    );
                    $this->rememberSample($store, $turnKey, $raw);
                }

                $reply = StageReply::parse($raw, true, $step->definition->intents);
                $outcome = $this->funnels->afterReply($step, $reply);
            } catch (Throwable $e) {
                Log::warning('FUNNEL_LIMIT_READ_FAILED', ['store_id' => $store->id, 'product_id' => $product->id, 'error' => $e::class]);
            }
        }

        $rejected = $outcome?->action !== null ? $this->limitActionRejection($outcome->action, $reply) : null;
        $action = $rejected === null ? $outcome?->action : null;
        $commercial = $reply?->question === StageReply::QUESTION_COMMERCIAL;
        $content = $reply?->question === StageReply::QUESTION_CONTENT;
        // A greeting or a thanks, as the model read it, or plainly one when the model did not read it.
        $courtesy = $reply?->question === StageReply::QUESTION_COURTESY || ($reply?->question === null && GuidedFunnel::isCourtesy($messageBody));
        // A refusal, frustration or anger: answered calmly like a courtesy, never with the plans nor a payment.
        $declined = $reply?->question === StageReply::QUESTION_DECLINE;

        if ($declined) {
            // Not now: this episode gets no reactivation. Not an opt-out, and never a reason for this turn to fail.
            try {
                GuidedFunnel::declineEpisode($conversation);
            } catch (Throwable $e) {
                Log::warning('REACTIVATION_DECLINE_NOT_SAVED', ['store_id' => $store->id, 'error' => $e::class]);
            }
        }
        // With a payment open, its own short reminder stands for the plans: the customer already chose one.
        $payment = $access->openPayment;
        $read = ['store_id' => $store->id, 'product_id' => $product->id, 'intent' => $reply?->intent, 'confidence' => $reply?->confidence, 'question' => $reply?->question, 'action_rejected' => $rejected, 'free_available' => $free, 'read' => $readKind];
        // How the model answered, never its words: kept last so the fields above stay in their order.
        $shape = ['question_field' => $reply?->questionField, 'reply_characters' => $reply?->reply !== null ? mb_strlen($reply->reply) : 0];

        if ($action === FunnelDefinition::ACTION_START_PAYMENT) {
            Log::info('FUNNEL_LIMIT_READ', $read + ['action' => $action, 'discarded' => null] + $shape);
            $this->deliverPurchase($store, $conversation, $from, $messageBody, $customer, $product, $domain, $funnel, $step->definition, true, $outcome->nextStage, $outcome->interactions);
            $this->forgetSample($store, $turnKey);

            return;
        }

        if ($action === FunnelDefinition::ACTION_SHOW_OFFER) {
            // The model's argument goes first, only if it passes like any commercial wording; then the
            // Core's official plans, or how to ask for them when they were just shown.
            $argumentViolation = $reply->reply !== null ? $this->claims->commercialWordingViolation($reply->reply, $product->name, (array) $product->aliases) : 'empty';
            $argument = $argumentViolation === null ? $reply->reply : null;
            $repeated = $payment !== null || $this->commerce->offerRecentlyShown($product, $conversation);
            $offer = match (true) {
                $payment !== null => $this->commerce->paymentReminder($store, $product, $payment),
                $repeated => $this->commerce->offerPointer(),
                default => $this->funnelAction($store, $conversation, $messageBody, $customer, $product, $step->definition, $action, true),
            };

            Log::info('FUNNEL_LIMIT_READ', $read + ['action' => $action, 'discarded' => $argumentViolation, 'offer_repeated' => $repeated] + $shape);

            if ($argument !== null) {
                $this->observeClaims($store, $domain, $product, $step, $argument, $wamid);
            }

            $this->deliverTurn($store, $from, $messageBody, $argument !== null ? $argument."\n\n".$offer : $offer, $domain->key());
            $this->afterFunnelOffer(! $repeated, $conversation, $product);
            $this->forgetSample($store, $turnKey);
            $this->funnels->advance($conversation, $product, $funnel, $outcome->nextStage, $outcome->interactions);

            return;
        }

        // Only a request for content is the free reply, while the customer has one. A message the
        // model did not read (no reads left, or it failed) never gets one by default.
        if ($free && $content) {
            Log::info('FUNNEL_LIMIT_READ', $read + ['action' => null, 'discarded' => null, 'free_reply' => true] + $shape);
            $this->funnels->releaseLimitRead($conversation, $product, $wamid, $readKind);

            $this->converse($store, $conversation, $from, $messageBody, $query, $customer, $product, $domain, $funnel, FunnelStep::converse($step->definition, $step->stage, $step->interactions, true), $access, $wamid);
            $this->forgetSample($store, $turnKey);

            return;
        }

        $literal = $outcome !== null && $commercial ? $this->advisor->literalAnswer($store, $domain, $product, $messageBody, $facts) : null;

        // A commercial question follows the funnel's strategy in the model's words when no verbatim
        // resource had to answer it: none configured, or one that needs no document (an objection).
        // A failed selection leaves it unknown. Anything else goes out only in the strict shape.
        $freeWording = $commercial && in_array($literal['reason'] ?? null, ['no_resources', 'no_instructions', 'not_needed'], true);
        $violation = match (true) {
            $reply?->reply === null => 'empty',
            $freeWording => $this->claims->commercialWordingViolation($reply->reply, $product->name, (array) $product->aliases),
            // A courtesy is answered as one: short and giving nothing, but with no invitation to the plans.
            $courtesy || $declined => $this->claims->courtesyReplyViolation($reply->reply, $product->name, (array) $product->aliases),
            default => $this->claims->limitReplyViolation($reply->reply, $product->name, (array) $product->aliases),
        };

        $literalReason = $literal['reason'] ?? null;
        $sendsWording = ! in_array($literalReason, ['answered', 'no_match'], true) && $outcome !== null && $violation === null;

        // Where a fixed text would go instead of the reply (a message not classified, a commercial wording that
        // cannot go out), the model's own safer answer of the same call goes, when it passes: checked like any
        // commercial wording, short, never commercial, never what the conversation already said.
        $fallback = null;
        $fallbackDiscarded = null;

        if ($free && $canRead && $outcome !== null && ! $sendsWording && ! $courtesy && ! $declined
            && ! in_array($literalReason, ['answered', 'no_match'], true) && PromptAssembler::fallbackEnabled()) {
            $fallbackDiscarded = match (true) {
                $reply->fallback === null => 'empty',
                default => $this->claims->fallbackReplyViolation($reply->fallback, $product->name, (array) $product->aliases)
                    ?? ($this->repeatsConversation($store, $from, $domain->key(), $reply->fallback) ? 'repeated' : null),
            };
            $fallback = $fallbackDiscarded === null ? $reply->fallback : null;
        }

        if ($outcome !== null) {
            Log::info('FUNNEL_LIMIT_READ', $read + ['action' => null, 'discarded' => $violation, 'commercial_reason' => $literalReason] + $shape
                + ['fallback' => $fallback !== null ? 'model' : ($fallbackDiscarded !== null ? 'fixed' : null), 'fallback_discarded' => $fallbackDiscarded]);
        } elseif (! $canRead) {
            Log::info('FUNNEL_LIMIT_READS_EXHAUSTED', ['store_id' => $store->id, 'product_id' => $product->id]);
        }

        // A commercial question gets the offer at its configured frequency; any other message, only how to ask for it.
        $offer = fn () => match (true) {
            $payment !== null => $this->commerce->paymentReminder($store, $product, $payment),
            $commercial => $this->commerce->offerAfterCommercialReply($store, $product, $access, $conversation),
            default => $this->commerce->offerPointer(),
        };
        // Plans just shown, or a payment already open: an answer goes alone, without inviting to them again.
        $answered = fn (string $answer) => $payment !== null || $this->commerce->offerRecentlyShown($product, $conversation) ? $answer : $answer."\n\n".$offer();

        $text = match (true) {
            $literalReason === 'answered' => $answered($literal['text']),
            $literalReason === 'no_match' => trim((string) config('botinker.sales_funnels.guided.undocumented_reply'))."\n\n".$offer(),
            ($courtesy || $declined) && $sendsWording => $reply->reply,
            $courtesy => trim((string) config('botinker.sales_funnels.guided.courtesy_reply')),
            $declined => trim((string) config('botinker.sales_funnels.guided.decline_reply')),
            $sendsWording => $answered($reply->reply),
            // A message the model read but did not classify, while a free reply is left: an invitation to say
            // more, neither the plans nor content, and the free reply stays. A message no read was left for is
            // not invited to say more, as nothing would read it. Without a free reply, the limit as always.
            $free && $canRead && ! $commercial => $fallback ?? GuidedFunnel::unclassifiedReply($funnel->definition ?? []) ?? trim((string) config('botinker.sales_funnels.guided.unclassified_reply')),
            // A commercial question whose wording cannot go out: the safer answer, with the plans as any answer gets them.
            $fallback !== null => $answered($fallback),
            // The fixed reply says the free replies are over only when they are.
            $free => $offer(),
            default => trim((string) config('botinker.sales_funnels.guided.limit_reply'))."\n\n".$offer(),
        };

        // A configured text that came out empty (a deploy whose configuration is not cached yet, a key removed):
        // WhatsApp refuses an empty message and the job would fail. The Core's own text goes instead.
        if (trim($text) === '') {
            Log::warning('FUNNEL_EMPTY_REPLY', ['store_id' => $store->id, 'product_id' => $product->id]);
            $text = $offer();
        }

        $text = trim($text);

        // Observed only, and before the send: nothing after it may fail the job, or its retry would send the reply again.
        if ($freeWording && $sendsWording) {
            $this->observeClaims($store, $domain, $product, $step, $reply->reply, $wamid);
        } elseif ($fallback !== null && str_starts_with($text, $fallback)) {
            $this->observeClaims($store, $domain, $product, $step, $fallback, $wamid);
        }

        $this->deliverTurn($store, $from, $messageBody, $text, $domain->key());
        $this->commerce->recordOfferIfSent($conversation, $product, $text);
        $this->forgetSample($store, $turnKey);
    }

    /**
     * Logs how many facts a commercial wording states without support in the
     * approved ones: counts only, never the words of the reply. Observation
     * only: nothing it finds blocks or changes the reply, and any failure
     * only leaves it unchecked.
     */
    private function observeClaims(Store $store, DomainModule $domain, Product $product, FunnelStep $step, string $reply, ?string $wamid): void
    {
        try {
            // Once per message: a retry goes straight to the send, without checking again.
            if ($wamid !== null && ! Cache::add('claims-checked:'.$store->id.':'.hash('sha256', $wamid), true, now()->addMinutes(self::SAMPLE_CACHE_MINUTES + 5))) {
                return;
            }

            $check = $this->advisor->checkClaims($store, $domain, $product, $step->stage->instructions, $this->commerce->plansBlock($product), $reply);

            if ($check !== null) {
                Log::info('FUNNEL_CLAIMS_CHECK', ['store_id' => $store->id, 'product_id' => $product->id, 'mode' => 'observe', 'verdict' => $check['verdict'], 'claims' => $check['claims'], 'unsupported' => $check['unsupported']]);
            }
        } catch (Throwable $e) {
            Log::warning('FUNNEL_CLAIMS_CHECK_FAILED', ['store_id' => $store->id, 'product_id' => $product->id, 'error' => $e::class]);
        }
    }

    /**
     * Why the Core does not take the action the model proposed after the free
     * replies, or null. Both need a message read as commercial: a decision to
     * buy is one ("ok, lo tomo"), while an unread «bueno», «ok» or «gracias»,
     * even right after the offer, never starts a payment.
     */
    private function limitActionRejection(string $action, StageReply $reply): ?string
    {
        return match ($action) {
            FunnelDefinition::ACTION_SHOW_OFFER => $reply->question === StageReply::QUESTION_COMMERCIAL ? null : 'offer_unclassified',
            FunnelDefinition::ACTION_START_PAYMENT => $reply->question === StageReply::QUESTION_COMMERCIAL ? null : 'purchase_without_context',
            default => null,
        };
    }

    private function releaseFunnelReply(?SampleDelivery &$reservation, ?string $inboundWamid = null): void
    {
        if ($reservation !== null) {
            $this->samples->release($reservation, $inboundWamid);
            $reservation = null;
        }
    }

    /** Only what the customer actually got counts as an offer shown. */
    private function afterFunnelOffer(bool $shown, Conversation $conversation, Product $product): void
    {
        if ($shown) {
            $this->commerce->recordOfferShown($conversation, $product);
        }
    }

    /**
     * A purchase: the payment exists before its message goes out. If the send
     * fails, the retry finds that payment open, never starts another, and
     * finishPurchase() takes the funnel where this turn was going.
     */
    private function deliverPurchase(Store $store, Conversation $conversation, string $from, string $messageBody, $customer, Product $product, DomainModule $domain, SalesFunnel $funnel, FunnelDefinition $definition, bool $readByModel, string $nextStage, int $interactions): void
    {
        $text = $this->funnelAction($store, $conversation, $messageBody, $customer, $product, $definition, FunnelDefinition::ACTION_START_PAYMENT, $readByModel);
        $payment = $this->access->decide($customer, $product)->openPayment;

        if ($payment !== null) {
            Cache::put($this->purchaseDestinationKey($payment), ['funnel_id' => $funnel->id, 'stage' => $nextStage, 'interactions' => $interactions], now()->addHours(CommerceResponder::PAYMENT_WINDOW_HOURS));
        }

        $this->deliverTurn($store, $from, $messageBody, $text, $domain->key());
        $this->funnels->advance($conversation, $product, $funnel, $nextStage, $interactions);

        if ($payment !== null) {
            Cache::forget($this->purchaseDestinationKey($payment));
        }
    }

    /**
     * The open payment's message went out: if the purchase that started it
     * failed to send, the funnel now moves where it was going. A funnel
     * published in between is not moved to a stage of the previous version.
     */
    private function finishPurchase(Store $store, Conversation $conversation, Product $product, AccessDecision $access, ?string $wamid): void
    {
        $destination = $access->openPayment !== null ? Cache::get($this->purchaseDestinationKey($access->openPayment)) : null;

        if ($destination === null) {
            return;
        }

        $funnel = $product->activeFunnel();

        if ($funnel?->id === $destination['funnel_id']) {
            $this->funnels->advance($conversation, $product, $funnel, $destination['stage'], $destination['interactions']);
        }

        Cache::forget($this->purchaseDestinationKey($access->openPayment));
        $this->forgetSample($store, $wamid);
    }

    private function purchaseDestinationKey(Payment $payment): string
    {
        return "funnel-purchase-destination:{$payment->id}";
    }

    /**
     * The funnel's operations, always through CommerceResponder. A purchase
     * read by the model never assumes a plan the customer did not name.
     */
    private function funnelAction(Store $store, Conversation $conversation, string $messageBody, $customer, Product $product, FunnelDefinition $definition, string $action, bool $readByModel): string
    {
        return $action === FunnelDefinition::ACTION_START_PAYMENT
            ? $this->commerce->startPurchase($store, $conversation, $customer, $product, $messageBody, $readByModel)->text
            : $this->commerce->funnelOffer($product, $definition->offerCta);
    }

    /** A configured text with the data only the Core has: the plans always come from the catalog. */
    private function fillPlaceholders(string $text, Product $product): string
    {
        // [Planes] asks for them as {planes} does.
        $text = (string) preg_replace(StageReply::PLANS_MARKER, '{planes}', $text);

        return strtr($text, ['{producto}' => $product->name, '{planes}' => str_contains($text, '{planes}') ? $this->commerce->plansBlock($product) : '']);
    }

    /** Whether a configured text asks for the catalog's plans ({planes} or [Planes]). */
    private function carriesPlans(string $text): bool
    {
        return str_contains($text, '{planes}') || preg_match(StageReply::PLANS_MARKER, $text) === 1;
    }

    /**
     * Sent first, recorded once WhatsApp took it, like a sample: a turn that
     * did not go out fails the job, so the queue retries it. Once sent,
     * nothing fails the job.
     */
    private function deliverTurn(Store $store, string $from, string $messageBody, string $text, ?string $domainKey): void
    {
        $text = $this->withWelcome($text);
        $sentWamid = $this->send($store, $from, $text, $sentText);

        if ($sentWamid === null) {
            throw new RuntimeException("The funnel's reply for store [{$store->id}] could not be sent.");
        }

        try {
            WhatsAppMessage::recordDeliveredTurn($store->id, $from, $domainKey, $messageBody, $sentText, $sentWamid);
        } catch (Throwable $e) {
            Log::error('TURN_HISTORY_NOT_SAVED', ['store_id' => $store->id, 'error' => $e::class]);
        }
    }

    /**
     * Sends the sample first and records the turn only once WhatsApp took
     * it: a sample that did not go out never appears in the history as
     * given. If the send fails, the reservation is released and the job
     * fails, so the existing queue retries try again (no turn was written,
     * and the generated answer is reused, see rememberSample()).
     *
     * Once WhatsApp accepted it, nothing may fail the job any more: a retry
     * would send the customer a contradicting offer. Both turns are saved
     * in one transaction; if that fails, it is logged and the job ends.
     */
    private function deliverSample(Store $store, string $from, string $messageBody, string $answer, ?string $domainKey, SampleDelivery $reservation, ?string $inboundWamid): void
    {
        $answer = $this->withWelcome($answer);
        $sentWamid = $this->send($store, $from, $answer, $sentText);

        if ($sentWamid === null) {
            $this->samples->release($reservation);

            throw new RuntimeException("The free sample of product [{$reservation->product_id}] could not be sent.");
        }

        try {
            $this->forgetSample($store, $inboundWamid);
            WhatsAppMessage::recordDeliveredTurn($store->id, $from, $domainKey, $messageBody, $sentText, $sentWamid);
        } catch (Throwable $e) {
            Log::error('SAMPLE_HISTORY_NOT_SAVED', [
                'store_id' => $store->id,
                'product_id' => $reservation->product_id,
                'error' => $e::class,
            ]);
        }
    }

    /**
     * The sample generated for this inbound message by an earlier attempt,
     * so a retry does not call the LLM again. Null without a message id.
     */
    private function rememberedSample(Store $store, ?string $inboundWamid): ?string
    {
        $cached = $inboundWamid ? Cache::get($this->sampleCacheKey($store, $inboundWamid)) : null;

        if (! is_string($cached)) {
            return null;
        }

        try {
            return Crypt::decryptString($cached);
        } catch (Throwable) {
            return null;
        }
    }

    /** Kept encrypted (it holds the customer's data) for the retry window of the job. */
    private function rememberSample(Store $store, ?string $inboundWamid, string $answer): void
    {
        if ($inboundWamid) {
            Cache::put($this->sampleCacheKey($store, $inboundWamid), Crypt::encryptString($answer), now()->addMinutes(self::SAMPLE_CACHE_MINUTES));
        }
    }

    private function forgetSample(Store $store, ?string $inboundWamid): void
    {
        if ($inboundWamid) {
            Cache::forget($this->sampleCacheKey($store, $inboundWamid));
        }
    }

    private function sampleCacheKey(Store $store, string $inboundWamid): string
    {
        return "sample-answer:{$store->id}:".hash('sha256', $inboundWamid);
    }

    /**
     * The LLM answer for the product (its full service, or its free sample),
     * or null when its capability failed: then nothing of the protected
     * service is produced this turn.
     *
     * @param  string  $query  what the customer asked (see handle()); it differs from
     *                         $messageBody when this message answered a question for
     *                         their data, and then joins it in the knowledge search
     */
    private function answer(Store $store, string $from, string $messageBody, string $query, $customer, ?Product $product, ?DomainModule $domain, ?FreeTurn $freeTurn): ?string
    {
        $isSample = $freeTurn !== null;
        $facts = $domain ? $this->memory->visibleFacts($customer, $domain) : [];
        $capabilityKey = $isSample ? $product?->sample_capability_key : $product?->capability_key;
        $computed = null;

        if ($product && $domain && filled($capabilityKey)) {
            try {
                $computed = $this->capabilities->run($customer, $product, $domain, $capabilityKey);
            } catch (Throwable $e) {
                Log::error('CAPABILITY_FAILED', [
                    'store_id' => $store->id,
                    'product_id' => $product->id,
                    'capability_key' => $capabilityKey,
                    'error' => $e::class,
                ]);

                return null;
            }
        }

        // A computed result that names its knowledge entries gets exactly
        // those; otherwise knowledge is searched with the customer's query.
        $keyed = $computed !== null && $computed->knowledgeKeys !== [];
        // The original query adds to this message (which may itself carry
        // meaning, e.g. a question asked as a fact); it never replaces it.
        $searchText = $query === $messageBody ? $messageBody : $query."\n".$messageBody;

        // A later free reply usually answers the question the previous one
        // asked ("en el trabajo"): the customer's previous message keeps
        // the search on the subject, since a free reply must rest on the
        // sources.
        if ($freeTurn !== null && $freeTurn->number > 1 && $domain !== null && ($previous = $this->previousCustomerMessage($store, $from, $domain->key())) !== null) {
            $searchText = $previous."\n".$searchText;
        }
        $guided = ! $keyed && $domain instanceof ProvidesRetrievalGuide && trim($domain->retrievalGuide()) !== '';
        $fragments = match (true) {
            $domain === null => collect(),
            $keyed => $this->knowledge->retrieveByKeys($store, $domain->key(), $product, $computed->knowledgeKeys),
            // The free sample of a guided topic rests on the sections its
            // guide picks from the customer's data, never on whatever words
            // of the message happen to match a title.
            $guided && $isSample => collect(),
            default => $this->knowledge->retrieve($store, $domain->key(), $product, $searchText),
        };

        // A domain with a retrieval guide picks the sections from the
        // customer's data and query, for the free sample and the service
        // alike: words of the message matching a title (another entity, a
        // neighbouring area) never override the guide.
        // Nothing is ever answered as if it rested on sources it does not have:
        // - selection failed: the service falls back to the sections the
        //   message found, if any, so a customer with access still gets an
        //   answer; otherwise, and always for the sample, null (the sample is
        //   released, nothing changes, the customer can retry);
        // - a free sample without sections: null, as above;
        // - a message that needs the sources but got none: a fixed reply
        //   saying so, without the LLM;
        // - a message that needs no sources (thanks, a greeting): answered
        //   normally, without fragments.
        $planned = false;

        if ($guided) {
            $plan = $this->planner->plan($store, $domain, $product, $searchText, $facts, $isSample);

            if ($plan->failed && ! $isSample && $fragments->isNotEmpty()) {
                Log::warning('PIPELINE_KNOWLEDGE_FALLBACK', [
                    'store_id' => $store->id,
                    'domain_key' => $domain->key(),
                    'product_id' => $product?->id,
                    'plan_status' => $plan->status,
                ]);
            } elseif ($plan->failed || ($isSample && $plan->fragments->isEmpty())) {
                $this->uncovered = $isSample && $plan->unsupported();

                Log::warning('PIPELINE_KNOWLEDGE_UNAVAILABLE', [
                    'store_id' => $store->id,
                    'domain_key' => $domain->key(),
                    'product_id' => $product?->id,
                    'sample' => $isSample,
                    'plan_status' => $plan->status,
                ]);

                return null;
            } elseif ($plan->unsupported()) {
                Log::info('PIPELINE_KNOWLEDGE_NOT_FOUND', [
                    'store_id' => $store->id,
                    'domain_key' => $domain->key(),
                    'product_id' => $product?->id,
                    'plan_status' => $plan->status,
                ]);

                return filled($store->topic_unavailable_message)
                    ? trim($store->topic_unavailable_message)
                    : "No encontré información sobre eso en las fuentes de «{$domain->label()}». ¿Puedes contarme un poco más o preguntarlo de otra forma?";
            } else {
                $fragments = $plan->fragments;
                $planned = true;
            }
        }

        $answer = AIServiceFactory::make($store)->getResponse(
            $messageBody,
            $this->prompts->build($store, $domain, $product, $facts, $fragments, $computed, $freeTurn, $query !== $messageBody ? $query : null),
            $this->history($store, $from, $domain?->key()),
        );

        Log::info('PIPELINE_ANSWERED', [
            'store_id' => $store->id,
            'customer_phone' => $from,
            'domain_key' => $domain?->key(),
            'product_id' => $product?->id,
            'sample' => $isSample,
            'free_turn' => $freeTurn?->number,
            'fact_keys' => array_keys($facts),
            'capability_key' => $computed ? $capabilityKey : null,
            'knowledge_retrieval' => $keyed ? 'keys' : ($planned ? 'planned' : 'message'),
            'knowledge_chunk_ids' => $fragments->pluck('id')->all(),
            'original_query' => $query !== $messageBody,
        ]);

        return $answer;
    }

    /**
     * Without access: the deterministic commercial answer (subscription
     * state, plans), preceded by the product's configured commercial
     * resources when CommercialAdvisor finds some that fit the message. The
     * offer counts as shown only once WhatsApp took it; if it did not, the
     * job fails and the retry shows it whole.
     */
    private function replyBlocked(Store $store, Conversation $conversation, string $from, string $messageBody, $customer, Product $product, ?DomainModule $domain, AccessDecision $access): void
    {
        $text = $this->blockedText($store, $conversation, $from, $messageBody, $customer, $product, $domain, $access);

        $this->deliverTurn($store, $from, $messageBody, $text, $product->domain_key);
        $this->commerce->recordOfferIfSent($conversation, $product, $text);
    }

    private function blockedText(Store $store, Conversation $conversation, string $from, string $messageBody, $customer, Product $product, ?DomainModule $domain, AccessDecision $access): string
    {
        $commercial = $domain
            ? $this->advisor->respond($store, $domain, $product, $access, $messageBody, $this->memory->visibleFacts($customer, $domain))
            : null;

        Log::info('PIPELINE_ACCESS_DENIED', [
            'store_id' => $store->id,
            'customer_phone' => $from,
            'product_id' => $product->id,
            'reason' => $access->reason,
            'open_payment_status' => $access->openPayment?->status,
            'commercial' => $commercial !== null,
        ]);

        $offer = $commercial !== null
            ? $this->commerce->offerAfterCommercialReply($store, $product, $access, $conversation)
            : $this->commerce->blockedReply($store, $product, $access, $conversation);

        return $commercial !== null ? $commercial."\n\n".$offer : $offer;
    }

    /**
     * The subscription product a commercial request is about: the ad's, the
     * conversation's, the store's only one, or the one the message names.
     * Null when there is none, or it is ambiguous (then the normal flow,
     * with its clarification, takes over).
     *
     * @param  Collection<int, Product>  $catalog
     */
    private function commercialTarget(Store $store, Conversation $conversation, string $messageBody, ?int $adProductId, Collection $catalog): ?Product
    {
        $subscriptions = $catalog->filter(fn (Product $product) => $product->requiresSubscription())->values();

        foreach ([$adProductId, $conversation->current_product_id] as $id) {
            if ($id && ($product = $subscriptions->firstWhere('id', $id))) {
                return $product;
            }
        }

        if ($subscriptions->count() <= 1) {
            return $subscriptions->first();
        }

        $resolution = $this->resolver->resolve($store, $conversation, $messageBody);

        return $resolution->product?->requiresSubscription() ? $resolution->product : null;
    }

    /**
     * Recent turns of THIS domain only: what the customer said about another
     * domain never reaches the LLM. Untagged turns (clarification questions,
     * operator messages, sales flow) are left out as well.
     *
     * @return list<array{role: string, content: string}>
     */
    /** The customer's last message in this domain before the current one (not yet saved), or null. */
    private function previousCustomerMessage(Store $store, string $from, string $domainKey): ?string
    {
        $content = WhatsAppMessage::where('store_id', $store->id)
            ->where('customer_phone', $from)
            ->where('domain_key', $domainKey)
            ->where('role', 'user')
            ->latest('id')
            ->value('content');

        return is_string($content) && trim($content) !== '' ? $content : null;
    }

    /**
     * Whether the text says again what one of the conversation's recent
     * replies said: the same words, one inside the other, or nearly the same,
     * once case, accents and punctuation are aside.
     */
    private function repeatsConversation(Store $store, string $from, string $domainKey, string $text): bool
    {
        $plain = fn (string $value) => trim((string) preg_replace('/[^a-z0-9]+/', ' ', Str::ascii(mb_strtolower($value))));
        $candidate = $plain($text);

        foreach ($this->history($store, $from, $domainKey) as $message) {
            if ($message['role'] !== 'assistant' || ($said = $plain((string) $message['content'])) === '') {
                continue;
            }

            similar_text($candidate, $said, $percent);

            if ($candidate === $said || $percent >= self::REPEATED_SIMILARITY
                || (mb_strlen($candidate) >= 20 && (str_contains($said, $candidate) || str_contains($candidate, $said)))) {
                return true;
            }
        }

        return false;
    }

    private function history(Store $store, string $from, ?string $domainKey): array
    {
        return WhatsAppMessage::where('store_id', $store->id)
            ->where('customer_phone', $from)
            ->where('domain_key', $domainKey)
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->limit(self::HISTORY_LIMIT)
            ->get(['role', 'content'])
            ->reverse()
            ->map(fn (WhatsAppMessage $message) => ['role' => $message->role, 'content' => $message->content])
            ->values()
            ->all();
    }

    /**
     * A message that pointed to no product, in a store that asks instead of
     * listing its catalog. Returns the product (or the real choice between
     * products) to go on with, or null once it has replied:
     * - only a commercial request ("quiero pagar"): the products to pay for;
     * - a topic the classifier recognized: its product, or a choice among
     *   that topic's products; this same message then gets the answer;
     * - no topic and nothing asked (a greeting), or the classification
     *   failed: the store's open question;
     * - no topic but something asked: the store's "not available" reply
     *   (its open question if that is not configured).
     * No product is set and no sample is used before a topic is known.
     *
     * @param  Collection<int, Product>  $catalog
     */
    private function identifyTopic(Store $store, string $from, string $messageBody, Collection $catalog): ?ProductResolution
    {
        if ($this->commerce->isProductlessRequest($messageBody)) {
            $payable = $catalog->filter(fn (Product $product) => $product->requiresSubscription())->values();

            return ProductResolution::clarify($payable->isNotEmpty() ? $payable : $catalog);
        }

        $classification = $this->topics->classify($store, $messageBody, $catalog);

        if ($classification->topicKey !== null) {
            $products = $catalog->where('domain_key', $classification->topicKey)->values();

            return $products->count() === 1 ? ProductResolution::selected($products->first()) : ProductResolution::clarify($products);
        }

        $unavailable = ! $classification->failed && $classification->request && filled($store->topic_unavailable_message);

        Log::info('PIPELINE_TOPIC_UNIDENTIFIED', [
            'store_id' => $store->id,
            'reply' => $unavailable ? 'unavailable' : 'open_question',
            'classification_failed' => $classification->failed,
        ]);

        $this->reply($store, $from, $messageBody, trim($unavailable ? $store->topic_unavailable_message : $store->topic_prompt_message));

        return null;
    }

    /**
     * @param  Collection<int, Product>  $options
     */
    private function clarificationText(Collection $options): string
    {
        $showDomain = $options->pluck('domain_key')->unique()->count() > 1;

        $lines = $options->values()->map(function (Product $product, int $index) use ($showDomain) {
            $label = $showDomain ? " ({$this->domains->get($product->domain_key, $product->store_id)->label()})" : '';

            return ($index + 1).". {$product->name}{$label}";
        });

        return "¿Sobre cuál de estas opciones quieres hablar?\n"
            .$lines->implode("\n")
            ."\n\nResponde con el número o el nombre.";
    }

    /**
     * @param  string|null  $domainKey  null for clarification turns, which
     *                                  belong to no single domain
     */
    private function reply(Store $store, string $from, string $messageBody, string $answer, ?string $domainKey = null): void
    {
        $answer = $this->withWelcome($answer);

        WhatsAppMessage::create([
            'store_id' => $store->id,
            'customer_phone' => $from,
            'role' => 'user',
            'domain_key' => $domainKey,
            'content' => $messageBody,
        ]);

        $assistantMessage = WhatsAppMessage::create([
            'store_id' => $store->id,
            'customer_phone' => $from,
            'role' => 'assistant',
            'domain_key' => $domainKey,
            'content' => $answer,
        ]);

        $wamid = $this->send($store, $from, $answer, $sentText);

        // The history holds what the customer read. Already sent: nothing here may fail the job.
        if ($sentText !== $answer) {
            try {
                $assistantMessage->update(['content' => $sentText]);
            } catch (Throwable $e) {
                Log::error('TURN_HISTORY_NOT_SAVED', ['store_id' => $store->id, 'error' => $e::class]);
            }
        }

        if ($wamid) {
            WhatsAppStatusTracker::trackMessage($assistantMessage->id, $wamid);
        }
    }

    /**
     * Sends a turn: with a reply button per plan when it carries the plan
     * list of the conversation's product (CommerceResponder::planButtons()),
     * otherwise as text. It falls back to text only when Meta refused the
     * buttons; when the outcome is unknown nothing else is sent, so the
     * customer never gets both, and the caller handles it as a failed send.
     */
    /**
     * @param  string|null  $sentText  set to the text that went out (with its buttons, the buttons'
     *                                 instruction), so the history holds what the customer read
     */
    private function send(Store $store, string $from, string $text, ?string &$sentText = null): ?string
    {
        $sentText = $text;
        $product = $this->conversation?->current_product_id ? Product::where('store_id', $store->id)->find($this->conversation->current_product_id) : null;
        $buttons = $this->commerce->planButtons($product, $this->customer, $text);
        // With its buttons the list says to use them; the text alone keeps its own way to choose.
        $body = $buttons !== null ? $this->commerce->withButtonsInstruction($text) : $text;

        if ($buttons === null || mb_strlen($body) > 1024) {
            return WhatsAppService::sendMessage($from, $text, $store);
        }

        $sent = WhatsAppService::sendInteractiveButtons($from, $body, $buttons, $store);

        if ($sent === false) {
            Log::warning('PLAN_BUTTONS_REFUSED', ['store_id' => $store->id, 'product_id' => $product->id]);

            return WhatsAppService::sendMessage($from, $text, $store);
        }

        $sentText = $body;

        return $sent;
    }

    /**
     * The store's welcome when this customer never wrote to it (no turn of
     * theirs is stored yet), or null; it is then sent once, with the first
     * reply. Without a configured welcome, nothing changes.
     */
    private function welcomeFor(Store $store, string $from): ?string
    {
        $welcome = trim((string) $store->welcome_message);

        if ($welcome === '' || WhatsAppMessage::where('store_id', $store->id)->where('customer_phone', $from)->exists()) {
            return null;
        }

        return $welcome;
    }

    /** The reply, preceded by the pending welcome the first time only. */
    private function withWelcome(string $text): string
    {
        if ($this->welcome === null) {
            return $text;
        }

        $text = $this->welcome."\n\n".$text;
        $this->welcome = null;

        return $text;
    }
}
