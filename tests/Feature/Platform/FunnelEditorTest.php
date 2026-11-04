<?php

/*
 * The editor of a funnel's definition: it loads any version of the product,
 * edits it in memory and gives back what FunnelDefinition accepts. Nothing it
 * does is stored, and each store reaches only its own.
 */

use App\Filament\Resources\Products\Pages\EditProductFunnel;
use App\Filament\Resources\Products\ProductResource;
use App\Models\ConversationFunnelState;
use App\Models\Product;
use App\Models\SalesFunnel;
use App\Models\Store;
use App\Models\User;
use App\Platform\Funnel\FunnelDefinition;
use App\Platform\Funnel\SalesFunnelVersions;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Domains\OracleTestDomain;

function feditProduct(Store $store, array $overrides = []): Product
{
    return Product::create(array_merge([
        'store_id' => $store->id, 'domain_key' => 'oracle', 'name' => 'Asesor', 'description' => 'x', 'price' => 1, 'stock' => 1,
        'access_mode' => Product::ACCESS_SUBSCRIPTION,
    ], $overrides));
}

function feditDefinition(string $name = 'Embudo-A', array $overrides = []): array
{
    return array_merge([
        'name' => $name,
        'goal' => 'OBJETIVO-EMBUDO',
        'start_stage' => 'dudas',
        'offer_cta' => 'LLAMADO',
        'free_replies' => ['limit' => 2, 'on_exhausted' => 'venta'],
        'opening_consumes_free_reply' => false,
        'intents' => [['key' => 'comprar', 'description' => 'Quiere adquirir'], ['key' => 'dudar', 'description' => 'Tiene dudas']],
        'stages' => [
            ['key' => 'bienvenida', 'type' => 'message', 'text' => 'TEXTO-LITERAL', 'next' => 'dudas', 'uses_free_replies' => true],
            [
                'key' => 'dudas', 'type' => 'ai', 'goal' => 'OBJETIVO-DUDAS', 'instructions' => 'INSTRUCCIONES-DUDAS', 'restrictions' => 'RESTRICCIONES-DUDAS',
                'context' => ['description', 'sample_content'], 'uses_free_replies' => true, 'max_interactions' => 5, 'on_limit' => 'venta',
                'rules' => [
                    ['when' => ['phrase' => ['Lo quiero']], 'then' => ['action' => 'start_payment', 'stage' => 'venta']],
                    ['when' => ['intent' => 'comprar', 'min_confidence' => 0.9], 'then' => ['action' => 'show_offer']],
                ],
            ],
            ['key' => 'venta', 'type' => 'ai', 'goal' => 'OBJETIVO-VENTA', 'instructions' => 'INSTRUCCIONES-VENTA', 'uses_free_replies' => false],
        ],
    ], $overrides);
}

function feditPage(Product $product, ?int $from = null): Testable
{
    return Livewire::withQueryParams($from !== null ? ['from' => $from] : [])
        ->test(EditProductFunnel::class, ['record' => $product->getRouteKey()]);
}

/** The stage states of the page, by position. */
function feditStages(Testable $page): array
{
    return array_values($page->instance()->data['stages']);
}

/** The repeater's key of a stage, to address its fields. */
function feditStageKey(Testable $page, int $position): string
{
    return array_keys($page->instance()->data['stages'])[$position];
}

function feditSorted(array $value): array
{
    if (! array_is_list($value)) {
        ksort($value);
    }

    return array_map(fn (mixed $item) => is_array($item) ? feditSorted($item) : $item, $value);
}

beforeEach(function () {
    config(['botinker.domains' => ['oracle' => OracleTestDomain::class]]);

    $this->store = makeStore(['conversation_mode' => Store::MODE_KNOWLEDGE]);
    $this->other = makeStore(['conversation_mode' => Store::MODE_KNOWLEDGE]);
    $this->product = feditProduct($this->store);
    $this->foreignProduct = feditProduct($this->other, ['name' => 'Ajeno']);
    $this->operator = User::factory()->create(['store_id' => $this->store->id, 'is_super_admin' => false]);
    $this->versions = app(SalesFunnelVersions::class);

    $this->actingAs($this->operator);
});

