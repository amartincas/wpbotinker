<?php

namespace App\Services\ConfigurationImport;

use App\Models\KnowledgeSource;
use App\Models\Product;
use App\Models\Store;
use App\Models\Topic;
use App\Platform\Commerce\CommercialClaims;
use App\Platform\Domains\DomainRegistry;
use App\Platform\Knowledge\KnowledgeFile;
use App\Platform\Knowledge\KnowledgeIngestor;
use App\Platform\Knowledge\KnowledgeRetriever;
use App\Platform\Memory\PersonFacts;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * Loads a configuracion.json (the botinker-config skill's output, schema 1)
 * into one store: its topic, one product, the product's knowledge sources
 * and, only when asked, the store's own texts.
 *
 * The file is data, never code. Every write goes through the same models
 * and ingestor as the panel, so it is held to the same rules. A preview
 * runs the whole import inside a transaction that is always rolled back:
 * what it reports is exactly what the import would do. A value that is
 * null (pending) never erases one already configured. Plans, payment
 * instructions and Meta templates are not imported: the operator owns them.
 */
final class ConfigurationImporter
{
    public const SCHEMA_VERSION = 1;

    public const MAX_BYTES = 2 * 1024 * 1024;

    /** Limits the panel forms enforce and the models do not. */
    private const LIMITS = [
        'store.welcome_message' => 1000,
        'product.name' => 255,
        'product.sample_closing_message' => 1000,
        'product.commercial_instructions' => 4000,
        'product.activation_message' => 2000,
    ];

    private const PRODUCT_TEXTS = [
        'description', 'ai_sales_strategy', 'faq_context', 'commercial_instructions', 'activation_message',
    ];

    private const KNOWN_KEYS = [
        'schema_version', 'generated_at', 'source', 'has_sample', 'store', 'topic', 'product', 'plans',
        'plans_suggestion', 'knowledge_sources', 'pending', 'findings', 'templates',
    ];

    public function __construct(
        private readonly KnowledgeIngestor $ingestor,
        private readonly KnowledgeRetriever $retriever,
        private readonly CommercialClaims $claims,
        private readonly DomainRegistry $domains,
        private readonly PersonFacts $personFacts,
    ) {}

    /** What the import would do. Writes nothing. */
    public function preview(Store $store, string $json, bool $updateStoreTexts, ?int $userId = null): ImportReport
    {
        $report = new ImportReport;
        $config = $this->read($json, $report);

        if ($config === null) {
            return $report;
        }

        DB::beginTransaction();

        try {
            $this->apply($store, $config, $updateStoreTexts, $userId, $report);
        } catch (Throwable $e) {
            $report->error($this->explain($e));
        } finally {
            DB::rollBack();
            // Topics read during the simulation no longer exist.
            $this->domains->forgetTopics();
        }

        return $report;
    }

    /** Imports when the preview has no errors; otherwise writes nothing and reports why. */
    public function import(Store $store, string $json, bool $updateStoreTexts, ?int $userId = null): ImportReport
    {
        $report = $this->preview($store, $json, $updateStoreTexts, $userId);

        if ($report->hasErrors()) {
            return $report;
        }

        $config = $this->read($json, new ImportReport);
        $report = new ImportReport;

        try {
            DB::transaction(function () use ($store, $config, $updateStoreTexts, $userId, $report) {
                $this->apply($store, $config, $updateStoreTexts, $userId, $report);

                if ($report->hasErrors()) {
                    throw new RuntimeException(implode(' ', $report->errors)); // rolls everything back
                }
            });
        } catch (Throwable $e) {
            $this->domains->forgetTopics();
            $failed = new ImportReport;
            $failed->error($this->explain($e));

            return $failed;
        }

        $report->applied = true;

        Log::info('CONFIG_IMPORTED', [
            'store_id' => $store->id,
            'user_id' => $userId,
            'topic_key' => $config['topic']['key'],
            'rows' => $report->summary(),
            'store_texts' => $updateStoreTexts,
        ]);

        return $report;
    }

    /**
     * The decoded file when its structure is valid; null otherwise (errors
     * in the report).
     */
    private function read(string $json, ImportReport $report): ?array
    {
        if (strlen($json) > self::MAX_BYTES) {
            $report->error('El archivo supera el máximo de 2 MB.');

            return null;
        }

        try {
            $config = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $report->error('El archivo no es un JSON válido.');

            return null;
        }

        if (! is_array($config) || array_is_list($config)) {
            $report->error('El archivo no tiene el formato de configuracion.json.');

            return null;
        }

        if (($config['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            $report->error('Versión de formato no soportada: se espera schema_version '.self::SCHEMA_VERSION.'.');

            return null;
        }

        foreach (array_diff(array_keys($config), self::KNOWN_KEYS) as $key) {
            $report->warn("Se ignora la clave desconocida «{$key}».");
        }

        if (array_key_exists('templates', $config)) {
            $report->warn('Se ignoran las plantillas de Meta: son de la tienda y se configuran una sola vez en WhatsApp Templates.');
        }

        $this->checkStructure($config, $report);

        return $report->hasErrors() ? null : $config;
    }

    private function checkStructure(array $config, ImportReport $report): void
    {
        if (! is_bool($config['has_sample'] ?? null)) {
            $report->error('Falta «has_sample» (sí o no hay muestra gratuita).');
        }

        foreach (['topic', 'product'] as $section) {
            if (! is_array($config[$section] ?? null)) {
                $report->error("Falta la sección «{$section}».");
            }
        }

        if (array_key_exists('store', $config) && ! is_array($config['store'])) {
            $report->error('La sección «store» no tiene el formato esperado.');
        }

        if ($report->hasErrors()) {
            return;
        }

        $texts = [
            'topic.key' => true, 'topic.label' => true, 'topic.instructions' => true, 'topic.retrieval_instructions' => false,
            'product.name' => true, 'product.description' => true, 'product.access_mode' => true,
            'product.ai_sales_strategy' => false, 'product.faq_context' => false, 'product.sample_instructions' => false,
            'product.sample_closing_message' => false, 'product.commercial_instructions' => false, 'product.activation_message' => false,
            'store.system_prompt' => false, 'store.welcome_message' => false,
        ];

        foreach ($texts as $path => $required) {
            $value = data_get($config, $path);

            if ($value === null ? $required : ! is_string($value)) {
                $report->error($required ? "Falta «{$path}» o no es un texto." : "«{$path}» debe ser un texto o null.");
            } elseif (is_string($value) && isset(self::LIMITS[$path]) && mb_strlen($value) > self::LIMITS[$path]) {
                $report->error("«{$path}» tiene ".mb_strlen($value).' caracteres (máximo '.self::LIMITS[$path].').');
            }
        }

        foreach (['topic.vocabulary', 'topic.facts', 'product.aliases', 'product.required_facts'] as $path) {
            $value = data_get($config, $path);

            if ($value !== null && (! is_array($value) || ! array_is_list($value))) {
                $report->error("«{$path}» debe ser una lista.");
            }
        }

        foreach (['product.aliases', 'product.required_facts', 'topic.vocabulary'] as $path) {
            if (collect(data_get($config, $path) ?? [])->contains(fn ($item) => ! is_string($item))) {
                $report->error("«{$path}» solo puede contener textos.");
            }
        }

        if (! in_array($config['product']['access_mode'] ?? null, [Product::ACCESS_OPEN, Product::ACCESS_SUBSCRIPTION], true)) {
            $report->error('«product.access_mode» debe ser «subscription» u «open».');
        }

        if ($config['has_sample'] && blank($config['product']['sample_instructions'] ?? null)) {
            $report->error('has_sample es sí pero faltan las instrucciones de la muestra.');
        }

        $sources = $config['knowledge_sources'] ?? null;

        if (! is_array($sources) || $sources === [] || ! array_is_list($sources)) {
            $report->error('Falta la lista «knowledge_sources».');

            return;
        }

        foreach ($sources as $i => $source) {
            $at = 'knowledge_sources['.$i.']';

            if (! is_array($source)) {
                $report->error("«{$at}» no tiene el formato esperado.");

                continue;
            }

            foreach (['file', 'title', 'content'] as $field) {
                if (! is_string($source[$field] ?? null) || trim($source[$field]) === '') {
                    $report->error("Falta «{$at}.{$field}».");
                }
            }

            foreach (['title', 'origin'] as $field) {
                if (isset($source[$field]) && (! is_string($source[$field]) || mb_strlen($source[$field]) > 191)) {
                    $report->error("«{$at}.{$field}» debe ser un texto de máximo 191 caracteres.");
                }
            }

            if (! in_array($source['purpose'] ?? null, KnowledgeSource::PURPOSES, true)) {
                $report->error("«{$at}.purpose» debe ser «knowledge» o «commercial».");
            }

            if (! in_array($source['delivery'] ?? KnowledgeSource::DELIVERY_REFERENCE, KnowledgeSource::DELIVERIES, true)) {
                $report->error("«{$at}.delivery» debe ser «reference» o «verbatim».");
            }

            if (! in_array($source['scope'] ?? 'product', ['product', 'topic'], true)) {
                $report->error("«{$at}.scope» debe ser «product» o «topic».");
            }

            if (is_string($source['file'] ?? null) && KnowledgeFile::typeFor($source['file']) === null) {
                $report->error("«{$at}.file»: extensión no soportada. Usa .md, .markdown, .html, .htm o .txt.");
            }
        }
    }

    /**
     * Writes the configuration and describes each write in the report.
     * Runs inside the caller's transaction; an invalid value throws.
     */
    private function apply(Store $store, array $config, bool $updateStoreTexts, ?int $userId, ImportReport $report): void
    {
        $topic = $this->applyTopic($store, $config['topic'], $userId, $report);
        $product = $this->applyProduct($store, $topic, $config['product'], $config['has_sample'], $report);
        $this->applyStore($store, $config['store'] ?? [], $updateStoreTexts, $report);

        foreach ($config['knowledge_sources'] as $source) {
            $this->applySource($store, $topic, $product, $source, $report);
        }

        $this->checkGuide($store, $topic, $product, $report);
        $this->listPending($store, $product, $config, $report);
    }

    private function applyTopic(Store $store, array $data, ?int $userId, ImportReport $report): Topic
    {
        $topic = Topic::where('store_id', $store->id)->where('key', $data['key'])->first()
            ?? new Topic(['store_id' => $store->id, 'key' => $data['key'], 'is_active' => true]);

        $topic->fill($this->present([
            'label' => $data['label'],
            'vocabulary' => $data['vocabulary'] ?? null,
            'instructions' => $data['instructions'],
            'retrieval_instructions' => $data['retrieval_instructions'] ?? null,
            'facts' => $data['facts'] ?? null,
        ]));

        $this->save($topic, "Tema «{$data['key']}»", $report, fn () => $topic->updated_by = $userId);

        if (! $topic->is_active) {
            $report->warn("El tema «{$topic->key}» existe pero está inactivo: actívalo para que el bot lo use.");
        }

        return $topic;
    }

    private function applyProduct(Store $store, Topic $topic, array $data, bool $hasSample, ImportReport $report): Product
    {
        $product = Product::where('store_id', $store->id)->where('domain_key', $topic->key)->where('name', $data['name'])->first()
            // Technical values of a knowledge product, as the panel sets them: it is sold through its plans.
            ?? new Product(['store_id' => $store->id, 'domain_key' => $topic->key, 'name' => $data['name'], 'price' => 0, 'type' => 'service', 'stock' => 1]);

        if (! $product->exists && Product::where('store_id', $store->id)->where('name', $data['name'])->exists()) {
            $report->warn("Ya hay otro producto llamado «{$data['name']}» en otro tema de la tienda: el bot podría confundirlos.");
        }

        $product->fill($this->present([
            ...array_intersect_key($data, array_flip(self::PRODUCT_TEXTS)),
            'aliases' => $data['aliases'] ?? null,
            'required_facts' => $data['required_facts'] ?? null,
            'access_mode' => $data['access_mode'],
        ]));

        // Not pending but decided: without a sample, its texts are cleared.
        $product->fill($hasSample
            ? $this->present(['sample_instructions' => $data['sample_instructions'] ?? null, 'sample_closing_message' => $data['sample_closing_message'] ?? null])
            : ['sample_instructions' => null, 'sample_closing_message' => null]);

        $this->save($product, "Producto «{$data['name']}»", $report);

        return $product;
    }

    private function applyStore(Store $store, array $data, bool $updateStoreTexts, ImportReport $report): void
    {
        if ($store->conversation_mode !== Store::MODE_KNOWLEDGE) {
            $report->warn('La tienda no está en modo «Conocimiento»: cámbialo en la tienda para que el bot use esta configuración.');
        }

        $texts = $this->present(['system_prompt' => $data['system_prompt'] ?? null, 'welcome_message' => $data['welcome_message'] ?? null]);

        if (! $updateStoreTexts) {
            $differs = collect($texts)->contains(fn ($value, $field) => $value !== $store->{$field});
            $report->row('Tienda: system prompt y bienvenida', ImportReport::SKIPPED, [],
                $differs ? 'No marcaste actualizar los textos de la tienda; el archivo trae otros.' : null);

            return;
        }

        $store = $store->fresh();
        $store->fill($texts);
        $this->save($store, 'Tienda: system prompt y bienvenida', $report);
    }

    private function applySource(Store $store, Topic $topic, Product $product, array $data, ImportReport $report): void
    {
        $purpose = $data['purpose'];
        $delivery = $purpose === KnowledgeSource::PURPOSE_COMMERCIAL ? ($data['delivery'] ?? KnowledgeSource::DELIVERY_REFERENCE) : KnowledgeSource::DELIVERY_REFERENCE;
        $element = 'Fuente «'.$data['title'].'»';

        // A verbatim resource reaches the customer as written (same rule as the panel upload).
        if ($delivery === KnowledgeSource::DELIVERY_VERBATIM && $this->claims->amount(strip_tags($data['content'])) !== null) {
            throw new RuntimeException("{$element}: un recurso literal no puede incluir precios, importes, monedas ni porcentajes.");
        }

        $previous = KnowledgeSource::max('id');

        try {
            $source = $this->ingestor->ingest(
                $store,
                $topic->key,
                ($data['scope'] ?? 'product') === 'product' ? $product : null,
                $data['title'],
                KnowledgeFile::typeFor($data['file']),
                $data['content'],
                filled($data['origin'] ?? null) ? $data['origin'] : null,
                null,
                filled($data['language'] ?? null) ? mb_substr($data['language'], 0, 10) : 'es',
                $purpose,
                $delivery,
            );
        } catch (InvalidArgumentException $e) {
            throw new RuntimeException("{$element}: {$e->getMessage()}", previous: $e);
        }

        $action = match (true) {
            $source->id <= (int) $previous => ImportReport::UNCHANGED,
            $source->version > 1 => ImportReport::NEW_VERSION,
            default => ImportReport::CREATE,
        };

        $report->row($element, $action, [], $action === ImportReport::UNCHANGED ? null : $source->chunks()->count().' fragmentos');
    }

    /** Titles quoted in the guide must exist in the topic's knowledge, letter by letter. */
    private function checkGuide(Store $store, Topic $topic, Product $product, ImportReport $report): void
    {
        preg_match_all('/«([^»<]+)»/u', (string) $topic->retrieval_instructions, $quoted);

        $headings = collect($this->retriever->headings($store, $topic->key, $product))->map(fn ($h) => $this->normalize($h));
        // A quoted fact («nombre», «planta») is a reference to the customer's data, not a title.
        $factNames = collect($topic->facts ?? [])
            ->flatMap(fn ($fact) => [
                $fact['key'] ?? '',
                ($fact['scope'] ?? null) === 'person' && $this->personFacts->has($fact['key'] ?? '') ? $this->personFacts->label($fact['key']) : ($fact['label'] ?? ''),
            ])
            ->map(fn ($n) => $this->normalize((string) $n));

        foreach (array_unique($quoted[1]) as $title) {
            $name = $this->normalize($title);

            if (! $headings->contains($name) && ! $factNames->contains($name)) {
                $report->error("La guía de selección cita «{$title}», que no es un título de las fuentes de conocimiento.");
            }
        }
    }

    private function listPending(Store $store, Product $product, array $config, ImportReport $report): void
    {
        if ($product->requiresSubscription() && ! $product->plans()->exists()) {
            $report->pending('Crear los planes del producto (precio y duración).'
                .(filled($config['plans_suggestion'] ?? null) ? ' Sugerencia: '.$config['plans_suggestion'] : ''));
        }

        if (blank($store->payment_instructions)) {
            $report->pending('Escribir las instrucciones de pago de la tienda.');
        }

        foreach ($config['pending'] ?? [] as $item) {
            $field = (string) ($item['field'] ?? '');

            if (! in_array($field, ['plans', 'store.payment_instructions'], true) && ! str_starts_with($field, 'templates')) {
                $report->pending(trim($field.': '.($item['reason'] ?? ''), ': '));
            }
        }
    }

    /**
     * Saves the model and records what changed.
     *
     * @param  (callable(): void)|null  $beforeSave  attributes that are not content (who edited)
     */
    private function save(Model $model, string $element, ImportReport $report, ?callable $beforeSave = null): void
    {
        $original = $model->exists ? $model->replicate()->setRawAttributes($model->getRawOriginal()) : null;

        if (! $model->exists || $model->isDirty()) {
            if ($beforeSave) {
                $beforeSave();
            }

            $model->save();
        }

        if ($model->wasRecentlyCreated) {
            $report->row($element, ImportReport::CREATE);

            return;
        }

        $changes = collect(array_keys($model->getChanges()))
            ->diff(['updated_at', 'updated_by'])
            ->mapWithKeys(fn (string $field) => [$field => ['old' => $original?->{$field}, 'new' => $model->{$field}]])
            ->all();

        $report->row($element, $changes === [] ? ImportReport::UNCHANGED : ImportReport::UPDATE, $changes);
    }

    /** Values that are set; a null (pending) never erases what is configured. */
    private function present(array $values): array
    {
        return array_filter($values, fn ($value) => $value !== null);
    }

    private function normalize(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', Str::ascii(mb_strtolower($text))));
    }

    private function explain(Throwable $e): string
    {
        return match (true) {
            $e instanceof InvalidArgumentException, $e instanceof RuntimeException => $e->getMessage(),
            default => 'No se pudo completar la importación ('.class_basename($e).'). No se guardó nada.',
        };
    }
}
