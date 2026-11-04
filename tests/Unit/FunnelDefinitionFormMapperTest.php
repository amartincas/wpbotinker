<?php

/*
 * A funnel's definition to a form's state and back, without losing anything
 * the contract reads. engine_definitions.json holds the valid definitions
 * that the engine's and persistence's tests publish.
 */

use App\Filament\Resources\Products\Schemas\FunnelDefinitionFormMapper as Mapper;
use App\Platform\Funnel\FunnelDefinition;

/** Same content and types, whatever the order of a map's keys: lists keep theirs. */
function fmSorted(array $value): array
{
    if (! array_is_list($value)) {
        ksort($value);
    }

    return array_map(fn (mixed $item) => is_array($item) ? fmSorted($item) : $item, $value);
}

/** The contract reads an empty list or a null like an absent key, and so does the mapper. */
function fmWithoutEmpty(array $value): array
{
    $kept = array_filter($value, fn (mixed $item) => $item !== null && $item !== []);

    return array_map(fn (mixed $item) => is_array($item) ? fmWithoutEmpty($item) : $item, array_is_list($value) ? $value : $kept);
}

function fmRoundTrip(array $definition): array
{
    return Mapper::toDefinition(Mapper::toFormState($definition));
}

function fmComplete(): array
{
    return [
        'name' => 'Embudo completo',
        'goal' => 'Vender el servicio',
        'start_stage' => 'bienvenida',
        'offer_cta' => 'Escribe QUIERO',
        'free_replies' => ['limit' => 3, 'on_exhausted' => 'venta'],
        'opening_consumes_free_reply' => false,
        'intents' => [
            ['key' => 'comprar', 'description' => 'Quiere adquirir'],
            ['key' => 'objecion', 'description' => 'Duda del valor'],
        ],
        'stages' => [
            ['key' => 'bienvenida', 'type' => 'message', 'text' => "Hola {producto}\n\n{planes}", 'uses_free_replies' => true, 'next' => 'dudas'],
            [
                'key' => 'dudas',
                'type' => 'ai',
                'goal' => 'Resolver dudas',
                'instructions' => "Responde breve.\nUna idea por mensaje.",
                'restrictions' => 'No prometas resultados.',
                'context' => ['description', 'faq', 'topic', 'commercial_resources', 'sample_content'],
                'uses_free_replies' => true,
                'max_interactions' => 5,
                'on_limit' => 'venta',
                'rules' => [
                    ['when' => ['phrase' => ['Lo quiero', 'Me apunto']], 'then' => ['action' => 'start_payment', 'stage' => 'venta']],
                    ['when' => ['plan_named' => true], 'then' => ['action' => 'start_payment']],
                    ['when' => ['intent' => 'comprar', 'min_confidence' => 0.9], 'then' => ['action' => 'show_offer', 'stage' => 'venta']],
                    ['when' => ['intent' => 'objecion'], 'then' => ['action' => 'stay']],
                    ['when' => ['interactions_reached' => 3], 'then' => ['action' => 'go_to', 'stage' => 'venta']],
                    ['when' => ['free_replies_exhausted' => true], 'then' => ['action' => 'show_offer']],
                    ['when' => ['any_reply' => true], 'then' => ['action' => 'stay']],
                ],
            ],
            ['key' => 'venta', 'type' => 'ai', 'goal' => 'Cerrar', 'instructions' => 'Invita a pagar.'],
        ],
    ];
}

dataset('engine definitions', function () {
    $definitions = json_decode(file_get_contents(__DIR__.'/../Support/Funnels/engine_definitions.json'), true);

    foreach ($definitions as $index => $definition) {
        yield "definition {$index}" => [$definition];
    }
});