describe('loading', function () {
    it('starts empty for a product without versions, with the contract\'s defaults', function () {
        $page = feditPage($this->product);

        $page->assertOk()
            ->assertFormSet(['name' => null, 'goal' => null, 'start_stage' => null, 'offer_cta' => null, 'opening_consumes_free_reply' => true])
            ->assertFormFieldExists('free_replies.limit')
            ->assertFormFieldExists('free_replies.on_exhausted');

        expect($page->instance()->data['stages'])->toBe([])
            ->and($page->instance()->data['intents'])->toBe([])
            ->and($page->instance()->baseVersion)->toBeNull();
    });

    it('starts from the active version', function () {
        $this->versions->publish($this->product, feditDefinition('Embudo-A'));
        $this->versions->publish($this->product, feditDefinition('Embudo-B'));

        $page = feditPage($this->product);

        $page->assertFormSet([
            'name' => 'Embudo-B', 'goal' => 'OBJETIVO-EMBUDO', 'start_stage' => 'dudas', 'offer_cta' => 'LLAMADO',
            'free_replies.limit' => 2, 'free_replies.on_exhausted' => 'venta', 'opening_consumes_free_reply' => false,
        ]);

        expect(array_column(feditStages($page), 'key'))->toBe(['bienvenida', 'dudas', 'venta'])
            ->and(array_column(array_values($page->instance()->data['intents']), 'key'))->toBe(['comprar', 'dudar'])
            ->and($page->instance()->baseVersion)->toBe(2);
    });

    it('starts from any version in the history', function () {
        $this->versions->publish($this->product, feditDefinition('Embudo-A'));
        $this->versions->publish($this->product, feditDefinition('Embudo-B'));

        $page = feditPage($this->product, 1);

        $page->assertFormSet(['name' => 'Embudo-A'])->assertSee('Partes de la versión v1');

        expect($page->instance()->baseVersion)->toBe(1);
    });

    it('starts from a version even when the funnel is deactivated', function () {
        $this->versions->publish($this->product, feditDefinition());
        $this->versions->deactivate($this->product);

        feditPage($this->product)->assertFormSet(['name' => null]);
        feditPage($this->product, 1)->assertFormSet(['name' => 'Embudo-A']);
    });

    it('shows the opening as spending a free reply when the definition does not say', function () {
        $definition = feditDefinition();
        unset($definition['opening_consumes_free_reply']);
        $this->versions->publish($this->product, $definition);

        feditPage($this->product)->assertFormSet(['opening_consumes_free_reply' => true]);
    });

    it('keeps saying no when the definition says no', function () {
        $this->versions->publish($this->product, feditDefinition());

        feditPage($this->product)->assertFormSet(['opening_consumes_free_reply' => false]);
    });

    it('warns that leaving discards the changes', function () {
        feditPage($this->product)->assertSee('cerrarla o salir de ella los descarta');
    });

    it('opens through HTTP from the funnel\'s route', function () {
        $this->versions->publish($this->product, feditDefinition());

        $this->get(ProductResource::getUrl('funnel-edit', ['record' => $this->product]))->assertOk()->assertSee('Embudo-A', false);
        $this->get(ProductResource::getUrl('funnel-edit', ['record' => $this->product, 'from' => 1]))->assertOk();
        $this->get(ProductResource::getUrl('funnel-version', ['record' => $this->product, 'version' => 1]))->assertOk();
    });
});

