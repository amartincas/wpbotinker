<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Domain modules
    |--------------------------------------------------------------------------
    |
    | Domains available to stores in 'knowledge' conversation mode, as
    | key => class implementing App\Platform\Contracts\DomainModule. A product
    | joins a domain through products.domain_key. Adding a domain is a module
    | class plus one line here — no change to App\Platform.
    |
    */

    'domains' => [],

    /*
    |--------------------------------------------------------------------------
    | Person facts
    |--------------------------------------------------------------------------
    |
    | Facts about the customer that any domain may reference (and, with the
    | customer's permission, share). Defined once here so every domain gives
    | them the same meaning. Types: date, time, text, number. Sensitive facts
    | get stricter handling (never shown in full outside the conversation).
    |
    */

    'person_facts' => [
        'name' => [
            'type' => 'text',
            'question' => '¿Cómo te llamas?',
            'sensitive' => false,
        ],
        'birth_date' => [
            'type' => 'date',
            'question' => '¿Cuál es tu fecha de nacimiento? (día/mes/año)',
            'sensitive' => true,
        ],
    ],

];