describe('the engine\'s definitions', function () {
    it('are real reference cases', function () {
        $definitions = json_decode(file_get_contents(__DIR__.'/../Support/Funnels/engine_definitions.json'), true);
        $encoded = json_encode($definitions);

        expect(count($definitions))->toBeGreaterThan(20);

        // interactions_reached appears in no test of the engine: 'supports each condition' covers it here.
        foreach (['"message"', '"ai"', '"phrase"', '"plan_named"', '"intent"', '"any_reply"', '"free_replies_exhausted"', '"go_to"', '"show_offer"', '"start_payment"', '"stay"'] as $used) {
            expect($encoded)->toContain($used);
        }
    });

    it('come back with the same content and types', function (array $definition) {
        expect(fmSorted(fmRoundTrip($definition)))->toBe(fmSorted(fmWithoutEmpty($definition)));
    })->with('engine definitions');

    it('read the same for the contract', function (array $definition) {
        expect(FunnelDefinition::fromArray(fmRoundTrip($definition)))->toEqual(FunnelDefinition::fromArray($definition));
    })->with('engine definitions');

    it('do not change on a second round trip', function (array $definition) {
        expect(fmRoundTrip(fmRoundTrip($definition)))->toBe(fmRoundTrip($definition));
    })->with('engine definitions');
});

describe('a definition with every option', function () {
    it('comes back exactly and passes the contract', function () {
        $definition = fmComplete();

        expect(fmSorted(fmRoundTrip($definition)))->toBe(fmSorted($definition))
            ->and(FunnelDefinition::fromArray(fmRoundTrip($definition)))->toEqual(FunnelDefinition::fromArray($definition));
    });

    it('gives a form its fields, a rule\'s condition and value apart', function () {
        $state = Mapper::toFormState(fmComplete());
        $rules = $state['stages'][1]['rules'];

        expect($state['name'])->toBe('Embudo completo')
            ->and($state['start_stage'])->toBe('bienvenida')
            ->and($state['offer_cta'])->toBe('Escribe QUIERO')
            ->and($state['free_replies'])->toBe(['limit' => 3, 'on_exhausted' => 'venta'])
            ->and($state['opening_consumes_free_reply'])->toBeFalse()
            ->and($state['intents'][1])->toBe(['key' => 'objecion', 'description' => 'Duda del valor'])
            ->and($state['stages'][0]['type'])->toBe('message')
            ->and($state['stages'][0]['next'])->toBe('dudas')
            ->and($state['stages'][1]['type'])->toBe('ai')
            ->and($state['stages'][1]['max_interactions'])->toBe(5)
            ->and($rules[0])->toBe(['condition' => 'phrase', 'phrases' => ['Lo quiero', 'Me apunto'], 'intent' => null, 'interactions' => null, 'min_confidence' => null, 'action' => 'start_payment', 'stage' => 'venta'])
            ->and($rules[2])->toBe(['condition' => 'intent', 'phrases' => [], 'intent' => 'comprar', 'interactions' => null, 'min_confidence' => 0.9, 'action' => 'show_offer', 'stage' => 'venta'])
            ->and($rules[4]['interactions'])->toBe(3)
            ->and(array_column($rules, 'condition'))->toBe(['phrase', 'plan_named', 'intent', 'intent', 'interactions_reached', 'free_replies_exhausted', 'any_reply']);
    });

    it('supports each condition with each action', function (string $condition, mixed $value) {
        foreach (['go_to', 'show_offer', 'start_payment', 'stay'] as $action) {
            $definition = fmComplete();
            $definition['stages'][1]['rules'] = [['when' => [$condition => $value], 'then' => ['action' => $action, 'stage' => 'venta']]];

            expect(fmSorted(fmRoundTrip($definition)))->toBe(fmSorted($definition))
                ->and(FunnelDefinition::fromArray(fmRoundTrip($definition)))->toEqual(FunnelDefinition::fromArray($definition));
        }
    })->with([
        'phrase' => ['phrase', ['Lo quiero']],
        'plan_named' => ['plan_named', true],
        'intent' => ['intent', 'comprar'],
        'any_reply' => ['any_reply', true],
        'interactions_reached' => ['interactions_reached', 4],
        'free_replies_exhausted' => ['free_replies_exhausted', true],
    ]);

    it('keeps what the contract accepts even where the engine does not use it', function () {
        $definition = fmComplete();
        $definition['stages'][0]['rules'] = [['when' => ['any_reply' => true], 'then' => ['action' => 'stay']]];
        $definition['stages'][0]['goal'] = 'Objetivo de un mensaje';
        $definition['stages'][1]['next'] = 'venta';
        $definition['stages'][1]['rules'][1]['when']['min_confidence'] = 0.5;
        $definition['stages'][1]['rules'][3]['then']['stage'] = 'venta';

        expect(fmSorted(fmRoundTrip($definition)))->toBe(fmSorted($definition));
    });
});

