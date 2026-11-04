<?php

/*
 * Sample sources in the panel. A source is a record of the store, not part of
 * a funnel's definition: a stage only says whether it reads sample content,
 * and then reads the active sources of its product and topic. What only
 * subscribers read never becomes a sample.
 */

use App\Filament\Resources\KnowledgeSources\Pages\CreateKnowledgeSource;
use App\Filament\Resources\KnowledgeSources\Pages\ListKnowledgeSources;
use App\Filament\Resources\Products\Pages\EditProductFunnel;
use App\Filament\Resources\Products\Schemas\FunnelDefinitionFormMapper;
use App\Filament\Resources\Products\Schemas\FunnelPublicationChecks;
use App\Models\KnowledgeSource;
use App\Models\Product;
use App\Models\SalesFunnel;
use App\Models\Store;
use App\Models\User;
use App\Platform\Commerce\AccessDecision;
use App\Platform\Funnel\FunnelDefinition;
use App\Platform\Funnel\SalesFunnelVersions;
use App\Platform\Knowledge\KnowledgeAccess;
use App\Platform\Knowledge\KnowledgeIngestor;
use App\Platform\Knowledge\KnowledgeRetriever;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Domains\OracleTestDomain;

function fsamProduct(Store $store, array $overrides = []): Product
{
    return Product::create(array_merge([
        'store_id' => $store->id, 'domain_key' => 'oracle', 'name' => 'Asesor', 'description' => 'x', 'price' => 1, 'stock' => 1,
        'access_mode' => Product::ACCESS_SUBSCRIPTION,
    ], $overrides));
}

function fsamDefinition(array $context = ['description', 'sample_content']): array
{
    return [
        'name' => 'Embudo', 'goal' => 'Vender',
        'stages' => [['key' => 'muestra', 'type' => 'ai', 'goal' => 'g', 'instructions' => 'i', 'context' => $context]],
    ];
}

function fsamIngest(Store $store, ?Product $product, string $purpose, string $title, string $body = 'Contenido'): KnowledgeSource
{
    return app(KnowledgeIngestor::class)->ingest($store, 'oracle', $product, $title, 'markdown', "# {$title}\n\n{$body}", origin: $title, purpose: $purpose);
}

function fsamUpload(array $values, string $content = "# Lección abierta\n\nCONTENIDO-MUESTRA"): Testable
{
    return Livewire::test(CreateKnowledgeSource::class)
        ->fillForm(['domain_key' => 'oracle', 'purpose' => KnowledgeSource::PURPOSE_SAMPLE])
        ->fillForm([...$values, 'file' => UploadedFile::fake()->createWithContent('muestra.md', $content)])
        ->call('create');
}

beforeEach(function () {
    config(['botinker.domains' => ['oracle' => OracleTestDomain::class]]);
    Storage::fake('local');

    $this->store = makeStore(['conversation_mode' => Store::MODE_KNOWLEDGE]);
    $this->other = makeStore(['conversation_mode' => Store::MODE_KNOWLEDGE]);
    $this->product = fsamProduct($this->store);
    $this->foreignProduct = fsamProduct($this->other, ['name' => 'Ajeno']);
    $this->operator = User::factory()->create(['store_id' => $this->store->id, 'is_super_admin' => false]);
    $this->versions = app(SalesFunnelVersions::class);

    $this->actingAs($this->operator);
});

