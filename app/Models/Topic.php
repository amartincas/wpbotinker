<?php

namespace App\Models;

use App\Platform\Memory\FactTypes;
use App\Platform\Memory\PersonFacts;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/**
 * A store's own topic, configured in the panel and served to the Core as a
 * domain (App\Platform\Domains\ConfiguredDomain). Instructions and facts
 * are data: validated here on every save, never executed.
 *
 * Facts are a list of entries:
 * - {scope: person, key}: a fact of the Core person catalog;
 * - {scope: domain, key, type, question, label?, sensitive?}: a fact of the
 *   topic itself, validated by its basic type.
 */
#[Fillable(['store_id', 'key', 'label', 'vocabulary', 'instructions', 'retrieval_instructions', 'facts', 'is_active', 'updated_by'])]
class Topic extends Model
{
    public const KEY_PATTERN = '/^[a-z][a-z0-9_]*$/';

    /** Keys the Core keeps for itself (e.g. future generic capabilities). */
    public const RESERVED_KEYS = ['core'];

    public const MAX_KEY_LENGTH = 50;

    public const MAX_LABEL_LENGTH = 100;

    public const MAX_INSTRUCTIONS_LENGTH = 8000;

    public const MAX_RETRIEVAL_INSTRUCTIONS_LENGTH = 2000;

    public const MAX_VOCABULARY_TERMS = 30;

    public const MAX_TERM_LENGTH = 50;

    public const MAX_FACTS = 15;

    public const MAX_QUESTION_LENGTH = 300;

