<?php

/*
 * Importing a configuracion.json (botinker-config skill, schema 1): a
 * preview that writes nothing, an import that writes all or nothing through
 * the panel's own models and ingestor, and pending values that never erase
 * what is configured.
 */

use App\Filament\Pages\ImportConfiguration;
use App\Models\KnowledgeSource;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Store;
use App\Models\Topic;
use App\Models\User;
use App\Services\ConfigurationImport\ConfigurationImporter;
use App\Services\ConfigurationImport\ImportReport;
use Illuminate\Http\UploadedFile;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;

function ciConfig(array $overrides = []): array
{
    $config = [
        'schema_version' => 1,
        'generated_at' => '2026-10-21',
        'source' => ['title' => 'Guía', 'language' => 'es'],
        'has_sample' => true,
        'store' => ['system_prompt' => 'PROMPT-TIENDA', 'welcome_message' => 'BIENVENIDA', 'payment_instructions' => null],
        'topic' => [
            'key' => 'cocina', 'label' => 'Cocina saludable', 'vocabulary' => ['receta', 'cocina'],
            'instructions' => 'INSTRUCCIONES-TEMA',
            'retrieval_instructions' => 'Elige «Desayunos: avena» o «Cenas: <plato>». Usa «nombre» si lo sabes.',
            'facts' => [['scope' => 'person', 'key' => 'name']],
        ],
        'product' => [
            'name' => 'Recetario', 'description' => 'DESCRIPCION', 'aliases' => [], 'required_facts' => ['name'],
            'access_mode' => 'subscription', 'ai_sales_strategy' => 'SERVICIO', 'faq_context' => 'FAQ',
            'sample_instructions' => 'MUESTRA', 'sample_closing_message' => 'CIERRE',
            'commercial_instructions' => 'COMERCIAL', 'activation_message' => 'ACTIVACION',
        ],
        'plans' => null,
        'plans_suggestion' => 'Mensual — 30 días',
        'knowledge_sources' => [
            ['file' => 'conocimiento.md', 'title' => 'Guía de cocina', 'purpose' => 'knowledge', 'delivery' => 'reference', 'scope' => 'product',
                'origin' => 'conocimiento-recetario', 'language' => 'es', 'content' => "# Desayunos: avena\n\nAVENA-TEXTO\n\n# Cenas: sopa\n\nSOPA-TEXTO"],
            ['file' => 'recursos_comerciales.md', 'title' => 'Recursos comerciales', 'purpose' => 'commercial', 'delivery' => 'reference', 'scope' => 'product',
                'origin' => 'comercial-recetario', 'language' => 'es', 'content' => "# Qué incluye\n\nRecetas para cada comida."],
        ],
        'pending' => [['field' => 'plans', 'reason' => 'Los define el operador.'], ['field' => 'product.name', 'reason' => 'Confirmar el nombre.']],
        'findings' => [],
    ];

    return array_replace_recursive($config, $overrides);
}

function ciJson(array $config): string
{
    return json_encode($config, JSON_UNESCAPED_UNICODE);
}

function ciImporter(): ConfigurationImporter
{
    return app(ConfigurationImporter::class);
}

function ciState(): array
{
    return [Topic::count(), Product::count(), KnowledgeSource::count(), Store::all()->map->only(['system_prompt', 'welcome_message'])->all()];
}

beforeEach(function () {
    $this->store = makeStore(['conversation_mode' => Store::MODE_KNOWLEDGE, 'system_prompt' => 'PROMPT-ACTUAL', 'welcome_message' => null]);
    $this->logged = [];
    Event::listen(MessageLogged::class, fn (MessageLogged $event) => $this->logged[] = $event->message);
});

it('previews a full import without writing anything', function () {
    $before = ciState();

    $report = ciImporter()->preview($this->store, ciJson(ciConfig()), true);

    expect($report->errors)->toBe([])
        ->and($report->applied)->toBeFalse()
        ->and(collect($report->rows)->pluck('action', 'element')->all())->toBe([
            'Tema «cocina»' => ImportReport::CREATE,
            'Producto «Recetario»' => ImportReport::CREATE,
            'Tienda: system prompt y bienvenida' => ImportReport::UPDATE,
            'Fuente «Guía de cocina»' => ImportReport::CREATE,
            'Fuente «Recursos comerciales»' => ImportReport::CREATE,
        ])
        ->and(ciState())->toEqual($before);
});