describe('creating', function () {
    it('uploads a sample source for a product', function () {
        fsamUpload(['product_id' => $this->product->id])->assertHasNoFormErrors();

        expect(KnowledgeSource::latest('id')->first())
            ->store_id->toBe($this->store->id)
            ->product_id->toBe($this->product->id)
            ->purpose->toBe(KnowledgeSource::PURPOSE_SAMPLE)
            ->delivery->toBe(KnowledgeSource::DELIVERY_REFERENCE)
            ->status->toBe(KnowledgeSource::STATUS_ACTIVE);
    });

    it('uploads a sample source for the whole topic', function () {
        fsamUpload([])->assertHasNoFormErrors();

        expect(KnowledgeSource::latest('id')->first()->product_id)->toBeNull()
            ->and(app(KnowledgeRetriever::class)->headings($this->store, 'oracle', $this->product, purpose: KnowledgeSource::PURPOSE_SAMPLE))->toBe(['Lección abierta']);
    });

    it('offers the delivery of a sample', function () {
        Livewire::test(CreateKnowledgeSource::class)
            ->fillForm(['domain_key' => 'oracle', 'purpose' => KnowledgeSource::PURPOSE_SAMPLE])
            ->assertFormFieldVisible('delivery')
            ->fillForm(['delivery' => KnowledgeSource::DELIVERY_VERBATIM, 'file' => UploadedFile::fake()->createWithContent('muestra.md', "# Lección\n\nTexto literal")])
            ->call('create')
            ->assertHasNoFormErrors();

        expect(KnowledgeSource::latest('id')->first()->delivery)->toBe(KnowledgeSource::DELIVERY_VERBATIM);
    });

    it('keeps the other uses as they were', function () {
        Livewire::test(CreateKnowledgeSource::class)
            ->fillForm(['domain_key' => 'oracle'])
            ->assertFormSet(['purpose' => KnowledgeSource::PURPOSE_KNOWLEDGE])
            ->assertFormFieldHidden('delivery');
    });

    it('shows it as a sample in the list, and filters by it', function () {
        $sample = fsamIngest($this->store, $this->product, KnowledgeSource::PURPOSE_SAMPLE, 'Muestra');
        $knowledge = fsamIngest($this->store, null, KnowledgeSource::PURPOSE_KNOWLEDGE, 'Lección');

        Livewire::test(ListKnowledgeSources::class)
            ->assertTableColumnFormattedStateSet('purpose', 'Muestra', $sample)
            ->filterTable('purpose', KnowledgeSource::PURPOSE_SAMPLE)
            ->assertCanSeeTableRecords([$sample])
            ->assertCanNotSeeTableRecords([$knowledge]);
    });
});

describe('invalid data', function () {
    it('needs the topic and the file', function () {
        Livewire::test(CreateKnowledgeSource::class)
            ->fillForm(['purpose' => KnowledgeSource::PURPOSE_SAMPLE])
            ->call('create')
            ->assertHasFormErrors(['domain_key' => 'required', 'file' => 'required']);

        expect(KnowledgeSource::count())->toBe(0);
    });

    it('refuses a verbatim sample stating a price', function () {
        fsamUpload(['delivery' => KnowledgeSource::DELIVERY_VERBATIM], "# Oferta\n\nSolo por 20000 pesos")
            ->assertHasFormErrors(['file'])
            ->assertSee('un recurso literal no puede incluir precios');

        expect(KnowledgeSource::count())->toBe(0);
    });

    it('refuses a file with no text', function () {
        fsamUpload([], "   \n  ")->assertHasFormErrors(['file']);

        expect(KnowledgeSource::count())->toBe(0);
    });

    it('refuses a product of another store', function () {
        fsamUpload(['product_id' => $this->foreignProduct->id])->assertHasFormErrors(['product_id']);

        expect(KnowledgeSource::count())->toBe(0);
    });
});

describe('editing and removing', function () {
    it('replaces a source by uploading a new version with the same origin', function () {
        fsamUpload(['product_id' => $this->product->id, 'origin' => 'muestra-1'], "# Lección\n\nPRIMERA")->assertHasNoFormErrors();
        fsamUpload(['product_id' => $this->product->id, 'origin' => 'muestra-1'], "# Lección\n\nSEGUNDA")->assertHasNoFormErrors();

        $versions = KnowledgeSource::where('origin', 'muestra-1')->orderBy('version')->get();

        expect($versions->pluck('version')->all())->toBe([1, 2])
            ->and($versions->pluck('status')->all())->toBe([KnowledgeSource::STATUS_RETIRED, KnowledgeSource::STATUS_ACTIVE]);
    });

    it('removes a source from use by retiring it, keeping its history', function () {
        $source = fsamIngest($this->store, $this->product, KnowledgeSource::PURPOSE_SAMPLE, 'Muestra');

        Livewire::test(ListKnowledgeSources::class)->callAction(TestAction::make('retire')->table($source));

        expect($source->fresh()->status)->toBe(KnowledgeSource::STATUS_RETIRED)
            ->and(app(KnowledgeRetriever::class)->headings($this->store, 'oracle', $this->product, purpose: KnowledgeSource::PURPOSE_SAMPLE))->toBe([]);
    });

    it('warns again when a stage is left without sources', function () {
        $source = fsamIngest($this->store, $this->product, KnowledgeSource::PURPOSE_SAMPLE, 'Muestra');

        expect(FunnelPublicationChecks::warnings($this->product, fsamDefinition()))->toBe([]);

        app(KnowledgeIngestor::class)->retire($source);

        expect(FunnelPublicationChecks::warnings($this->product, fsamDefinition()))->toBe(['La etapa «muestra» usa contenido de muestra, pero el producto no tiene fuentes de muestra activas.']);
    });
});

