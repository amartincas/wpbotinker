<?php

/*
 * The editor of a funnel's rules: the six conditions and four actions of the
 * contract, with their fields according to what is chosen. It gives back what
 * FunnelDefinition accepts, stores nothing, and each store reaches only its
 * own.
 */

use App\Filament\Resources\Products\Pages\EditProductFunnel;
use App\Filament\Resources\Products\ProductResource;
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

function fruleProduct(Store $store, array $overrides = []): Product
{
    return Product::create(array_merge([
        'store_id' => $store->id, 'domain_key' => 'oracle', 'name' => 'Asesor', 'description' => 'x', 'price' => 1, 'stock' => 1,
        'access_mode' => Product::ACCESS_SUBSCRIPTION,
    ], $overrides));
}

function fruleDefinition(array $rules = [], array $overrides = []): array
{
    return array_merge([
        'name' => 'Embudo',
        'goal' => 'OBJETIVO',
        'free_replies' => ['limit' => 2, 'on_exhausted' => 'venta'],
        'opening_consumes_free_reply' => true,
        'intents' => [['key' => 'comprar', 'description' => 'Quiere adquirir'], ['key' => 'dudar', 'description' => 'Tiene dudas']],
        'stages' => [
            ['key' => 'bienvenida', 'type' => 'message', 'text' => 'Hola', 'uses_free_replies' => false, 'next' => 'dudas'],
            ['key' => 'dudas', 'type' => 'ai', 'goal' => 'g', 'instructions' => 'i', 'uses_free_replies' => true, 'rules' => $rules],
            ['key' => 'venta', 'type' => 'ai', 'goal' => 'g', 'instructions' => 'i', 'uses_free_replies' => false],
        ],
    ], $overrides);
}

function frulePage(Product $product): Testable
{
    return Livewire::test(EditProductFunnel::class, ['record' => $product->getRouteKey()]);
}

function fruleStageKey(Testable $page, int $position): string
{
    return array_keys($page->instance()->data['stages'])[$position];
}

function fruleKey(Testable $page, int $position = 0, int $stage = 1): string
{
    return array_keys($page->instance()->data['stages'][fruleStageKey($page, $stage)]['rules'])[$position];
}

/** The path of a field of a rule of the "dudas" stage, for the form's assertions. */
function frulePath(Testable $page, string $field, int $position = 0): string
{
    return 'stages.'.fruleStageKey($page, 1).'.rules.'.fruleKey($page, $position).'.'.$field;
}

/** Writes rules to the "dudas" stage as a form would hold them. */
function fruleSet(Testable $page, array $rules): Testable
{
    return $page->set('data.stages.'.fruleStageKey($page, 1).'.rules', $rules);
}

function fruleRules(Testable $page): array
{
    return $page->instance()->definition()['stages'][1]['rules'] ?? [];
}

function fruleSorted(array $value): array
{
    if (! array_is_list($value)) {
        ksort($value);
    }

    return array_map(fn (mixed $item) => is_array($item) ? fruleSorted($item) : $item, $value);
}

/** The editor does not offer what the engine does not use of each stage type, so it does not come back. */
function fruleOnlyWhatRuns(array $definition): array
{
    $definition['stages'] = array_map(fn (array $stage) => array_diff_key($stage, array_flip(
        $stage['type'] === 'message'
            ? ['goal', 'instructions', 'restrictions', 'context', 'max_interactions', 'on_limit', 'rules']
            : ['next', 'text'],
    )), $definition['stages']);

    return $definition;
}

function fruleOptions(Testable $page, string $path): array
{
    return array_keys($page->instance()->form->getComponent(
        fn ($component) => $component instanceof Select && $component->getStatePath() === "data.{$path}",
        withHidden: true,
    )->getOptions());
}

function fruleCheck(Testable $page): Testable
{
    return $page->callAction('checkDefinition');
}

beforeEach(function () {
    config(['botinker.domains' => ['oracle' => OracleTestDomain::class]]);

    $this->store = makeStore(['conversation_mode' => Store::MODE_KNOWLEDGE]);
    $this->other = makeStore(['conversation_mode' => Store::MODE_KNOWLEDGE]);
    $this->product = fruleProduct($this->store);
    $this->foreignProduct = fruleProduct($this->other, ['name' => 'Ajeno']);
    $this->operator = User::factory()->create(['store_id' => $this->store->id, 'is_super_admin' => false]);
    $this->versions = app(SalesFunnelVersions::class);

    $this->actingAs($this->operator);
});