it('imports the topic, the product, its sources and the store texts', function () {
    $user = User::factory()->create(['store_id' => $this->store->id]);
    $report = ciImporter()->import($this->store, ciJson(ciConfig()), true, userId: $user->id);

    $topic = Topic::sole();
    $product = Product::sole();
    expect($report->applied)->toBeTrue()
        ->and($topic->only(['store_id', 'key', 'label', 'instructions', 'is_active', 'updated_by']))
        ->toEqual(['store_id' => $this->store->id, 'key' => 'cocina', 'label' => 'Cocina saludable', 'instructions' => 'INSTRUCCIONES-TEMA', 'is_active' => true, 'updated_by' => $user->id])
        ->and($product->only(['domain_key', 'access_mode', 'sample_instructions', 'commercial_instructions', 'activation_message', 'type', 'stock']))
        ->toEqual(['domain_key' => 'cocina', 'access_mode' => 'subscription', 'sample_instructions' => 'MUESTRA', 'commercial_instructions' => 'COMERCIAL', 'activation_message' => 'ACTIVACION', 'type' => 'service', 'stock' => 1])
        ->and($product->offersSample())->toBeTrue()
        ->and(KnowledgeSource::where('product_id', $product->id)->pluck('purpose')->sort()->values()->all())->toBe(['commercial', 'knowledge'])
        ->and($this->store->fresh()->only(['system_prompt', 'welcome_message']))->toBe(['system_prompt' => 'PROMPT-TIENDA', 'welcome_message' => 'BIENVENIDA'])
        ->and($this->logged)->toContain('CONFIG_IMPORTED');
});

it('reports no changes when the same file is imported again', function () {
    ciImporter()->import($this->store, ciJson(ciConfig()), true);

    $report = ciImporter()->import($this->store, ciJson(ciConfig()), true);

    expect(collect($report->rows)->pluck('action')->unique()->values()->all())->toBe([ImportReport::UNCHANGED])
        ->and($report->hasChanges())->toBeFalse()
        ->and(KnowledgeSource::count())->toBe(2);
});

it('updates what changed and versions a changed source', function () {
    ciImporter()->import($this->store, ciJson(ciConfig()), true);

    $report = ciImporter()->import($this->store, ciJson(ciConfig([
        'product' => ['faq_context' => 'FAQ-NUEVA'],
        'knowledge_sources' => [['content' => "# Desayunos: avena\n\nAVENA-V2\n\n# Cenas: sopa\n\nSOPA-TEXTO"]],
    ])), true);

    $rows = collect($report->rows)->keyBy('element');
    expect($rows['Producto «Recetario»']['action'])->toBe(ImportReport::UPDATE)
        ->and($rows['Producto «Recetario»']['changes'])->toBe(['faq_context' => ['old' => 'FAQ', 'new' => 'FAQ-NUEVA']])
        ->and($rows['Fuente «Guía de cocina»']['action'])->toBe(ImportReport::NEW_VERSION)
        ->and(Product::count())->toBe(1)
        ->and(KnowledgeSource::where('purpose', 'knowledge')->where('status', KnowledgeSource::STATUS_ACTIVE)->sole()->version)->toBe(2);
});

it('never erases a configured value with a pending one', function () {
    ciImporter()->import($this->store, ciJson(ciConfig()), true);

    ciImporter()->import($this->store, ciJson(ciConfig([
        'store' => ['welcome_message' => null],
        'product' => ['activation_message' => null, 'faq_context' => null],
        'topic' => ['retrieval_instructions' => null],
    ])), true);

    expect($this->store->fresh()->welcome_message)->toBe('BIENVENIDA')
        ->and(Product::sole()->only(['activation_message', 'faq_context']))->toBe(['activation_message' => 'ACTIVACION', 'faq_context' => 'FAQ'])
        ->and(Topic::sole()->retrieval_instructions)->not->toBeNull();
});

it('clears the sample texts when the file says there is no sample', function () {
    ciImporter()->import($this->store, ciJson(ciConfig()), true);

    ciImporter()->import($this->store, ciJson(ciConfig(['has_sample' => false, 'product' => ['sample_instructions' => null, 'sample_closing_message' => null]])), true);

    expect(Product::sole()->offersSample())->toBeFalse()
        ->and(Product::sole()->sample_closing_message)->toBeNull();
});

it('leaves the store texts alone unless asked', function () {
    $report = ciImporter()->import($this->store, ciJson(ciConfig()), false);

    expect($this->store->fresh()->only(['system_prompt', 'welcome_message']))->toBe(['system_prompt' => 'PROMPT-ACTUAL', 'welcome_message' => null])
        ->and(collect($report->rows)->firstWhere('element', 'Tienda: system prompt y bienvenida')['action'])->toBe(ImportReport::SKIPPED);
});

it('writes nothing when any part is invalid', function (array $overrides, string $error) {
    $before = ciState();

    $report = ciImporter()->import($this->store, ciJson(ciConfig($overrides)), true);

    expect($report->applied)->toBeFalse()
        ->and(implode("\n", $report->errors))->toContain($error)
        ->and(ciState())->toEqual($before);
})->with([
    'invalid topic key' => [['topic' => ['key' => 'Cocina Sana']], 'La clave usa minúsculas'],
    'required fact not in the topic' => [['product' => ['required_facts' => ['birth_date']]], 'birth_date'],
    'closing too long' => [['product' => ['sample_closing_message' => str_repeat('a', 1001)]], 'máximo 1000'],
    'guide names a missing title' => [['topic' => ['retrieval_instructions' => 'Elige «Postres: flan».']], '«Postres: flan»'],
    'verbatim resource with an amount' => [['knowledge_sources' => [1 => ['delivery' => 'verbatim', 'content' => "# Oferta\n\nSolo \$20.000 al mes."]]], 'recurso literal'],
    'sample promised without instructions' => [['product' => ['sample_instructions' => null]], 'instrucciones de la muestra'],
]);