describe('stages using them', function () {
    it('tells the editor how many sources a stage would read', function () {
        fsamIngest($this->store, $this->product, KnowledgeSource::PURPOSE_SAMPLE, 'Propia');
        fsamIngest($this->store, null, KnowledgeSource::PURPOSE_SAMPLE, 'Del tema');
        fsamIngest($this->store, fsamProduct($this->store, ['name' => 'Hermano']), KnowledgeSource::PURPOSE_SAMPLE, 'De otro');
        fsamIngest($this->store, $this->product, KnowledgeSource::PURPOSE_KNOWLEDGE, 'Lección');
        $this->versions->publish($this->product, fsamDefinition());

        Livewire::test(EditProductFunnel::class, ['record' => $this->product->getRouteKey()])
            ->assertSee('de este producto y de su tema (2 ahora)', false);
    });

    it('turns a stage on and off, and publishes that', function () {
        fsamIngest($this->store, $this->product, KnowledgeSource::PURPOSE_SAMPLE, 'Propia');
        $this->versions->publish($this->product, fsamDefinition(['description']));

        $page = Livewire::test(EditProductFunnel::class, ['record' => $this->product->getRouteKey()]);
        $stage = array_keys($page->instance()->data['stages'])[0];

        $page->fillForm(["stages.{$stage}.context" => ['description', 'sample_content']])
            ->callAction('publish', data: ['confirmed' => true])
            ->assertHasNoActionErrors();

        expect(FunnelDefinition::fromArray($this->product->fresh()->activeFunnel()->definition)->start()->uses(FunnelDefinition::CONTEXT_SAMPLE))->toBeTrue();
    });

    it('refuses a context outside the contract, in the panel\'s words', function () {
        $this->versions->publish($this->product, fsamDefinition());

        $page = Livewire::test(EditProductFunnel::class, ['record' => $this->product->getRouteKey()]);
        $stage = array_keys($page->instance()->data['stages'])[0];

        $page->set("data.stages.{$stage}.context", ['service_knowledge'])
            ->callAction('checkDefinition')
            ->assertHasFormErrors(["stages.{$stage}.context"])
            ->assertSee('Elige un contexto de la lista.');
    });

    it('offers a message stage no context', function () {
        $definition = fsamDefinition();
        $definition['stages'][] = ['key' => 'aviso', 'type' => 'message', 'text' => 'Hola', 'next' => 'muestra'];
        $this->versions->publish($this->product, $definition);

        $page = Livewire::test(EditProductFunnel::class, ['record' => $this->product->getRouteKey()]);
        $aviso = array_keys($page->instance()->data['stages'])[1];

        $page->assertFormFieldHidden("stages.{$aviso}.context");
    });
});

describe('the definition', function () {
    it('keeps the stage\'s choice, and the sources stay out of it', function () {
        $source = fsamIngest($this->store, $this->product, KnowledgeSource::PURPOSE_SAMPLE, 'Fuente-Propia');
        $definition = fsamDefinition(['sample_content', 'description', 'commercial_resources']);

        $rebuilt = FunnelDefinitionFormMapper::toDefinition(FunnelDefinitionFormMapper::toFormState($definition));

        expect($rebuilt['stages'][0]['context'])->toBe(['sample_content', 'description', 'commercial_resources'])
            ->and($rebuilt)->toBe($definition)
            ->and(json_encode($rebuilt))->not->toContain('Fuente-Propia');

        $this->versions->publish($this->product, $definition);

        $published = Livewire::test(EditProductFunnel::class, ['record' => $this->product->getRouteKey()])->instance()->definition();

        expect($published['stages'][0]['context'])->toBe(['sample_content', 'description', 'commercial_resources'])
            ->and(json_encode($published))->not->toContain((string) $source->title);
    });

    it('stores and reads back the same values', function () {
        $this->versions->publish($this->product, fsamDefinition());

        $funnel = SalesFunnel::where('product_id', $this->product->id)->sole();

        expect($funnel->fresh()->definition['stages'][0]['context'])->toBe(['description', 'sample_content'])
            ->and(FunnelDefinition::fromArray($funnel->fresh()->definition)->start()->uses(FunnelDefinition::CONTEXT_SAMPLE))->toBeTrue();
    });
});