describe('what comes back', function () {
    it('is the same definition when nothing was edited, rules included', function () {
        $this->versions->publish($this->product, feditDefinition());

        expect(feditSorted(feditPage($this->product)->instance()->definition()))->toBe(feditSorted(feditDefinition()));
    });

    it('passes the contract', function () {
        $this->versions->publish($this->product, feditDefinition());

        $definition = feditPage($this->product)->instance()->definition();

        expect(FunnelDefinition::fromArray($definition))->toEqual(FunnelDefinition::fromArray(feditDefinition()));
    });

    it('takes the edits, with the types the contract needs', function () {
        $this->versions->publish($this->product, feditDefinition());

        $page = feditPage($this->product);
        $dudas = feditStageKey($page, 1);

        $page->fillForm([
            'name' => 'Editado',
            'goal' => 'OTRO-OBJETIVO',
            'offer_cta' => '',
            'start_stage' => 'venta',
            'free_replies.limit' => '4',
            'free_replies.on_exhausted' => null,
            'opening_consumes_free_reply' => true,
            "stages.{$dudas}.goal" => 'OTRO-OBJETIVO-DUDAS',
            "stages.{$dudas}.restrictions" => '',
            "stages.{$dudas}.max_interactions" => '7',
            "stages.{$dudas}.context" => ['faq'],
            "stages.{$dudas}.uses_free_replies" => false,
        ]);

        $definition = $page->instance()->definition();

        expect($definition['name'])->toBe('Editado')
            ->and($definition['goal'])->toBe('OTRO-OBJETIVO')
            ->and($definition)->not->toHaveKey('offer_cta')
            ->and($definition['start_stage'])->toBe('venta')
            ->and($definition['free_replies'])->toBe(['limit' => 4])
            ->and($definition['opening_consumes_free_reply'])->toBeTrue()
            ->and($definition['stages'][1]['goal'])->toBe('OTRO-OBJETIVO-DUDAS')
            ->and($definition['stages'][1])->not->toHaveKey('restrictions')
            ->and($definition['stages'][1]['max_interactions'])->toBe(7)
            ->and($definition['stages'][1]['context'])->toBe(['faq'])
            ->and($definition['stages'][1]['uses_free_replies'])->toBeFalse()
            ->and(count($definition['stages'][1]['rules']))->toBe(2);

        FunnelDefinition::fromArray($definition);
    });

    it('builds a definition from nothing', function () {
        $page = feditPage($this->product);

        $page->fillForm([
            'name' => 'Nuevo',
            'goal' => 'Vender',
            'intents' => [['key' => 'comprar', 'description' => 'Quiere']],
            'stages' => [
                ['key' => 'hola', 'type' => 'message', 'text' => 'Hola', 'next' => 'dudas'],
                ['key' => 'dudas', 'type' => 'ai', 'goal' => 'g', 'instructions' => 'i'],
            ],
        ]);

        $definition = $page->instance()->definition();

        expect($definition['name'])->toBe('Nuevo')
            ->and(array_column($definition['stages'], 'key'))->toBe(['hola', 'dudas'])
            ->and($definition['intents'])->toBe([['key' => 'comprar', 'description' => 'Quiere']])
            ->and($definition['opening_consumes_free_reply'])->toBeTrue();

        FunnelDefinition::fromArray($definition);
    });

    it('tells whether the definition is valid, storing nothing', function () {
        $this->versions->publish($this->product, feditDefinition());
        $total = SalesFunnel::count();

        feditPage($this->product)
            ->callAction('checkDefinition')
            ->assertHasNoFormErrors()
            ->assertNotified('La definición es válida.');

        expect(SalesFunnel::count())->toBe($total);
    });

});

