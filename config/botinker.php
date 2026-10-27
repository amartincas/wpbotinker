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
            'label' => 'nombre',
            'question' => '¿Cómo te llamas?',
            'sensitive' => false,
        ],
        'birth_date' => [
            'type' => 'date',
            'label' => 'fecha de nacimiento',
            'question' => '¿Cuál es tu fecha de nacimiento? (día/mes/año)',
            'sensitive' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Free interactions
    |--------------------------------------------------------------------------
    |
    | Every subscription product gives a customer without access up to `max`
    | free replies before the offer; the offer (plans, price, how to pay)
    | follows the last one. A product's sample instructions only adapt them
    | to its domain: they cannot change this number. 0 disables them.
    |
    | The plan list is shown unasked at most once every
    | `offer_interval_hours` per conversation and product; asking for
    | prices, plans or to pay always shows it.
    |
    | `strategy` is the conversational strategy given to the model: `all`
    | on every free reply, then `first`, `middle` or `last` by position
    | (with a single free reply, only `last`). Generic, for every domain.
    |
    */

    'free_interactions' => [
        'max' => 3,
        'offer_interval_hours' => 24,
        'strategy' => [
            'all' => 'El cliente aún no está suscrito y recibe pocas respuestas gratuitas: cada una debe aportarle valor concreto sobre lo que pregunta, sin entregar el servicio completo. '
                .'Sé breve, es un mensaje de WhatsApp. No conviertas la respuesta en un discurso de ventas. '
                .'Haz como máximo una pregunta, relacionada con su consulta; no repitas preguntas que ya hiciste ni pidas datos innecesarios. '
                .'No menciones precios, planes ni formas de pago: el sistema los agrega cuando corresponde.',
            'first' => 'Responde directamente a su consulta con información concreta y un consejo, ejemplo o recomendación que pueda aplicar ya. '
                .'Termina con una sola pregunta específica y fácil de responder que te permita conocer mejor su situación.',
            'middle' => 'Usa lo que el cliente te contó para adaptar el consejo a su caso: da una recomendación práctica, un ejercicio o el siguiente paso. '
                .'Termina con una invitación concreta a seguir: que pruebe la recomendación y te cuente cómo le fue, o que te describa un detalle de su situación.',
            'last' => 'Es su última respuesta gratuita. Da una respuesta útil y pertinente. '
                .'Después, en una o dos frases, explica qué podría conseguir con el servicio completo a partir de lo que te contó, sin prometer resultados garantizados. '
                .'No termines con una pregunta: el sistema agrega a continuación la oferta, el precio y cómo suscribirse.',
        ],
    ],

];