describe('loading and rebuilding', function () {
    it('comes back with every condition and action, in order', function () {
        $rules = [
            ['when' => ['phrase' => ['Lo quiero', 'Me apunto']], 'then' => ['action' => 'start_payment', 'stage' => 'venta']],
            ['when' => ['plan_named' => true], 'then' => ['action' => 'start_payment']],
            ['when' => ['intent' => 'comprar', 'min_confidence' => 0.9], 'then' => ['action' => 'show_offer', 'stage' => 'venta']],
            ['when' => ['intent' => 'dudar'], 'then' => ['action' => 'stay']],
            ['when' => ['interactions_reached' => 3], 'then' => ['action' => 'go_to', 'stage' => 'venta']],
            ['when' => ['free_replies_exhausted' => true], 'then' => ['action' => 'show_offer']],
            ['when' => ['any_reply' => true], 'then' => ['action' => 'stay']],
        ];
        $this->versions->publish($this->product, fruleDefinition($rules));

        $definition = frulePage($this->product)->instance()->definition();

        expect(fruleSorted($definition))->toBe(fruleSorted(fruleDefinition($rules)));

        FunnelDefinition::fromArray($definition);
    });

    it('reads the same for the contract, for each reference definition', function (array $definition) {
        $this->versions->publish($this->product, $definition);

        $page = frulePage($this->product);
        $rebuilt = $page->instance()->definition();

        expect(FunnelDefinition::fromArray($rebuilt))->toEqual(FunnelDefinition::fromArray(fruleOnlyWhatRuns($definition)));
    })->with(function () {
        foreach (json_decode(file_get_contents(__DIR__.'/../../Support/Funnels/engine_definitions.json'), true) as $index => $definition) {
            yield "definition {$index}" => [$definition];
        }
    });
});

describe('each condition with each action', function () {
    $conditions = [
        'phrase' => [['condition' => 'phrase', 'phrases' => ['Lo quiero']], ['phrase' => ['Lo quiero']]],
        'plan_named' => [['condition' => 'plan_named'], ['plan_named' => true]],
        'interactions_reached' => [['condition' => 'interactions_reached', 'interactions' => 4], ['interactions_reached' => 4]],
        'free_replies_exhausted' => [['condition' => 'free_replies_exhausted'], ['free_replies_exhausted' => true]],
        'intent' => [['condition' => 'intent', 'intent' => 'comprar', 'min_confidence' => 0.75], ['intent' => 'comprar', 'min_confidence' => 0.75]],
        'any_reply' => [['condition' => 'any_reply'], ['any_reply' => true]],
    ];

    $actions = [
        'go_to' => [['action' => 'go_to', 'stage' => 'venta'], ['action' => 'go_to', 'stage' => 'venta']],
        'show_offer' => [['action' => 'show_offer'], ['action' => 'show_offer']],
        'show_offer and a stage' => [['action' => 'show_offer', 'stage' => 'venta'], ['action' => 'show_offer', 'stage' => 'venta']],
        'start_payment' => [['action' => 'start_payment'], ['action' => 'start_payment']],
        'start_payment and a stage' => [['action' => 'start_payment', 'stage' => 'venta'], ['action' => 'start_payment', 'stage' => 'venta']],
        'stay' => [['action' => 'stay'], ['action' => 'stay']],
    ];

    foreach ($conditions as $conditionName => [$conditionState, $when]) {
        foreach ($actions as $actionName => [$actionState, $then]) {
            it("builds {$conditionName} with {$actionName}", function () use ($conditionState, $when, $actionState, $then) {
                $this->versions->publish($this->product, fruleDefinition());
                $page = frulePage($this->product);

                fruleSet($page, ['nueva' => [...$conditionState, ...$actionState]]);

                expect(fruleRules($page))->toBe([['when' => $when, 'then' => $then]]);

                fruleCheck($page)->assertHasNoFormErrors()->assertNotified('La definición es válida.');
            });
        }
    }
});