describe('what only subscribers read', function () {
    beforeEach(function () {
        $this->exclusive = fsamIngest($this->store, $this->product, KnowledgeSource::PURPOSE_KNOWLEDGE, 'Lección exclusiva', 'CONTENIDO-EXCLUSIVO');
    });

    it('is not within reach of a stage using sample content', function () {
        $stage = FunnelDefinition::fromArray(fsamDefinition(['sample_content']))->start();
        $denied = AccessDecision::deny(AccessDecision::NO_SUBSCRIPTION, null, null, true);

        expect(app(KnowledgeAccess::class)->allowedPurposes($denied, $stage))->toBe([KnowledgeSource::PURPOSE_SAMPLE])
            ->and(app(KnowledgeRetriever::class)->headings($this->store, 'oracle', $this->product, purpose: KnowledgeSource::PURPOSE_SAMPLE))->toBe([]);
    });

    it('cannot be opened by any context of a stage', function () {
        expect(FunnelDefinition::CONTEXTS)->not->toContain(KnowledgeSource::PURPOSE_KNOWLEDGE)
            ->and(fn () => FunnelDefinition::fromArray(fsamDefinition(['knowledge'])))->toThrow(InvalidArgumentException::class);
    });

    it('is refused when uploaded again as a sample, in the panel\'s words', function () {
        fsamUpload(['product_id' => $this->product->id], "# Lección exclusiva\n\nCONTENIDO-EXCLUSIVO")
            ->assertHasFormErrors(['file'])
            ->assertSee('repite contenido exclusivo de suscriptores');

        expect(KnowledgeSource::where('purpose', KnowledgeSource::PURPOSE_SAMPLE)->count())->toBe(0);
    });

    it('is refused when only one section repeats it', function () {
        fsamUpload([], "# Propia\n\nMATERIAL-ABIERTO\n\n# Lección exclusiva\n\nCONTENIDO-EXCLUSIVO")->assertHasFormErrors(['file']);

        expect(KnowledgeSource::where('purpose', KnowledgeSource::PURPOSE_SAMPLE)->count())->toBe(0);
    });

    it('leaves the previous version of the sample active when the new one is refused', function () {
        fsamUpload(['origin' => 'muestra-1'], "# Propia\n\nMATERIAL-ABIERTO")->assertHasNoFormErrors();
        fsamUpload(['origin' => 'muestra-1'], "# Lección exclusiva\n\nCONTENIDO-EXCLUSIVO")->assertHasFormErrors(['file']);

        expect(KnowledgeSource::where('origin', 'muestra-1')->sole())
            ->version->toBe(1)
            ->status->toBe(KnowledgeSource::STATUS_ACTIVE);
    });

    it('accepts own material, a retired lesson and the same text in another store', function () {
        fsamUpload([], "# Propia\n\nMATERIAL-ABIERTO")->assertHasNoFormErrors();

        fsamIngest($this->other, null, KnowledgeSource::PURPOSE_KNOWLEDGE, 'Ajena', 'TEXTO-AJENO');
        fsamUpload(['origin' => 'igual-a-otra-tienda'], "# Ajena\n\nTEXTO-AJENO")->assertHasNoFormErrors();

        app(KnowledgeIngestor::class)->retire($this->exclusive);
        fsamUpload(['origin' => 'ya-no-exclusiva'], "# Lección exclusiva\n\nCONTENIDO-EXCLUSIVO")->assertHasNoFormErrors();

        expect(KnowledgeSource::where('purpose', KnowledgeSource::PURPOSE_SAMPLE)->count())->toBe(3);
    });

    it('does not check the other uses', function () {
        Livewire::test(CreateKnowledgeSource::class)
            ->fillForm(['domain_key' => 'oracle', 'purpose' => KnowledgeSource::PURPOSE_COMMERCIAL])
            ->fillForm(['file' => UploadedFile::fake()->createWithContent('comercial.md', "# Lección exclusiva\n\nCONTENIDO-EXCLUSIVO")])
            ->call('create')
            ->assertHasNoFormErrors();
    });
});

describe('another store', function () {
    it('does not see nor retire foreign sample sources', function () {
        $foreign = fsamIngest($this->other, null, KnowledgeSource::PURPOSE_SAMPLE, 'Ajena');

        Livewire::test(ListKnowledgeSources::class)
            ->assertCanNotSeeTableRecords([$foreign]);

        expect($foreign->fresh()->status)->toBe(KnowledgeSource::STATUS_ACTIVE);
    });

    it('does not count foreign sources for a stage', function () {
        fsamIngest($this->other, null, KnowledgeSource::PURPOSE_SAMPLE, 'Ajena');

        expect(FunnelPublicationChecks::warnings($this->product, fsamDefinition()))->toHaveCount(1);
    });

    it('uploads for the operator\'s own store whatever is asked', function () {
        fsamUpload(['store_id' => $this->other->id])->assertHasNoFormErrors();

        expect(KnowledgeSource::latest('id')->first()->store_id)->toBe($this->store->id);
    });
});