describe('stage types', function () {
    beforeEach(function () {
        $this->versions->publish($this->product, feditDefinition());
        $this->page = feditPage($this->product);
        $this->bienvenida = feditStageKey($this->page, 0);
        $this->dudas = feditStageKey($this->page, 1);
    });

    it('offers each type only the fields the engine uses', function () {
        $this->page
            ->assertFormFieldVisible("stages.{$this->bienvenida}.text")
            ->assertFormFieldVisible("stages.{$this->bienvenida}.next")
            ->assertFormFieldVisible("stages.{$this->bienvenida}.uses_free_replies")
            ->assertFormFieldHidden("stages.{$this->bienvenida}.goal")
            ->assertFormFieldHidden("stages.{$this->bienvenida}.instructions")
            ->assertFormFieldHidden("stages.{$this->bienvenida}.restrictions")
            ->assertFormFieldHidden("stages.{$this->bienvenida}.context")
            ->assertFormFieldHidden("stages.{$this->bienvenida}.max_interactions")
            ->assertFormFieldHidden("stages.{$this->bienvenida}.on_limit")
            ->assertFormFieldVisible("stages.{$this->dudas}.goal")
            ->assertFormFieldVisible("stages.{$this->dudas}.instructions")
            ->assertFormFieldVisible("stages.{$this->dudas}.restrictions")
            ->assertFormFieldVisible("stages.{$this->dudas}.context")
            ->assertFormFieldVisible("stages.{$this->dudas}.max_interactions")
            ->assertFormFieldVisible("stages.{$this->dudas}.on_limit")
            ->assertFormFieldVisible("stages.{$this->dudas}.uses_free_replies")
            ->assertFormFieldHidden("stages.{$this->dudas}.text")
            ->assertFormFieldHidden("stages.{$this->dudas}.next");
    });

    it('switches a stage from AI to message, leaving nothing of the other type', function () {
        $this->page->fillForm([
            "stages.{$this->dudas}.type" => 'message',
            "stages.{$this->dudas}.text" => 'AHORA-MENSAJE',
            "stages.{$this->dudas}.next" => 'venta',
        ]);

        $this->page->assertFormFieldVisible("stages.{$this->dudas}.text")->assertFormFieldHidden("stages.{$this->dudas}.goal");

        $stage = $this->page->instance()->definition()['stages'][1];

        expect($stage['type'])->toBe('message')
            ->and($stage['text'])->toBe('AHORA-MENSAJE')
            ->and($stage['next'])->toBe('venta')
            ->and($stage)->not->toHaveKeys(['goal', 'instructions', 'restrictions', 'context', 'max_interactions', 'on_limit', 'rules']);

        FunnelDefinition::fromArray($this->page->instance()->definition());
    });

    it('switches a stage from message to AI, leaving nothing of the other type', function () {
        $this->page->fillForm([
            "stages.{$this->bienvenida}.type" => 'ai',
            "stages.{$this->bienvenida}.goal" => 'NUEVO-OBJETIVO',
            "stages.{$this->bienvenida}.instructions" => 'NUEVAS-INSTRUCCIONES',
        ]);

        $stage = $this->page->instance()->definition()['stages'][0];

        expect($stage['type'])->toBe('ai')
            ->and($stage['goal'])->toBe('NUEVO-OBJETIVO')
            ->and($stage)->not->toHaveKeys(['text', 'next']);

        FunnelDefinition::fromArray($this->page->instance()->definition());
    });

    it('asks for what each type needs', function () {
        $this->page->fillForm(["stages.{$this->bienvenida}.text" => '', "stages.{$this->bienvenida}.next" => null, "stages.{$this->dudas}.goal" => '', "stages.{$this->dudas}.instructions" => ''])
            ->callAction('checkDefinition')
            ->assertHasFormErrors([
                "stages.{$this->bienvenida}.text" => 'required',
                "stages.{$this->bienvenida}.next" => 'required',
                "stages.{$this->dudas}.goal" => 'required',
                "stages.{$this->dudas}.instructions" => 'required',
            ]);
    });
});