describe('fields according to what is chosen', function () {
    beforeEach(function () {
        $this->versions->publish($this->product, fruleDefinition([['when' => ['any_reply' => true], 'then' => ['action' => 'stay']]]));
        $this->page = frulePage($this->product);
        $this->set = fn (array $values) => fruleSet($this->page, ['r' => $values]);
    });

    it('asks each condition only for its value', function (string $condition, array $visible) {
        ($this->set)(['condition' => $condition, 'action' => 'stay']);

        foreach (['phrases', 'intent', 'min_confidence', 'interactions'] as $field) {
            in_array($field, $visible, true)
                ? $this->page->assertFormFieldVisible(frulePath($this->page, $field))
                : $this->page->assertFormFieldHidden(frulePath($this->page, $field));
        }
    })->with([
        ['phrase', ['phrases']],
        ['plan_named', []],
        ['interactions_reached', ['interactions']],
        ['free_replies_exhausted', []],
        ['intent', ['intent', 'min_confidence']],
        ['any_reply', []],
    ]);

    it('offers a stage for each action that can use one', function (string $action, bool $visible) {
        ($this->set)(['condition' => 'any_reply', 'action' => $action]);

        $visible
            ? $this->page->assertFormFieldVisible(frulePath($this->page, 'stage'))
            : $this->page->assertFormFieldHidden(frulePath($this->page, 'stage'));
    })->with([
        ['go_to', true],
        ['show_offer', true],
        ['start_payment', true],
        ['stay', false],
    ]);

    it('changes the fields when the choice changes, and leaves nothing behind', function () {
        ($this->set)(['condition' => 'intent', 'intent' => 'comprar', 'min_confidence' => 0.9, 'action' => 'go_to', 'stage' => 'venta']);
        expect(fruleRules($this->page))->toBe([['when' => ['intent' => 'comprar', 'min_confidence' => 0.9], 'then' => ['action' => 'go_to', 'stage' => 'venta']]]);

        $this->page->set('data.stages.'.fruleStageKey($this->page, 1).'.rules.r.condition', 'plan_named');
        $this->page->set('data.stages.'.fruleStageKey($this->page, 1).'.rules.r.action', 'stay');

        $this->page->assertFormFieldHidden(frulePath($this->page, 'intent'))
            ->assertFormFieldHidden(frulePath($this->page, 'min_confidence'))
            ->assertFormFieldHidden(frulePath($this->page, 'stage'));

        expect(fruleRules($this->page))->toBe([['when' => ['plan_named' => true], 'then' => ['action' => 'stay']]]);
    });
});

describe('numbers', function () {
    it('gives interactions as an integer, however the field holds them', function (mixed $given) {
        $this->versions->publish($this->product, fruleDefinition());
        $page = frulePage($this->product);

        fruleSet($page, ['r' => ['condition' => 'interactions_reached', 'interactions' => $given, 'action' => 'stay']]);

        expect(fruleRules($page)[0]['when']['interactions_reached'])->toBe(3);
    })->with([[3], [3.0], ['3'], ['3.0']]);

    it('keeps the other integer fields of the editor working through the mapper alone', function () {
        $this->versions->publish($this->product, fruleDefinition());
        $page = frulePage($this->product);

        $page->fillForm(['free_replies.limit' => '4', 'stages.'.fruleStageKey($page, 1).'.max_interactions' => '6', 'stages.'.fruleStageKey($page, 1).'.on_limit' => 'venta']);

        $definition = $page->instance()->definition();

        expect($definition['free_replies']['limit'])->toBe(4)
            ->and($definition['stages'][1]['max_interactions'])->toBe(6);

        FunnelDefinition::fromArray($definition);
    });
});