it('rejects files that are not a configuration', function (string $json, string $error) {
    $report = ciImporter()->preview($this->store, $json, true);

    expect(implode("\n", $report->errors))->toContain($error)->and(Topic::count())->toBe(0);
})->with([
    'not JSON' => ['{oops', 'no es un JSON válido'],
    'a list' => ['[1, 2]', 'formato de configuracion.json'],
    'another schema' => [json_encode(['schema_version' => 2]), 'schema_version 1'],
    'no sources' => [json_encode(array_merge(ciConfig(), ['knowledge_sources' => []])), 'knowledge_sources'],
    'too big' => [str_repeat(' ', ConfigurationImporter::MAX_BYTES + 1), '2 MB'],
]);

it('rolls back the sources already loaded when a later source fails', function () {
    $before = ciState();

    $report = ciImporter()->import($this->store, ciJson(ciConfig(['knowledge_sources' => [1 => ['content' => '<p></p>', 'file' => 'vacio.html']]])), true);

    expect($report->applied)->toBeFalse()
        ->and($report->errors)->not->toBe([])
        ->and(ciState())->toEqual($before);
});

it('keeps each store on its own records', function () {
    $other = makeStore(['conversation_mode' => Store::MODE_KNOWLEDGE]);
    ciImporter()->import($other, ciJson(ciConfig()), true);

    ciImporter()->import($this->store, ciJson(ciConfig(['product' => ['faq_context' => 'FAQ-MIA']])), true);

    expect(Topic::where('store_id', $this->store->id)->count())->toBe(1)
        ->and(Product::where('store_id', $other->id)->sole()->faq_context)->toBe('FAQ')
        ->and(Product::where('store_id', $this->store->id)->sole()->faq_context)->toBe('FAQ-MIA');
});

it('lists what the operator still has to complete and ignores Meta templates', function () {
    $report = ciImporter()->preview($this->store, ciJson(ciConfig(['templates' => [['event_key' => 'payment_alert']]])), true);

    expect($report->pending)->toContain('Crear los planes del producto (precio y duración). Sugerencia: Mensual — 30 días')
        ->toContain('Escribir las instrucciones de pago de la tienda.')
        ->toContain('product.name: Confirmar el nombre.')
        ->and(implode("\n", $report->warnings))->toContain('plantillas de Meta');

    Plan::create(['product_id' => ciImporter()->import($this->store, ciJson(ciConfig()), true)->applied ? Product::sole()->id : 0, 'name' => 'Mensual', 'price' => 20000]);

    expect(ciImporter()->preview($this->store, ciJson(ciConfig()), true)->pending)->not->toContain('Crear los planes del producto (precio y duración). Sugerencia: Mensual — 30 días');
});

it('warns when the store is not in knowledge mode', function () {
    $this->store->update(['conversation_mode' => Store::MODE_SALES]);

    $report = ciImporter()->preview($this->store, ciJson(ciConfig()), true);

    expect(implode("\n", $report->warnings))->toContain('modo «Conocimiento»');
});

describe('the panel page', function () {
    beforeEach(function () {
        $this->operator = User::factory()->create(['store_id' => $this->store->id, 'is_super_admin' => false]);
    });

    function ciUpload(array $config = []): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('configuracion.json', ciJson(ciConfig($config)));
    }

    it('opens for a store user', function () {
        $this->actingAs($this->operator)->get('/import-configuration')->assertOk()->assertSee('Importar configuración');
    });

    it('reviews, then imports into the user own store only', function () {
        $foreign = makeStore(['conversation_mode' => Store::MODE_KNOWLEDGE]);
        $this->actingAs($this->operator);

        Livewire\Livewire::test(ImportConfiguration::class)
            ->set('storeId', $foreign->id) // ignored: not a superuser
            ->set('file', ciUpload())
            ->call('review')
            ->assertSee('Qué se hará')
            ->assertSee('Crear')
            ->call('import')
            ->assertSee('Resultado');

        expect(Topic::sole()->store_id)->toBe($this->store->id)
            ->and(Topic::where('store_id', $foreign->id)->count())->toBe(0);
    });

    it('does not import a file that was not reviewed', function () {
        $this->actingAs($this->operator);

        Livewire\Livewire::test(ImportConfiguration::class)
            ->set('file', ciUpload())
            ->call('review')
            ->set('updateStoreTexts', true) // a changed option needs a new review
            ->call('import');

        expect(Topic::count())->toBe(0);
    });

    it('shows the errors and imports nothing', function () {
        $this->actingAs($this->operator);

        Livewire\Livewire::test(ImportConfiguration::class)
            ->set('file', ciUpload(['topic' => ['retrieval_instructions' => 'Elige «Postres: flan».']]))
            ->call('review')
            ->assertSee('Errores: no se puede importar')
            ->assertDontSee('wire:click="import"', false);

        expect(Topic::count())->toBe(0);
    });
});
