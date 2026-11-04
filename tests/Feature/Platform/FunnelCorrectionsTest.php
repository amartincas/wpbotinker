<?php

/*
 * What an administrator actually reads when a funnel or a source does not
 * validate, and the same protection of subscribers' content whether a sample
 * is uploaded or imported.
 */

use App\Filament\Resources\KnowledgeSources\Pages\CreateKnowledgeSource;
use App\Filament\Resources\Products\Pages\EditProductFunnel;
use App\Models\KnowledgeSource;
use App\Models\Product;
use App\Models\Store;
use App\Models\Topic;
use App\Models\User;
use App\Platform\Funnel\SalesFunnelVersions;
use App\Platform\Knowledge\KnowledgeIngestor;
use App\Services\ConfigurationImport\ConfigurationImporter;
use App\Services\SampleContentGuard;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

const FC_ENGLISH = '/\b(the|field|selected|invalid|required|must|may not|greater than|at least|is)\b/i';

function fcProduct(Store $store, array $overrides = []): Product
{
    return Product::create(array_merge([
        'store_id' => $store->id, 'domain_key' => 'cocina', 'name' => 'Recetario', 'description' => 'x', 'price' => 1, 'stock' => 1,
        'access_mode' => Product::ACCESS_SUBSCRIPTION,
    ], $overrides));
}

function fcDefinition(): array
{
    return [
        'name' => 'Embudo', 'goal' => 'Vender', 'start_stage' => 'venta',
        'free_replies' => ['limit' => 1, 'on_exhausted' => 'venta'],
        'stages' => [
            ['key' => 'aviso', 'type' => 'message', 'text' => 'Hola', 'next' => 'venta'],
            ['key' => 'dudas', 'type' => 'ai', 'goal' => 'g', 'instructions' => 'i', 'max_interactions' => 2, 'on_limit' => 'venta'],
            ['key' => 'venta', 'type' => 'ai', 'goal' => 'g', 'instructions' => 'i'],
        ],
    ];
}

/** Every message the page holds, by the end of its path. */
function fcErrors(Testable $page): array
{
    return collect($page->errors()->toArray())
        ->mapWithKeys(fn (array $messages, string $key) => [preg_replace('/stages\.[^.]+\./', 'stages.*.', str_replace('data.', '', $key)) => $messages[0]])
        ->all();
}

function fcEditor(Product $product): Testable
{
    return Livewire::test(EditProductFunnel::class, ['record' => $product->getRouteKey()]);
}

function fcUpload(array $values, ?string $content = "# Propia\n\nMATERIAL"): Testable
{
    return Livewire::test(CreateKnowledgeSource::class)
        ->fillForm(['domain_key' => 'cocina', 'purpose' => KnowledgeSource::PURPOSE_SAMPLE])
        ->fillForm([...$values, 'file' => UploadedFile::fake()->createWithContent('muestra.md', (string) $content)])
        ->call('create');
}

/** A complete configuracion.json with the given sources (a file of the importer's own schema). */
function fcConfig(array $sources): string
{
    return json_encode([
        'schema_version' => 1,
        'generated_at' => '2026-11-04',
        'source' => ['title' => 'Guía', 'language' => 'es'],
        'has_sample' => true,
        'store' => ['system_prompt' => 'P', 'welcome_message' => 'B', 'payment_instructions' => null],
        'topic' => ['key' => 'cocina', 'label' => 'Cocina', 'vocabulary' => ['receta'], 'instructions' => 'I', 'retrieval_instructions' => 'Guía', 'facts' => []],
        'product' => [
            'name' => 'Recetario', 'description' => 'D', 'aliases' => [], 'required_facts' => [], 'access_mode' => 'subscription',
            'ai_sales_strategy' => 'S', 'faq_context' => 'F', 'sample_instructions' => 'M', 'sample_closing_message' => 'C',
            'commercial_instructions' => 'C', 'activation_message' => 'A', 'opening_message' => null, 'opening_triggers' => [],
        ],
        'plans_suggestion' => 'Mensual',
        'knowledge_sources' => $sources,
        'pending' => [],
        'findings' => [],
    ], JSON_UNESCAPED_UNICODE);
}