describe('invalid rules', function () {
    beforeEach(function () {
        $this->versions->publish($this->product, fruleDefinition());
        $this->page = frulePage($this->product);
        $this->path = fn (string $field) => 'stages.'.fruleStageKey($this->page, 1).'.rules.r.'.$field;
    });

    it('is refused in the panel\'s words', function (array $rule, string $field, string $message) {
        fruleSet($this->page, ['r' => $rule]);

        fruleCheck($this->page)
            ->assertHasFormErrors([($this->path)($field)])
            ->assertSee($message, false);
    })->with([
        'no condition' => [['action' => 'stay'], 'condition', 'Elige la condición de la regla.'],
        'no action' => [['condition' => 'any_reply'], 'action', 'Elige la acción de la regla.'],
        'an unknown condition' => [['condition' => 'otra', 'action' => 'stay'], 'condition', 'Ya no existe'],
        'an unknown action' => [['condition' => 'any_reply', 'action' => 'otra'], 'action', 'Ya no existe'],
        'no phrases' => [['condition' => 'phrase', 'phrases' => [], 'action' => 'stay'], 'phrases', 'Escribe al menos una frase.'],
        'a phrase with nothing to compare' => [['condition' => 'phrase', 'phrases' => ['¡¿?!'], 'action' => 'stay'], 'phrases', 'no dice nada que comparar'],
        'no intent' => [['condition' => 'intent', 'action' => 'stay'], 'intent', 'Elige una intención declarada'],
        'an undeclared intent' => [['condition' => 'intent', 'intent' => 'otra', 'action' => 'stay'], 'intent', 'Ya no existe'],
        'a confidence above 1' => [['condition' => 'intent', 'intent' => 'comprar', 'min_confidence' => 1.5, 'action' => 'stay'], 'min_confidence', 'Indica un número de 0 a 1.'],
        'a negative confidence' => [['condition' => 'intent', 'intent' => 'comprar', 'min_confidence' => -0.1, 'action' => 'stay'], 'min_confidence', 'Indica un número de 0 a 1.'],
        'no interactions' => [['condition' => 'interactions_reached', 'action' => 'stay'], 'interactions', 'Indica cuántas interacciones.'],
        'zero interactions' => [['condition' => 'interactions_reached', 'interactions' => 0, 'action' => 'stay'], 'interactions', 'Indica un número de 1 en adelante.'],
        'fractional interactions' => [['condition' => 'interactions_reached', 'interactions' => 2.5, 'action' => 'stay'], 'interactions', 'Indica un número entero.'],
        'go_to without a stage' => [['condition' => 'any_reply', 'action' => 'go_to'], 'stage', 'Elige la etapa de destino.'],
        'a stage that does not exist' => [['condition' => 'any_reply', 'action' => 'go_to', 'stage' => 'nada'], 'stage', 'Ya no existe'],
        'an offer going to a stage that does not exist' => [['condition' => 'any_reply', 'action' => 'show_offer', 'stage' => 'nada'], 'stage', 'Ya no existe'],
    ]);
});

describe('references', function () {
    beforeEach(function () {
        $this->versions->publish($this->product, fruleDefinition([
            ['when' => ['intent' => 'comprar'], 'then' => ['action' => 'go_to', 'stage' => 'venta']],
        ]));
        $this->page = frulePage($this->product);
    });

    it('offers only the declared intents and existing stages, also the ones just added', function () {
        $intents = $this->page->instance()->data['intents'];
        $intents['nueva'] = ['key' => 'cancelar', 'description' => 'No quiere seguir'];
        $stages = $this->page->instance()->data['stages'];
        $stages['nueva'] = ['key' => 'cierre', 'type' => 'ai', 'goal' => 'g', 'instructions' => 'i'];

        $intentPath = frulePath($this->page, 'intent');
        $stagePath = frulePath($this->page, 'stage');

        $this->page->set('data.intents', $intents)->set('data.stages', $stages);

        expect(fruleOptions($this->page, $intentPath))->toBe(['comprar', 'dudar', 'cancelar'])
            ->and(fruleOptions($this->page, $stagePath))->toBe(['bienvenida', 'dudas', 'venta', 'cierre']);
    });

    it('catches a rule whose intent was removed, in the panel\'s words', function () {
        $intentPath = frulePath($this->page, 'intent');

        $this->page->set('data.intents', ['solo' => ['key' => 'dudar', 'description' => 'Tiene dudas']]);

        fruleCheck($this->page)->assertHasFormErrors([$intentPath])->assertSee('Ya no existe');
    });

    it('catches a rule whose stage was removed, in the panel\'s words', function () {
        $stagePath = frulePath($this->page, 'stage');
        $stages = $this->page->instance()->data['stages'];
        unset($stages[fruleStageKey($this->page, 2)]);

        $this->page->set('data.free_replies.on_exhausted', null)->set('data.stages', $stages);

        fruleCheck($this->page)->assertHasFormErrors([$stagePath])->assertSee('Ya no existe');
    });
});