describe('absent and empty values', function () {
    it('leaves a minimal definition minimal', function () {
        $definition = ['name' => 'Mínimo', 'goal' => 'Vender', 'stages' => [['key' => 'a', 'type' => 'ai', 'goal' => 'x', 'instructions' => 'y']]];

        $state = Mapper::toFormState($definition);

        expect(fmRoundTrip($definition))->toBe($definition)
            ->and($state['start_stage'])->toBeNull()
            ->and($state['offer_cta'])->toBeNull()
            ->and($state['free_replies'])->toBe(['limit' => null, 'on_exhausted' => null])
            ->and($state['opening_consumes_free_reply'])->toBeNull()
            ->and($state['intents'])->toBe([])
            ->and($state['stages'][0]['context'])->toBe([])
            ->and($state['stages'][0]['rules'])->toBe([])
            ->and($state['stages'][0]['uses_free_replies'])->toBeNull();
    });

    it('turns what a form leaves empty into absent', function () {
        $state = Mapper::toFormState(fmComplete());
        $state['start_stage'] = '';
        $state['offer_cta'] = '   ';
        $state['free_replies']['on_exhausted'] = '';
        $state['stages'][1]['restrictions'] = '';
        $state['stages'][1]['context'] = [];
        $state['stages'][1]['max_interactions'] = '';
        $state['stages'][1]['on_limit'] = '';
        $state['stages'][1]['rules'][0]['stage'] = '';
        $state['stages'][1]['rules'][2]['min_confidence'] = '';

        $definition = Mapper::toDefinition($state);

        expect($definition)->not->toHaveKeys(['start_stage', 'offer_cta'])
            ->and($definition['free_replies'])->toBe(['limit' => 3])
            ->and($definition['stages'][1])->not->toHaveKeys(['restrictions', 'context', 'max_interactions', 'on_limit'])
            ->and($definition['stages'][1]['rules'][0]['then'])->toBe(['action' => 'start_payment'])
            ->and($definition['stages'][1]['rules'][2]['when'])->toBe(['intent' => 'comprar']);

        FunnelDefinition::fromArray($definition);
    });

    it('reads the same when a definition spells out empty values', function () {
        $explicit = [
            'name' => 'Explícito', 'goal' => 'Vender', 'start_stage' => null, 'offer_cta' => null, 'intents' => [],
            'free_replies' => ['limit' => 0, 'on_exhausted' => null],
            'stages' => [['key' => 'a', 'type' => 'ai', 'goal' => 'x', 'instructions' => 'y', 'restrictions' => '', 'context' => [], 'max_interactions' => null, 'on_limit' => null, 'next' => null, 'rules' => []]],
        ];

        expect(fmRoundTrip($explicit))->toBe([
            'name' => 'Explícito', 'goal' => 'Vender', 'free_replies' => ['limit' => 0],
            'stages' => [['key' => 'a', 'type' => 'ai', 'goal' => 'x', 'instructions' => 'y']],
        ])->and(FunnelDefinition::fromArray(fmRoundTrip($explicit)))->toEqual(FunnelDefinition::fromArray($explicit));
    });

    it('keeps false and zero, which are values', function () {
        $definition = fmComplete();
        $definition['free_replies']['limit'] = 0;
        unset($definition['free_replies']['on_exhausted']);
        $definition['opening_consumes_free_reply'] = true;
        $definition['stages'][0]['uses_free_replies'] = false;
        $definition['stages'][1]['uses_free_replies'] = false;
        $definition['stages'][1]['rules'][2]['when']['min_confidence'] = 0;

        $result = fmRoundTrip($definition);

        expect($result['free_replies'])->toBe(['limit' => 0])
            ->and($result['opening_consumes_free_reply'])->toBeTrue()
            ->and($result['stages'][0]['uses_free_replies'])->toBeFalse()
            ->and($result['stages'][1]['rules'][2]['when']['min_confidence'])->toBe(0);
    });

    it('works with a state missing the fields a form hid', function () {
        $definition = Mapper::toDefinition([
            'name' => 'Oculto', 'goal' => 'Vender',
            'stages' => [
                ['key' => 'a', 'type' => 'ai', 'goal' => 'x', 'instructions' => 'y', 'rules' => [['condition' => 'any_reply', 'action' => 'stay']]],
                ['key' => 'b', 'type' => 'message', 'text' => 'Hola', 'next' => 'a'],
            ],
        ]);

        expect($definition['stages'][0]['rules'])->toBe([['when' => ['any_reply' => true], 'then' => ['action' => 'stay']]]);

        FunnelDefinition::fromArray($definition);
    });
});