describe('keys and order', function () {
    beforeEach(function () {
        $this->versions->publish($this->product, feditDefinition());
        $this->page = feditPage($this->product);
    });

    it('does not let an existing stage change its key', function () {
        $dudas = feditStageKey($this->page, 1);

        $this->page->assertFormFieldReadOnly("stages.{$dudas}.key");

        $this->page->set("data.stages.{$dudas}.key", 'renombrada')
            ->callAction('checkDefinition')
            ->assertHasFormErrors(["stages.{$dudas}.key"]);
    });

    it('lets a new stage choose its key', function () {
        $stages = $this->page->instance()->data['stages'];
        $stages['nueva'] = ['key' => 'cierre', 'type' => 'ai', 'goal' => 'g', 'instructions' => 'i'];

        $this->page->set('data.stages', $stages)->assertFormFieldExists('stages.nueva.key');

        expect(array_column($this->page->instance()->definition()['stages'], 'key'))->toBe(['bienvenida', 'dudas', 'venta', 'cierre']);
    });

    it('keeps the order of stages as arranged', function () {
        $stages = $this->page->instance()->data['stages'];
        $this->page->set('data.stages', array_reverse($stages, true));

        expect(array_column($this->page->instance()->definition()['stages'], 'key'))->toBe(['venta', 'dudas', 'bienvenida']);
    });

    it('keeps the order of intents', function () {
        $intents = $this->page->instance()->data['intents'];
        $this->page->set('data.intents', array_reverse($intents, true));

        expect(array_column($this->page->instance()->definition()['intents'], 'key'))->toBe(['dudar', 'comprar']);
    });

    it('refuses repeated stage keys', function () {
        $stages = $this->page->instance()->data['stages'];
        $stages['nueva'] = ['key' => 'dudas', 'type' => 'ai', 'goal' => 'g', 'instructions' => 'i'];

        $this->page->set('data.stages', $stages)
            ->callAction('checkDefinition')
            ->assertHasFormErrors(['stages.nueva.key'])
            ->assertSee('Esta clave de etapa ya existe.');
    });

    it('refuses repeated intent keys', function () {
        $intents = $this->page->instance()->data['intents'];
        $intents['nueva'] = ['key' => 'comprar', 'description' => 'Otra'];

        $this->page->set('data.intents', $intents)
            ->callAction('checkDefinition')
            ->assertHasFormErrors(['intents.nueva.key'])
            ->assertSee('Esta clave de intención ya existe.');
    });

    it('refuses keys the contract would not read well', function () {
        $intents = $this->page->instance()->data['intents'];
        $intents['nueva'] = ['key' => 'Con Espacios', 'description' => 'Otra'];
        $stages = $this->page->instance()->data['stages'];
        $stages['nueva'] = ['key' => 'Con Espacios', 'type' => 'ai', 'goal' => 'g', 'instructions' => 'i'];

        $this->page->set('data.intents', $intents)->set('data.stages', $stages)
            ->callAction('checkDefinition')
            ->assertHasFormErrors(['intents.nueva.key' => 'regex', 'stages.nueva.key' => 'regex']);
    });

    it('offers as destinations only the stages that exist, also the ones just added', function () {
        $stages = $this->page->instance()->data['stages'];
        $stages['nueva'] = ['key' => 'cierre', 'type' => 'ai', 'goal' => 'g', 'instructions' => 'i'];
        $dudas = feditStageKey($this->page, 1);
        $bienvenida = feditStageKey($this->page, 0);

        $this->page->set('data.stages', $stages);

        $options = fn (string $path) => $this->page->instance()->form->getComponent(fn ($component) => $component instanceof Select && $component->getStatePath() === "data.{$path}", withHidden: true)->getOptions();

        expect(array_keys($options('start_stage')))->toBe(['bienvenida', 'dudas', 'venta', 'cierre'])
            ->and(array_keys($options('free_replies.on_exhausted')))->toBe(['bienvenida', 'dudas', 'venta', 'cierre'])
            ->and(array_keys($options("stages.{$dudas}.on_limit")))->toBe(['bienvenida', 'dudas', 'venta', 'cierre'])
            // A message cannot follow itself.
            ->and(array_keys($options("stages.{$bienvenida}.next")))->toBe(['dudas', 'venta', 'cierre']);
    });

    it('rejects a destination that does not exist', function () {
        $this->page->fillForm(['start_stage' => 'nada'])
            ->callAction('checkDefinition')
            ->assertHasFormErrors(['start_stage']);
    });
});

