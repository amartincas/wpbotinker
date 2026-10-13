<?php

/*
 * The Core catalog of person facts and the validation of its basic types.
 */

use App\Platform\Memory\PersonFacts;

function personCatalog(): PersonFacts
{
    return new PersonFacts([
        'birth_date' => ['type' => 'date', 'question' => '¿Fecha?', 'sensitive' => true],
        'birth_time' => ['type' => 'time', 'question' => '¿Hora?'],
        'nickname' => ['type' => 'text', 'question' => '¿Apodo?'],
        'children' => ['type' => 'number', 'question' => '¿Cuántos hijos?'],
    ]);
}

it('ships a catalog with name and birth date, both validated by the Core', function () {
    $catalog = app(PersonFacts::class);

    expect($catalog->keys())->toBe(['name', 'birth_date'])
        ->and($catalog->type('birth_date'))->toBe('date')
        ->and($catalog->isSensitive('birth_date'))->toBeTrue()
        ->and($catalog->isSensitive('name'))->toBeFalse()
        ->and($catalog->question('birth_date'))->toContain('fecha de nacimiento');
});

it('normalizes valid dates to Y-m-d', function (string $input, string $expected) {
    $result = personCatalog()->validate('birth_date', $input);

    expect($result->valid)->toBeTrue()->and($result->value)->toBe($expected);
})->with([
    ['15/03/1990', '1990-03-15'],
    ['5-3-1990', '1990-03-05'],
    [' 15.03.1990 ', '1990-03-15'],
    ['1990-03-15', '1990-03-15'],
    ['29/02/2024', '2024-02-29'],
]);

it('rejects invalid dates without echoing the input', function (string $input) {
    $result = personCatalog()->validate('birth_date', $input);

    expect($result->valid)->toBeFalse()
        ->and($result->value)->toBeNull()
        ->and($result->reason)->not->toBeEmpty()
        ->and($result->reason)->not->toContain($input);
})->with(['31/02/1990', '29/02/2023', 'ayer', '15/03/90', '1990/13/40']);

it('rejects an empty date', function () {
    expect(personCatalog()->validate('birth_date', '   ')->valid)->toBeFalse();
});

it('normalizes valid times to 24h H:i', function (string $input, string $expected) {
    $result = personCatalog()->validate('birth_time', $input);

    expect($result->valid)->toBeTrue()->and($result->value)->toBe($expected);
})->with([
    ['14:30', '14:30'],
    ['2:30 pm', '14:30'],
    ['2.30 p.m.', '14:30'],
    ['12:05 am', '00:05'],
    ['12:05 pm', '12:05'],
    ['0:00', '00:00'],
]);

it('rejects invalid times', function (string $input) {
    expect(personCatalog()->validate('birth_time', $input)->valid)->toBeFalse();
})->with(['25:00', '14:60', '13:00 pm', '0:30 am', 'mediodía', '1430']);

it('accepts trimmed text up to 255 characters', function () {
    $catalog = personCatalog();

    expect($catalog->validate('nickname', '  Lu  ')->value)->toBe('Lu')
        ->and($catalog->validate('nickname', '   ')->valid)->toBeFalse()
        ->and($catalog->validate('nickname', str_repeat('a', 256))->valid)->toBeFalse();
});

it('accepts plain numbers and rejects ambiguous thousands separators', function () {
    $catalog = personCatalog();

    expect($catalog->validate('children', '2')->value)->toBe(2)
        ->and($catalog->validate('children', '2,5')->value)->toBe(2.5)
        ->and($catalog->validate('children', '1.500.000')->valid)->toBeFalse()
        ->and($catalog->validate('children', 'dos')->valid)->toBeFalse();
});

it('fails loudly on an unknown key or a malformed catalog entry', function () {
    $catalog = new PersonFacts([
        'bad_type' => ['type' => 'color', 'question' => '¿?'],
        'no_question' => ['type' => 'text'],
    ]);

    expect(fn () => $catalog->validate('missing', 'x'))->toThrow(InvalidArgumentException::class, 'Unknown person fact [missing]')
        ->and(fn () => $catalog->type('bad_type'))->toThrow(InvalidArgumentException::class, 'invalid type')
        ->and(fn () => $catalog->question('no_question'))->toThrow(InvalidArgumentException::class, 'needs a question');
});