describe('types a form gives as text', function () {
    it('turns whole numbers into integers', function () {
        $state = Mapper::toFormState(fmComplete());
        $state['free_replies']['limit'] = '4';
        $state['stages'][1]['max_interactions'] = ' 7 ';
        $state['stages'][1]['rules'][4]['interactions'] = '2';

        $definition = Mapper::toDefinition($state);

        expect($definition['free_replies']['limit'])->toBe(4)
            ->and($definition['stages'][1]['max_interactions'])->toBe(7)
            ->and($definition['stages'][1]['rules'][4]['when']['interactions_reached'])->toBe(2);

        FunnelDefinition::fromArray($definition);
    });

    it('keeps a limit of zero given as text', function () {
        $state = Mapper::toFormState(['name' => 'x', 'goal' => 'y', 'stages' => [['key' => 'a', 'type' => 'ai', 'goal' => 'x', 'instructions' => 'y']]]);
        $state['free_replies']['limit'] = '0';

        expect(Mapper::toDefinition($state)['free_replies'])->toBe(['limit' => 0]);
    });

    it('turns a confidence into a number', function () {
        $state = Mapper::toFormState(fmComplete());
        $state['stages'][1]['rules'][2]['min_confidence'] = '0.75';

        expect(Mapper::toDefinition($state)['stages'][1]['rules'][2]['when']['min_confidence'])->toBe(0.75);
    });

    it('reads booleans, also the text the contract would take as true', function (mixed $given, bool $expected) {
        $state = Mapper::toFormState(fmComplete());
        $state['opening_consumes_free_reply'] = $given;
        $state['stages'][0]['uses_free_replies'] = $given;

        $definition = Mapper::toDefinition($state);

        expect($definition['opening_consumes_free_reply'])->toBe($expected)
            ->and($definition['stages'][0]['uses_free_replies'])->toBe($expected);
    })->with([
        [true, true], [false, false], [1, true], [0, false], ['1', true], ['0', false], ['true', true], ['false', false],
    ]);
});

describe('lists', function () {
    it('keeps the order of stages, intents, rules, phrases and context', function () {
        $definition = fmComplete();
        $definition['stages'] = array_reverse($definition['stages']);
        $definition['intents'] = array_reverse($definition['intents']);
        $definition['stages'][1]['rules'] = array_reverse($definition['stages'][1]['rules']);
        $definition['stages'][1]['context'] = array_reverse($definition['stages'][1]['context']);
        $last = count($definition['stages'][1]['rules']) - 1;
        $definition['stages'][1]['rules'][$last]['when']['phrase'] = ['Me apunto', 'Lo quiero'];

        $result = fmRoundTrip($definition);

        expect(array_column($result['stages'], 'key'))->toBe(['venta', 'dudas', 'bienvenida'])
            ->and(array_column($result['intents'], 'key'))->toBe(['objecion', 'comprar'])
            ->and($result['stages'][1]['context'])->toBe($definition['stages'][1]['context'])
            ->and($result['stages'][1]['rules'][$last]['when']['phrase'])->toBe(['Me apunto', 'Lo quiero'])
            ->and(fmSorted($result))->toBe(fmSorted($definition));
    });

    it('turns a repeater\'s keyed items into lists, in the order they came', function () {
        $state = Mapper::toFormState(fmComplete());
        $state['stages'] = ['uuid-c' => $state['stages'][0], 'uuid-a' => $state['stages'][1], 'uuid-b' => $state['stages'][2]];
        $state['intents'] = ['uuid-z' => $state['intents'][0], 'uuid-y' => $state['intents'][1]];
        $state['stages']['uuid-a']['rules'] = ['uuid-r' => $state['stages']['uuid-a']['rules'][0], 'uuid-q' => $state['stages']['uuid-a']['rules'][1]];

        $definition = Mapper::toDefinition($state);

        expect(array_is_list($definition['stages']))->toBeTrue()
            ->and(array_is_list($definition['intents']))->toBeTrue()
            ->and(array_column($definition['stages'], 'key'))->toBe(['bienvenida', 'dudas', 'venta'])
            ->and(array_column($definition['stages'][1]['rules'], 'then')[1])->toBe(['action' => 'start_payment']);

        FunnelDefinition::fromArray($definition);
    });

    it('drops blank items a form leaves in a list', function () {
        $state = Mapper::toFormState(fmComplete());
        $state['stages'][1]['rules'][0]['phrases'] = ['Lo quiero', '', '  ', 'Me apunto'];

        expect(Mapper::toDefinition($state)['stages'][1]['rules'][0]['when']['phrase'])->toBe(['Lo quiero', 'Me apunto']);
    });
});