    protected $attributes = [
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'vocabulary' => 'array',
            'facts' => 'array',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $topic) {
            $topic->vocabulary = self::normalizeVocabulary($topic->vocabulary ?? []);
            $topic->facts = self::normalizeFacts($topic->facts ?? []);

            if ($errors = $topic->validationErrors()) {
                throw new InvalidArgumentException(reset($errors));
            }
        });

        // Products, knowledge and customer facts point to the topic by its
        // key: deactivate it instead.
        static::deleting(function (self $topic) {
            if ($topic->products()->exists()) {
                throw new InvalidArgumentException("Topic [{$topic->key}] has products; deactivate it instead.");
            }
        });
    }

    /**
     * Every problem of the current attributes, by field. Empty when the
     * topic can be saved.
     *
     * @return array<string, string>
     */
    public function validationErrors(): array
    {
        $errors = [];
        $key = (string) $this->key;

        if ($this->exists && $this->isDirty('key')) {
            $errors['key'] = 'La clave de un tema no se puede cambiar.';
        } elseif (! preg_match(self::KEY_PATTERN, $key) || mb_strlen($key) > self::MAX_KEY_LENGTH) {
            $errors['key'] = 'La clave usa minúsculas, números y guion bajo, empieza por una letra y tiene máximo '.self::MAX_KEY_LENGTH.' caracteres.';
        } elseif (in_array($key, self::RESERVED_KEYS, true) || array_key_exists($key, config('botinker.domains', []))) {
            $errors['key'] = "La clave [{$key}] está reservada por el sistema.";
        } elseif (self::where('store_id', $this->store_id)->where('key', $key)->whereKeyNot($this->id)->exists()) {
            $errors['key'] = "Ya existe un tema con la clave [{$key}] en esta tienda.";
        }

        if (Store::whereKey($this->store_id)->doesntExist()) {
            $errors['store_id'] = 'El tema necesita una tienda.';
        } elseif ($this->exists && $this->isDirty('store_id')) {
            $errors['store_id'] = 'Un tema no se puede mover a otra tienda.';
        }

        $label = trim((string) $this->label);

        if ($label === '' || mb_strlen($label) > self::MAX_LABEL_LENGTH) {
            $errors['label'] = 'El nombre es obligatorio y tiene máximo '.self::MAX_LABEL_LENGTH.' caracteres.';
        }

        $instructions = trim((string) $this->instructions);

        if ($instructions === '' || mb_strlen($instructions) > self::MAX_INSTRUCTIONS_LENGTH) {
            $errors['instructions'] = 'Las instrucciones son obligatorias y tienen máximo '.self::MAX_INSTRUCTIONS_LENGTH.' caracteres.';
        }

        if (mb_strlen((string) $this->retrieval_instructions) > self::MAX_RETRIEVAL_INSTRUCTIONS_LENGTH) {
            $errors['retrieval_instructions'] = 'La guía de selección tiene máximo '.self::MAX_RETRIEVAL_INSTRUCTIONS_LENGTH.' caracteres.';
        }

        $vocabulary = $this->vocabulary ?? [];

        if (count($vocabulary) > self::MAX_VOCABULARY_TERMS) {
            $errors['vocabulary'] = 'Máximo '.self::MAX_VOCABULARY_TERMS.' términos de vocabulario.';
        } elseif (collect($vocabulary)->contains(fn ($term) => mb_strlen($term) < 2 || mb_strlen($term) > self::MAX_TERM_LENGTH)) {
            $errors['vocabulary'] = 'Cada término tiene entre 2 y '.self::MAX_TERM_LENGTH.' caracteres.';
        }

        if ($factError = $this->factsError()) {
            $errors['facts'] = $factError;
        }

        return $errors;
    }

    private function factsError(): ?string
    {
        $facts = $this->facts ?? [];
        $personFacts = app(PersonFacts::class);

        if (count($facts) > self::MAX_FACTS) {
            return 'Máximo '.self::MAX_FACTS.' datos por tema.';
        }

        $seen = [];

        foreach ($facts as $fact) {
            $key = (string) ($fact['key'] ?? '');

            if (in_array($key, $seen, true)) {
                return "El dato [{$key}] está repetido.";
            }

            $seen[] = $key;

            if (($fact['scope'] ?? null) === 'person') {
                if (! $personFacts->has($key)) {
                    return "[{$key}] no es un dato personal del catálogo.";
                }

                continue;
            }

            if (($fact['scope'] ?? null) !== 'domain') {
                return 'Cada dato es personal (del catálogo) o propio del tema.';
            }

            if (! preg_match(self::KEY_PATTERN, $key) || mb_strlen($key) > self::MAX_KEY_LENGTH) {
                return 'La clave de cada dato usa minúsculas, números y guion bajo, y empieza por una letra.';
            }

            if ($personFacts->has($key)) {
                return "[{$key}] es un dato personal del catálogo: añádelo como dato personal.";
            }

            if (! in_array($fact['type'] ?? null, FactTypes::TYPES, true)) {
                return "El dato [{$key}] necesita un tipo: ".implode(', ', FactTypes::TYPES).'.';
            }

            $question = trim((string) ($fact['question'] ?? ''));

            if ($question === '' || mb_strlen($question) > self::MAX_QUESTION_LENGTH) {
                return "El dato [{$key}] necesita una pregunta de máximo ".self::MAX_QUESTION_LENGTH.' caracteres.';
            }

            if (mb_strlen((string) ($fact['label'] ?? '')) > self::MAX_LABEL_LENGTH) {
                return "La etiqueta del dato [{$key}] tiene máximo ".self::MAX_LABEL_LENGTH.' caracteres.';
            }
        }

        // A product of this topic may only require facts the topic declares.
        $products = Product::where('store_id', $this->store_id)->where('domain_key', $this->key)->get(['name', 'required_facts']);

        foreach ($products as $product) {
            foreach ($product->required_facts ?? [] as $required) {
                if (! in_array($required, $seen, true)) {
                    return "El producto «{$product->name}» necesita el dato [{$required}]; no lo quites del tema.";
                }
            }
        }

        return null;
    }

    /**
     * @return list<string> lowercase, trimmed, without blanks or duplicates
     */
    private static function normalizeVocabulary(array $terms): array
    {
        return collect($terms)
            ->filter(fn ($term) => is_string($term))
            ->map(fn (string $term) => mb_strtolower(trim(preg_replace('/\s+/u', ' ', $term))))
            ->filter(fn (string $term) => $term !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Keeps only the known fields of each entry, with their expected types,
     * so nothing else can be stored inside a fact definition.
     *
     * @return list<array<string, mixed>>
     */
    private static function normalizeFacts(array $facts): array
    {
        return collect($facts)
            ->filter(fn ($fact) => is_array($fact))
            ->map(function (array $fact) {
                $key = trim((string) ($fact['key'] ?? ''));

                if (($fact['scope'] ?? null) === 'person') {
                    return ['scope' => 'person', 'key' => $key];
                }

                return array_filter([
                    'scope' => is_string($fact['scope'] ?? null) ? $fact['scope'] : null,
                    'key' => $key,
                    'type' => is_string($fact['type'] ?? null) ? $fact['type'] : null,
                    'question' => trim((string) ($fact['question'] ?? '')),
                    'label' => trim((string) ($fact['label'] ?? '')),
                    'sensitive' => (bool) ($fact['sensitive'] ?? false),
                ], fn ($value) => $value !== null && $value !== '');
            })
            ->values()
            ->all();
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** Products of the same store that belong to this topic. */
    public function products(): Builder
    {
        return Product::where('store_id', $this->store_id)->where('domain_key', $this->key);
    }
}