function fcSource(string $purpose, string $title, string $content, array $overrides = []): array
{
    return array_merge(['file' => "{$title}.md", 'title' => $title, 'purpose' => $purpose, 'delivery' => 'reference', 'scope' => 'product', 'origin' => $title, 'language' => 'es', 'content' => $content], $overrides);
}

beforeEach(function () {
    Storage::fake('local');

    $this->store = makeStore(['conversation_mode' => Store::MODE_KNOWLEDGE]);
    $this->other = makeStore(['conversation_mode' => Store::MODE_KNOWLEDGE]);
    Topic::create(['store_id' => $this->store->id, 'key' => 'cocina', 'label' => 'Cocina', 'instructions' => 'x']);
    Topic::create(['store_id' => $this->other->id, 'key' => 'cocina', 'label' => 'Cocina', 'instructions' => 'x']);
    $this->product = fcProduct($this->store);
    $this->foreignProduct = fcProduct($this->other, ['name' => 'Ajeno']);
    $this->operator = User::factory()->create(['store_id' => $this->store->id, 'is_super_admin' => false]);
    $this->actingAs($this->operator);
    $this->versions = app(SalesFunnelVersions::class);
    $this->importer = app(ConfigurationImporter::class);
});

describe('what the funnel editor says', function () {
    it('explains in Spanish every reference left by a removed stage', function () {
        $this->versions->publish($this->product, fcDefinition());
        $page = fcEditor($this->product);
        $stages = $page->instance()->data['stages'];
        unset($stages[array_keys($stages)[2]]);

        $page->set('data.stages', $stages)->callAction('checkDefinition');

        expect(fcErrors($page))->toBe([
            'start_stage' => 'La etapa inicial elegida ya no existe. Elige otra o déjala vacía.',
            'free_replies.on_exhausted' => 'La etapa elegida para cuando se agoten las respuestas gratuitas ya no existe. Elige otra o déjala vacía.',
            'stages.*.next' => 'La etapa siguiente elegida ya no existe. Elige otra.',
            'stages.*.on_limit' => 'La etapa elegida para cuando se alcance el máximo ya no existe. Elige otra.',
        ]);
    });

    it('explains an unknown stage type', function () {
        $this->versions->publish($this->product, fcDefinition());
        $page = fcEditor($this->product);
        $stage = array_keys($page->instance()->data['stages'])[2];

        $page->set("data.stages.{$stage}.type", 'otro')->callAction('checkDefinition');

        expect(fcErrors($page))->toBe(['stages.*.type' => 'Elige un tipo de etapa válido: IA o mensaje.']);
    });

    it('asks for a stage type', function () {
        $this->versions->publish($this->product, fcDefinition());
        $page = fcEditor($this->product);
        $stage = array_keys($page->instance()->data['stages'])[2];

        $page->set("data.stages.{$stage}.type", null)->callAction('checkDefinition');

        expect(fcErrors($page))->toBe(['stages.*.type' => 'Elige el tipo de la etapa.']);
    });

    it('says everything in Spanish, whatever is wrong', function (array $change) {
        $this->versions->publish($this->product, fcDefinition());
        $page = fcEditor($this->product);
        $keys = array_keys($page->instance()->data['stages']);

        foreach ($change as $path => $value) {
            $page->set('data.'.str_replace(['{message}', '{ai}'], [$keys[0], $keys[1]], $path), $value);
        }

        $page->callAction('checkDefinition');
        $errors = fcErrors($page);

        expect($errors)->not->toBe([]);

        foreach ($errors as $path => $message) {
            expect(preg_match(FC_ENGLISH, $message))->toBe(0, "{$path}: {$message}");
        }
    })->with([
        'no name' => [['name' => '']],
        'no goal' => [['goal' => '']],
        'a long call to action' => [['offer_cta' => str_repeat('a', 4001)]],
        'a limit in words' => [['free_replies.limit' => 'tres']],
        'a limit too high' => [['free_replies.limit' => 256]],
        'a negative limit' => [['free_replies.limit' => -1]],
        'a fractional limit' => [['free_replies.limit' => 1.5]],
        'no stages' => [['stages' => []]],
        'a message without text' => [['stages.{message}.text' => '']],
        'a long message' => [['stages.{message}.text' => str_repeat('a', 4001)]],
        'a message without next' => [['stages.{message}.next' => null]],
        'a stage without goal' => [['stages.{ai}.goal' => '']],
        'a stage without instructions' => [['stages.{ai}.instructions' => '']],
        'a context outside the list' => [['stages.{ai}.context' => ['otro']]],
        'interactions in words' => [['stages.{ai}.max_interactions' => 'dos']],
        'zero interactions' => [['stages.{ai}.max_interactions' => 0]],
        'fractional interactions' => [['stages.{ai}.max_interactions' => 1.5]],
        'a limit without a stage' => [['stages.{ai}.on_limit' => null]],
        'a stage spending replies that do not exist' => [['free_replies.limit' => 0, 'stages.{ai}.uses_free_replies' => true]],
        'a repeated stage key' => [['stages.{ai}.key' => 'aviso']],
        'a rule without condition' => [['stages.{ai}.rules' => ['r' => ['action' => 'stay']]]],
        'a rule with an unknown action' => [['stages.{ai}.rules' => ['r' => ['condition' => 'any_reply', 'action' => 'otra']]]],
        'an intent with a bad key' => [['intents' => ['i' => ['key' => 'Con Espacios', 'description' => 'x']]]],
        'an intent without description' => [['intents' => ['i' => ['key' => 'comprar', 'description' => '']]]],
    ]);
});