describe('what must stay untouched', function () {
    it('never alters keys, destinations or texts', function () {
        $definition = [
            'name' => '  Nombre con espacios  ',
            'goal' => "Objetivo\ncon saltos ",
            'start_stage' => 'Etapa Uno ',
            'intents' => [['key' => 'comprar_ya', 'description' => ' Descripción ']],
            'free_replies' => ['limit' => 1, 'on_exhausted' => 'Etapa-2'],
            'stages' => [
                ['key' => 'Etapa Uno ', 'type' => 'message', 'text' => "  Texto\n\n con {planes}  ", 'next' => 'Etapa-2'],
                ['key' => 'Etapa-2', 'type' => 'ai', 'goal' => ' g ', 'instructions' => "\ni\n", 'restrictions' => ' r ', 'rules' => [['when' => ['phrase' => [' Lo Quiero ']], 'then' => ['action' => 'go_to', 'stage' => 'Etapa Uno ']]]],
            ],
        ];

        expect(fmSorted(fmRoundTrip($definition)))->toBe(fmSorted($definition));
    });

    it('leaves what it cannot normalize for the contract to reject', function (callable $change, string $message) {
        $state = Mapper::toFormState(fmComplete());
        $change($state);

        expect(fn () => FunnelDefinition::fromArray(Mapper::toDefinition($state)))->toThrow(InvalidArgumentException::class, $message);
    })->with([
        'a limit that is no number' => [function (array &$state) {
            $state['free_replies']['limit'] = 'tres';
        }, 'free_replies.limit'],
        'a fractional limit' => [function (array &$state) {
            $state['free_replies']['limit'] = '1.5';
        }, 'free_replies.limit'],
        'an empty name' => [function (array &$state) {
            $state['name'] = ' ';
        }, 'needs a name'],
        'an empty goal' => [function (array &$state) {
            $state['goal'] = '';
        }, 'needs a goal'],
        'a stage without a key' => [function (array &$state) {
            $state['stages'][0]['key'] = '';
        }, 'needs a key'],
        'an unknown destination' => [function (array &$state) {
            $state['stages'][1]['rules'][0]['stage'] = 'nada';
        }, 'does not exist'],
        'a rule without a condition' => [function (array &$state) {
            $state['stages'][1]['rules'][0]['condition'] = null;
        }, 'exactly one supported condition'],
        'a rule with an unknown condition' => [function (array &$state) {
            $state['stages'][1]['rules'][0]['condition'] = 'otra';
        }, 'exactly one supported condition'],
        'a phrase rule without phrases' => [function (array &$state) {
            $state['stages'][1]['rules'][0]['phrases'] = ['', ' '];
        }, 'must be a list'],
        'a rule without an action' => [function (array &$state) {
            $state['stages'][1]['rules'][0]['action'] = '';
        }, 'unsupported action'],
        'a go_to without a destination' => [function (array &$state) {
            $state['stages'][1]['rules'][4]['stage'] = '';
        }, 'needs its target stage'],
        'a confidence out of range' => [function (array &$state) {
            $state['stages'][1]['rules'][2]['min_confidence'] = '1.5';
        }, 'min_confidence'],
        'a message without text' => [function (array &$state) {
            $state['stages'][0]['text'] = '';
        }, 'needs its text'],
    ]);

    it('does not keep keys outside the contract', function () {
        $definition = fmComplete();
        $definition['ajena'] = 'x';
        $definition['stages'][1]['ajena'] = 'x';

        expect(fmSorted(fmRoundTrip($definition)))->toBe(fmSorted(fmComplete()));
    });

    it('does not depend on Filament', function () {
        $source = file_get_contents((new ReflectionClass(Mapper::class))->getFileName());

        expect($source)->not->toContain('use Filament');
    });
});