describe('validating in the panel\'s words', function () {
    it('needs a name, a goal and a stage', function () {
        feditPage($this->product)
            ->callAction('checkDefinition')
            ->assertHasFormErrors(['name' => 'required', 'goal' => 'required', 'stages']);
    });

    it('limits the free replies as the contract does', function () {
        $this->versions->publish($this->product, feditDefinition());

        feditPage($this->product)->fillForm(['free_replies.limit' => 256])->callAction('checkDefinition')->assertHasFormErrors(['free_replies.limit' => 'max']);
        feditPage($this->product)->fillForm(['free_replies.limit' => -1])->callAction('checkDefinition')->assertHasFormErrors(['free_replies.limit' => 'min']);
        feditPage($this->product)->fillForm(['free_replies.limit' => 'tres'])->callAction('checkDefinition')->assertHasFormErrors(['free_replies.limit']);
    });

    it('needs a destination when a stage limits its interactions', function () {
        $this->versions->publish($this->product, feditDefinition());
        $page = feditPage($this->product);
        $dudas = feditStageKey($page, 1);

        $page->fillForm(["stages.{$dudas}.on_limit" => null])->callAction('checkDefinition')->assertHasFormErrors(["stages.{$dudas}.on_limit" => 'required']);
    });

    it('refuses a stage spending free replies in a funnel that gives none', function () {
        $this->versions->publish($this->product, feditDefinition());
        $page = feditPage($this->product);
        $dudas = feditStageKey($page, 1);

        $page->fillForm(['free_replies.limit' => 0])
            ->callAction('checkDefinition')
            ->assertHasFormErrors(["stages.{$dudas}.uses_free_replies"]);
    });
});

describe('another store', function () {
    beforeEach(function () {
        $this->versions->publish($this->foreignProduct, feditDefinition('Embudo-Ajeno'));
    });

    it('answers 404 on the editor of a foreign product', function () {
        $this->get(ProductResource::getUrl('funnel-edit', ['record' => $this->foreignProduct]))->assertNotFound();
        $this->get(ProductResource::getUrl('funnel-edit', ['record' => $this->foreignProduct, 'from' => 1]))->assertNotFound();
    });

    it('cannot load a version of another product through its own', function () {
        $this->versions->publish($this->product, feditDefinition('Embudo-Propio'));
        $sibling = feditProduct($this->store, ['name' => 'Hermano']);

        $this->get(ProductResource::getUrl('funnel-edit', ['record' => $sibling, 'from' => 1]))->assertNotFound();
        $this->get(ProductResource::getUrl('funnel-edit', ['record' => $this->product, 'from' => 99]))->assertNotFound();
        $this->get(ProductResource::getUrl('funnel-edit', ['record' => $this->product, 'from' => 1]))->assertOk()->assertSee('Embudo-Propio', false)->assertDontSee('Embudo-Ajeno', false);
    });

    it('is open to a super admin', function () {
        $this->actingAs(User::factory()->create(['is_super_admin' => true]));

        $this->get(ProductResource::getUrl('funnel-edit', ['record' => $this->foreignProduct]))->assertOk()->assertSee('Embudo-Ajeno', false);
    });
});

it('writes nothing while editing and leaving', function () {
    $this->versions->publish($this->product, feditDefinition());
    $before = [SalesFunnel::count(), ConversationFunnelState::count(), $this->product->fresh()->active_sales_funnel_id, $this->product->fresh()->updated_at->toIso8601String()];

    $writes = [];
    DB::listen(function ($query) use (&$writes) {
        if (! str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
            $writes[] = $query->sql;
        }
    });

    $this->get(ProductResource::getUrl('funnel-edit', ['record' => $this->product]))->assertOk();

    $page = feditPage($this->product);
    $page->fillForm(['name' => 'Sin guardar'])->callAction('checkDefinition');
    $page->instance()->definition();

    expect($writes)->toBe([])
        ->and([SalesFunnel::count(), ConversationFunnelState::count(), $this->product->fresh()->active_sales_funnel_id, $this->product->fresh()->updated_at->toIso8601String()])->toBe($before)
        ->and($this->product->fresh()->activeFunnel()->definition['name'])->toBe('Embudo-A');
});