describe('what the source upload says', function () {
    it('asks for the topic and the file in Spanish', function () {
        $page = Livewire::test(CreateKnowledgeSource::class)->fillForm(['purpose' => KnowledgeSource::PURPOSE_SAMPLE])->call('create');

        expect(fcErrors($page))->toBe([
            'domain_key' => 'Elige el dominio o tema.',
            'file' => 'Sube el archivo de la fuente.',
        ]);
    });

    it('explains a product of another store', function () {
        expect(fcErrors(fcUpload(['product_id' => $this->foreignProduct->id])))->toBe(['product_id' => 'Ese producto no pertenece a esta tienda y tema. Elige otro.']);
    });

    it('explains a file without text', function () {
        expect(fcErrors(fcUpload([], "   \n ")))->toBe(['file' => 'muestra.md: el archivo no tiene texto que se pueda usar.']);
    });

    it('says everything in Spanish, whatever is wrong', function (array $values, string $expected) {
        $errors = fcErrors(fcUpload($values));

        expect(array_values($errors))->toBe([$expected]);
    })->with([
        'an unknown topic' => [['domain_key' => 'otro'], 'Ese dominio o tema no está activo en esta tienda. Elige otro.'],
        'an unknown use' => [['purpose' => 'otro'], 'Elige un uso de la lista.'],
        'an unknown delivery' => [['delivery' => 'otra'], 'Elige una forma de entrega de la lista.'],
        'no delivery' => [['delivery' => null], 'Elige la forma de entrega.'],
        'a long title' => [['title' => str_repeat('a', 192)], 'El título no puede superar 191 caracteres.'],
        'a long origin' => [['origin' => str_repeat('a', 192)], 'El origen no puede superar 191 caracteres.'],
        'a long license' => [['license' => str_repeat('a', 192)], 'La licencia no puede superar 191 caracteres.'],
        'no language' => [['language' => ''], 'Indica el idioma.'],
        'a long language' => [['language' => str_repeat('a', 11)], 'El idioma no puede superar 10 caracteres.'],
    ]);

    it('asks a super admin for the store in Spanish', function () {
        $this->actingAs(User::factory()->create(['is_super_admin' => true]));

        $page = Livewire::test(CreateKnowledgeSource::class)->call('create');

        expect(fcErrors($page)['store_id'])->toBe('Elige la tienda.');
    });
});