describe('whole numbers from a numeric field', function () {
    /** The state of a definition with one integer field set to the given value. */
    $fields = [
        'free_replies.limit' => [
            fn (array &$state, mixed $value) => $state['free_replies']['limit'] = $value,
            fn (array $definition) => $definition['free_replies']['limit'] ?? null,
            'free_replies.limit',
        ],
        'max_interactions' => [
            fn (array &$state, mixed $value) => $state['stages'][1]['max_interactions'] = $value,
            fn (array $definition) => $definition['stages'][1]['max_interactions'] ?? null,
            'max_interactions',
        ],
        'interactions_reached' => [
            fn (array &$state, mixed $value) => $state['stages'][1]['rules'][4]['interactions'] = $value,
            fn (array $definition) => $definition['stages'][1]['rules'][4]['when']['interactions_reached'] ?? null,
            'interactions_reached',
        ],
    ];

    foreach ($fields as $name => [$set, $read, $message]) {
        it("turns into an integer in {$name}", function (mixed $given) use ($set, $read) {
            $state = Mapper::toFormState(fmComplete());
            $set($state, $given);

            $definition = Mapper::toDefinition($state);

            expect($read($definition))->toBe(2);

            FunnelDefinition::fromArray($definition);
        })->with([
            'an integer' => [2],
            'a whole float' => [2.0],
            'text' => ['2'],
            'text with spaces' => [' 2 '],
            'whole decimal text' => ['2.0'],
            'whole decimal text with more zeros' => ['2.00'],
        ]);

        it("leaves for the contract to reject in {$name}", function (mixed $given) use ($set, $read, $message) {
            $state = Mapper::toFormState(fmComplete());
            $set($state, $given);

            $definition = Mapper::toDefinition($state);

            // Never truncated: it stays as it came.
            if (is_float($given) && is_nan($given)) {
                expect(is_nan($read($definition)))->toBeTrue();
            } else {
                expect($read($definition))->toBe($given);
            }

            expect(fn () => FunnelDefinition::fromArray($definition))->toThrow(InvalidArgumentException::class, $message);
        })->with([
            'a fractional float' => [2.5],
            'fractional text' => ['2.5'],
            'text past any integer' => ['99999999999999999999'],
            'decimal text past any integer' => ['99999999999999999999.0'],
            'infinite text' => ['1e999'],
            'a whole float too large to be exact' => [9007199254740994.0],
            'not a number' => [NAN],
            'text that is no number' => ['dos'],
            'mixed text' => ['2a'],
            'an infinite float' => [INF],
            'a float beyond any integer' => [1.0e30],
            'a list' => [[2]],
            'a boolean' => [true],
        ]);
    }

    it('keeps zero, from any of its forms', function (mixed $given) {
        $state = Mapper::toFormState(fmComplete());
        $state['free_replies']['limit'] = $given;
        foreach ($state['stages'] as $index => $stage) {
            $state['stages'][$index]['uses_free_replies'] = false;
        }

        $definition = Mapper::toDefinition($state);

        expect($definition['free_replies']['limit'])->toBe(0);

        FunnelDefinition::fromArray($definition);
    })->with([[0], [0.0], ['0']]);

    it('reads an empty field as absent, never as zero', function () {
        $state = Mapper::toFormState(fmComplete());
        $state['free_replies']['limit'] = '';
        $state['stages'][1]['max_interactions'] = null;

        $definition = Mapper::toDefinition($state);

        expect($definition['free_replies'])->not->toHaveKey('limit')
            ->and($definition['stages'][1])->not->toHaveKey('max_interactions');
    });

    it('leaves the other numeric value of a rule as it was', function () {
        $state = Mapper::toFormState(fmComplete());
        $state['stages'][1]['rules'][2]['min_confidence'] = 0.5;

        expect(Mapper::toDefinition($state)['stages'][1]['rules'][2]['when']['min_confidence'])->toBe(0.5);
    });
});