describe('order', function () {
    it('keeps the rules as arranged', function () {
        $this->versions->publish($this->product, fruleDefinition([
            ['when' => ['phrase' => ['Lo quiero']], 'then' => ['action' => 'stay']],
            ['when' => ['plan_named' => true], 'then' => ['action' => 'stay']],
            ['when' => ['any_reply' => true], 'then' => ['action' => 'stay']],
        ]));
        $page = frulePage($this->product);
        $rules = $page->instance()->data['stages'][fruleStageKey($page, 1)]['rules'];

        expect(array_map(fn ($rule) => array_key_first($rule['when']), fruleRules($page)))->toBe(['phrase', 'plan_named', 'any_reply']);

        fruleSet($page, array_reverse($rules, true));

        expect(array_map(fn ($rule) => array_key_first($rule['when']), fruleRules($page)))->toBe(['any_reply', 'plan_named', 'phrase']);
    });

    it('adds a rule at the end', function () {
        $this->versions->publish($this->product, fruleDefinition([['when' => ['plan_named' => true], 'then' => ['action' => 'stay']]]));
        $page = frulePage($this->product);
        $rules = $page->instance()->data['stages'][fruleStageKey($page, 1)]['rules'];
        $rules['nueva'] = ['condition' => 'any_reply', 'action' => 'stay'];

        fruleSet($page, $rules);

        expect(array_map(fn ($rule) => array_key_first($rule['when']), fruleRules($page)))->toBe(['plan_named', 'any_reply']);
    });

    it('removes a rule', function () {
        $this->versions->publish($this->product, fruleDefinition([['when' => ['plan_named' => true], 'then' => ['action' => 'stay']]]));
        $page = frulePage($this->product);

        fruleSet($page, []);

        expect($page->instance()->definition()['stages'][1])->not->toHaveKey('rules');
    });
});

describe('message stages', function () {
    beforeEach(function () {
        $this->versions->publish($this->product, fruleDefinition([['when' => ['any_reply' => true], 'then' => ['action' => 'stay']]]));
        $this->page = frulePage($this->product);
    });

    it('do not offer rules', function () {
        $this->page->assertFormFieldHidden('stages.'.fruleStageKey($this->page, 0).'.rules')
            ->assertFormFieldVisible('stages.'.fruleStageKey($this->page, 1).'.rules');
    });

    it('drop the rules of a stage that becomes a message', function () {
        $dudas = fruleStageKey($this->page, 1);

        $this->page->set("data.stages.{$dudas}.type", 'message')
            ->set("data.stages.{$dudas}.text", 'Ahora mensaje')
            ->set("data.stages.{$dudas}.next", 'venta');

        expect($this->page->instance()->definition()['stages'][1])->not->toHaveKey('rules');

        FunnelDefinition::fromArray($this->page->instance()->definition());
    });

    it('can receive rules once they become an AI stage', function () {
        $bienvenida = fruleStageKey($this->page, 0);

        $this->page->set("data.stages.{$bienvenida}.type", 'ai')
            ->set("data.stages.{$bienvenida}.goal", 'g')
            ->set("data.stages.{$bienvenida}.instructions", 'i')
            ->set("data.stages.{$bienvenida}.rules", ['r' => ['condition' => 'plan_named', 'action' => 'start_payment']]);

        expect($this->page->instance()->definition()['stages'][0]['rules'])->toBe([['when' => ['plan_named' => true], 'then' => ['action' => 'start_payment']]]);
    });
});

describe('isolation and storage', function () {
    it('answers 404 on the rules of a foreign product', function () {
        $this->versions->publish($this->foreignProduct, fruleDefinition([['when' => ['plan_named' => true], 'then' => ['action' => 'stay']]]));

        $this->get(ProductResource::getUrl('funnel-edit', ['record' => $this->foreignProduct]))->assertNotFound();
        $this->get(ProductResource::getUrl('funnel-edit', ['record' => $this->foreignProduct, 'from' => 1]))->assertNotFound();
    });

    it('does not bring in the intents or stages of another product', function () {
        $this->versions->publish($this->foreignProduct, fruleDefinition(overrides: ['intents' => [['key' => 'ajena', 'description' => 'x']]]));
        $this->versions->publish($this->product, fruleDefinition([['when' => ['intent' => 'comprar'], 'then' => ['action' => 'go_to', 'stage' => 'venta']]]));

        $page = frulePage($this->product);

        expect(fruleOptions($page, frulePath($page, 'intent')))->toBe(['comprar', 'dudar']);
    });

    it('stores nothing while editing rules', function () {
        $this->versions->publish($this->product, fruleDefinition());
        $total = SalesFunnel::count();
        $active = $this->product->fresh()->active_sales_funnel_id;

        $writes = [];
        DB::listen(function ($query) use (&$writes) {
            if (! str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
                $writes[] = $query->sql;
            }
        });

        $page = frulePage($this->product);
        fruleSet($page, ['r' => ['condition' => 'plan_named', 'action' => 'start_payment']]);
        fruleCheck($page)->assertNotified('La definición es válida.');

        expect($writes)->toBe([])
            ->and(SalesFunnel::count())->toBe($total)
            ->and($this->product->fresh()->active_sales_funnel_id)->toBe($active)
            ->and($this->product->fresh()->activeFunnel()->definition['stages'][1]['rules'] ?? [])->toBe([]);
    });

});