describe('importing a sample', function () {
    beforeEach(function () {
        $this->knowledge = fcSource('knowledge', 'Lecciones', "# Lección\n\nCONTENIDO-EXCLUSIVO");
    });

    it('accepts a valid one', function () {
        $report = $this->importer->import($this->store, fcConfig([$this->knowledge, fcSource('sample', 'Muestra', "# Abierta\n\nMATERIAL-ABIERTO")]), true);

        expect($report->errors)->toBe([])
            ->and($report->applied)->toBeTrue()
            ->and(KnowledgeSource::where('purpose', 'sample')->sole())
            ->title->toBe('Muestra')
            ->status->toBe(KnowledgeSource::STATUS_ACTIVE);
    });

    it('refuses one copying exclusive content, writing nothing', function () {
        $report = $this->importer->import($this->store, fcConfig([$this->knowledge, fcSource('sample', 'Muestra', "# Lección\n\nCONTENIDO-EXCLUSIVO")]), true);

        expect($report->applied)->toBeFalse()
            ->and(implode(' ', $report->errors))->toContain('Fuente «Muestra»: repite contenido exclusivo de suscriptores (fuente «Lecciones»)')
            ->and(KnowledgeSource::where('purpose', 'sample')->count())->toBe(0)
            ->and(KnowledgeSource::where('purpose', 'knowledge')->count())->toBe(0);
    });

    it('refuses it whatever the order of the file', function () {
        $report = $this->importer->import($this->store, fcConfig([fcSource('sample', 'Muestra', "# Lección\n\nCONTENIDO-EXCLUSIVO"), $this->knowledge]), true);

        expect($report->applied)->toBeFalse()
            ->and(implode(' ', $report->errors))->toContain('repite contenido exclusivo')
            ->and(KnowledgeSource::count())->toBe(0);
    });

    it('refuses one copying exclusive content already stored', function () {
        $this->importer->import($this->store, fcConfig([$this->knowledge]), true);
        $total = KnowledgeSource::count();

        $report = $this->importer->import($this->store, fcConfig([fcSource('sample', 'Muestra', "# Lección\n\nCONTENIDO-EXCLUSIVO")]), true);

        expect($report->applied)->toBeFalse()
            ->and(KnowledgeSource::count())->toBe($total);
    });

    it('warns in the preview too', function () {
        $report = $this->importer->preview($this->store, fcConfig([$this->knowledge, fcSource('sample', 'Muestra', "# Lección\n\nCONTENIDO-EXCLUSIVO")]), true);

        expect(implode(' ', $report->errors))->toContain('repite contenido exclusivo')
            ->and(KnowledgeSource::count())->toBe(0);
    });

    it('accepts a valid one after refusing another', function () {
        $this->importer->import($this->store, fcConfig([$this->knowledge, fcSource('sample', 'Muestra', "# Lección\n\nCONTENIDO-EXCLUSIVO")]), true);

        $report = $this->importer->import($this->store, fcConfig([$this->knowledge, fcSource('sample', 'Muestra', "# Abierta\n\nMATERIAL-ABIERTO")]), true);

        expect($report->applied)->toBeTrue()
            ->and(KnowledgeSource::where('purpose', 'sample')->sole())
            ->version->toBe(1)
            ->status->toBe(KnowledgeSource::STATUS_ACTIVE);
    });

    it('keeps the previous version active when its replacement is refused', function () {
        $this->importer->import($this->store, fcConfig([$this->knowledge, fcSource('sample', 'Muestra', "# Abierta\n\nMATERIAL-ABIERTO")]), true);

        $report = $this->importer->import($this->store, fcConfig([$this->knowledge, fcSource('sample', 'Muestra', "# Lección\n\nCONTENIDO-EXCLUSIVO")]), true);

        expect($report->applied)->toBeFalse()
            ->and(KnowledgeSource::where('purpose', 'sample')->sole())
            ->version->toBe(1)
            ->status->toBe(KnowledgeSource::STATUS_ACTIVE);
    });

    it('does not refuse it when the lesson is replaced in the same file', function () {
        $this->importer->import($this->store, fcConfig([$this->knowledge]), true);

        $report = $this->importer->import($this->store, fcConfig([
            fcSource('knowledge', 'Lecciones', "# Lección\n\nOTRO-CONTENIDO"),
            fcSource('sample', 'Muestra', "# Lección\n\nCONTENIDO-EXCLUSIVO"),
        ]), true);

        expect($report->applied)->toBeTrue();
    });

    it('is accepted with the purposes the message names', function () {
        $report = $this->importer->import($this->store, fcConfig([fcSource('otro', 'Muestra', "# Abierta\n\nX")]), true);

        expect(implode(' ', $report->errors))->toContain('debe ser «knowledge», «commercial» o «sample»');
    });
});

describe('the same control everywhere', function () {
    beforeEach(function () {
        $this->exclusive = app(KnowledgeIngestor::class)->ingest($this->store, 'cocina', null, 'Lecciones', 'markdown', "# Lección\n\nCONTENIDO-EXCLUSIVO", origin: 'lecciones');
    });

    it('refuses the same content with the same reason, uploaded or imported', function () {
        $uploaded = fcUpload([], "# Lección\n\nCONTENIDO-EXCLUSIVO");
        $imported = $this->importer->import($this->store, fcConfig([fcSource('sample', 'Muestra', "# Lección\n\nCONTENIDO-EXCLUSIVO")]), true);

        $reason = 'repite contenido exclusivo de suscriptores (fuente «Lecciones»). Una muestra debe ser material propio para clientes sin acceso.';

        expect(fcErrors($uploaded)['file'])->toBe("muestra.md: {$reason}")
            ->and($imported->errors[0])->toContain("Fuente «Muestra»: {$reason}")
            ->and(KnowledgeSource::where('purpose', 'sample')->count())->toBe(0);
    });

    it('accepts the same content, uploaded or imported', function () {
        fcUpload(['origin' => 'subida'], "# Abierta\n\nMATERIAL-ABIERTO")->assertHasNoFormErrors();

        $imported = $this->importer->import($this->store, fcConfig([fcSource('sample', 'Otra', "# Otra\n\nOTRO-MATERIAL")]), true);

        expect($imported->applied)->toBeTrue()
            ->and(KnowledgeSource::where('purpose', 'sample')->count())->toBe(2);
    });

    it('keeps the previous version when the upload is refused', function () {
        fcUpload(['origin' => 'muestra-1'], "# Abierta\n\nMATERIAL-ABIERTO")->assertHasNoFormErrors();
        fcUpload(['origin' => 'muestra-1'], "# Lección\n\nCONTENIDO-EXCLUSIVO")->assertHasFormErrors(['file']);

        expect(KnowledgeSource::where('origin', 'muestra-1')->sole())
            ->version->toBe(1)
            ->status->toBe(KnowledgeSource::STATUS_ACTIVE);
    });

    it('lives in a single place, and only compares whole sections', function () {
        $guard = app(SampleContentGuard::class);
        $reworded = app(KnowledgeIngestor::class)->ingest($this->store, 'cocina', null, 'Reescrita', 'markdown', "# Lección\n\nCONTENIDO EXCLUSIVO REESCRITO", origin: 'reescrita', purpose: KnowledgeSource::PURPOSE_SAMPLE);

        expect($guard->refusalFor($reworded))->toBeNull()
            ->and(file_get_contents(app_path('Filament/Resources/KnowledgeSources/Pages/CreateKnowledgeSource.php')))->not->toContain('KnowledgeChunk')
            ->and(file_get_contents(app_path('Services/ConfigurationImport/ConfigurationImporter.php')))->not->toContain('KnowledgeChunk');
    });
});

describe('the other purposes', function () {
    it('are not checked on import', function () {
        $report = $this->importer->import($this->store, fcConfig([
            fcSource('knowledge', 'Lecciones', "# Lección\n\nCONTENIDO-EXCLUSIVO"),
            fcSource('commercial', 'Comercial', "# Lección\n\nCONTENIDO-EXCLUSIVO"),
        ]), true);

        expect($report->errors)->toBe([])
            ->and($report->applied)->toBeTrue()
            ->and(KnowledgeSource::where('purpose', 'commercial')->count())->toBe(1);
    });

    it('are not checked on upload', function () {
        app(KnowledgeIngestor::class)->ingest($this->store, 'cocina', null, 'Lecciones', 'markdown', "# Lección\n\nCONTENIDO-EXCLUSIVO", origin: 'lecciones');

        fcUpload(['purpose' => KnowledgeSource::PURPOSE_COMMERCIAL], "# Lección\n\nCONTENIDO-EXCLUSIVO")->assertHasNoFormErrors();
        fcUpload(['purpose' => KnowledgeSource::PURPOSE_KNOWLEDGE, 'origin' => 'otra'], "# Otra\n\nOTRO-TEXTO")->assertHasNoFormErrors();
    });

    it('keep their delivery on import', function () {
        $this->importer->import($this->store, fcConfig([fcSource('commercial', 'Comercial', "# Testimonio\n\nTEXTO", ['delivery' => 'verbatim'])]), true);

        expect(KnowledgeSource::where('purpose', 'commercial')->sole()->delivery)->toBe(KnowledgeSource::DELIVERY_VERBATIM);
    });
});
