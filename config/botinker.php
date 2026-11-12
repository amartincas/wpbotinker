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

    /*
    |--------------------------------------------------------------------------
    | Sales funnels
    |--------------------------------------------------------------------------
    |
    | How the model converses in any funnel's stage, for every product and
    | domain. What to sell and how is each funnel's configuration, not this.
    |
    */

    'sales_funnels' => [
        'conversation_rules' => 'Conversa como un asesor humano, no como un formulario ni un guion. '
            .'El objetivo de la etapa orienta la conversación, pero no es una secuencia de preguntas. '
            .'Responde primero lo que el cliente pregunta o la objeción que plantea, y después retoma el objetivo cuando sea natural. '
            .'Acepta interrupciones y cambios de tema sin perder el hilo. '
            .'Usa lo que ya se habló: no repitas preguntas que el cliente ya respondió ni información que ya le diste. '
            .'Haz como máximo una pregunta por mensaje. Sé breve: es un mensaje de WhatsApp.',

        /*
        | What surrounds an administrator's single instruction in a guided
        | funnel (App\Platform\Funnel\GuidedFunnel). Generic, for every domain:
        | the way of selling is that instruction, never these texts. Each
        | published version keeps the texts it was published with.
        */
        'guided' => [
            'name' => 'Instrucción comercial',
            'goal' => 'Convertir al prospecto en suscriptor del producto mediante una conversación natural.',
            'stage_goal' => 'Entender qué necesita la persona, resolver sus preguntas y objeciones, y orientarla hacia la suscripción cuando sea el momento.',
            'intents' => [
                'comprar' => 'El cliente decidió comprar o activar el servicio, o pide empezar ya, con las palabras que sea. No incluye preguntas sobre el precio ni sobre cómo funciona.',
                'presentar_oferta' => 'Este es el momento de mostrarle los planes: el cliente los pide, o ya entendió cómo le ayuda el servicio y conviene presentárselos. '
                    .'El sistema los agrega después de tu respuesta, con sus precios. Si ya los vio en esta conversación y no los vuelve a pedir, no la elijas.',
            ],
            // Every message of a guided funnel is first a commercial turn: only the Core gives a free reply, to a request for content.
            'commercial_turn' => 'Este es un turno comercial: sigue el PROCESO COMERCIAL con tu tono habitual y usa la conversación anterior para no repetir lo que ya dijiste ni lo que el cliente ya contó. '
                .'En este mensaje no resuelvas peticiones del contenido del servicio (consejos, técnicas, ejemplos, ejercicios o ayuda con su situación concreta): el sistema decide si puede dárselas. '
                .'Si el cliente pide eso, en "reply", en máximo tres frases cortas y :characters caracteres: empieza con un reconocimiento muy breve (por ejemplo, «Entiendo» o «Te entiendo»), '
                .'explica en una frase que eso se trabaja con el servicio completo y cierra invitándolo sin presión a conocer los planes o activar su suscripción; '
                .'no des técnicas, pasos, ejemplos, ejercicios ni instrucciones prácticas, ni siquiera una pista, y no uses listas ni frases entre comillas. '
                .'No inventes importes, beneficios ni condiciones. '
                .'Si su último mensaje es solo un saludo, un agradecimiento o una cortesía, respóndelo con naturalidad y en pocas palabras, sin ofrecer los planes ni pedirle que escriba algo. '
                .'Elige una intención solo si su último mensaje pide ver los planes o decide comprar con claridad; un «bueno», un «ok» o un «gracias» no es una decisión de compra. En cualquier otro caso usa null. '
                .'En "question" clasifica siempre su último mensaje: "comercial" si pregunta por el producto como compra (qué incluye, cómo funciona, duración, condiciones, pagos o planes), plantea una duda u objeción sobre comprarlo o decide comprar; '
                .'"contenido" si pide el tema del servicio en sí (consejos, técnicas, ejemplos o ayuda con su situación); "cortesia" si es solo un saludo, un agradecimiento, una despedida o una cortesía; null solo si no se puede saber.',
            // What a greeting or a thanks gets when no reply of the model may go out. Generic, for every domain.
            'courtesy_reply' => 'Con gusto. Aquí estoy si quieres saber algo más del servicio.',
            'limit_reply' => 'Hasta aquí llegan tus respuestas gratuitas. Gracias por conversar. Si quieres seguir con el servicio completo, puedes activar tu suscripción cuando lo decidas.',

            // Checks the facts a commercial turn's own wording states against the approved ones
            // (CommercialAdvisor::checkClaims()). Only observed and logged for now: it never blocks.
            'claims_check' => 'Eres un verificador. Recibes la respuesta que un asistente comercial dio a un cliente y los HECHOS APROBADOS del producto. '
                .'Identifica en la respuesta solo las afirmaciones de hecho sobre el producto o el servicio: qué incluye, funciones, características, condiciones, resultados, garantías, reembolsos, duración o disponibilidad. '
                .'No cuentes como hechos la empatía, las opiniones, las invitaciones, las preguntas ni los recursos persuasivos. '
                .'Una afirmación está respaldada solo si los HECHOS APROBADOS la dicen o la implican claramente. '
                .'La INSTRUCCIÓN COMERCIAL dice cómo vender: sus indicaciones (por ejemplo, qué destacar o cómo argumentar) no prueban que algo exista; solo cuentan como respaldo los hechos que afirma explícitamente sobre el producto. '
                .'Responde EXCLUSIVAMENTE con un JSON, sin texto adicional: {"claims": [{"text": "la afirmación", "supported": true o false}]}; usa una lista vacía si no hay afirmaciones de hecho.',

            // A commercial question that no verbatim commercial resource answers.
            'undocumented_reply' => 'No tengo información confirmada sobre esa condición específica y prefiero no darte una respuesta incorrecta. Si quieres, puedes consultar los planes disponibles para conocer las condiciones del servicio.',

            // Model calls of the commercial turns, per conversation and product (see commercial_turn).
            'after_limit_reads' => 5,

            // What the Core requires of the commercial turn's reply to a request for content it may not answer. Generic, for every domain.
            'after_limit_rules' => [
                'max_characters' => 400,
                'max_sentences' => 4,
                'max_service_characters' => 100,
                'max_acknowledgment_characters' => 50,
                'commercial_terms' => ['plan', 'planes', 'suscripcion', 'suscribirte', 'suscribete', 'activar', 'activa', 'acceso', 'opciones'],
                'service_terms' => ['servicio'], // and the product's whole name or aliases, without listing them here
                'acknowledgments' => ['entiendo', 'te entiendo', 'comprendo', 'te comprendo', 'buena pregunta', 'que buena pregunta', 'tiene sentido', 'es normal', 'es comun', 'es muy comun'],
                'courtesies' => ['si quieres', 'si te interesa', 'si lo deseas', 'si gustas', 'cuando quieras', 'cuando lo decidas', 'sin presion', 'sin presiones', 'sin afan', 'con calma'],
                // A guarantee or a refund stated as a fact: only the approved material may give it (commercialWordingViolation()).
                'claim_terms' => [
                    'garantia', 'garantias', 'garantizamos', 'garantizo', 'garantizado', 'garantizada', 'garantiza',
                    'reembolso', 'reembolsamos', 'reembolsar', 'devolucion', 'devolvemos', 'te devuelvo', 'te devolvemos',
                ],
                'instruction_terms' => [
                    'aprenderas a', 'te enseno', 'te ensenare', 'sabras como',
                    'intenta', 'prueba', 'haz', 'usa', 'utiliza', 'practica', 'aplica', 'evita', 'observa', 'fijate', 'dile', 'preguntale', 'escribele',
                    'empieza por', 'comienza por', 'debes', 'deberias', 'tienes que', 'te recomiendo', 'te sugiero', 'te aconsejo', 'lo mejor es',
                    'la clave es', 'el secreto es', 'el truco', 'la tecnica', 'consiste en', 'por ejemplo', 'puedes decir', 'puedes probar',
                    'paso a paso', 'primer paso', 'sigue estos', 'primero',
                ],
            ],

            // Whole messages the Core serves in a guided funnel, without the model, whatever reads are left.
            'purchase_phrases' => [
                'Quiero mi acceso', 'Quiero acceso', 'Quiero el acceso', 'Quiero activar mi acceso', 'Activar mi acceso', 'Activa mi acceso',
                'Quiero empezar', 'Quiero comenzar', 'Quiero empezar ya', 'Me animo', 'Lo quiero', 'Me apunto', 'Quiero contratar',
                'Quiero entrar', 'Estoy listo para comenzar', 'Estoy listo para empezar', 'Quiero tener acceso', 'Dame acceso', 'Quiero acceder',
            ],
            'offer_phrases' => [
                'Opciones', 'Ver opciones', 'Las opciones', 'Qué opciones hay', 'Cuáles son las opciones', 'Quiero ver las opciones', 'Muéstrame las opciones',
                'Muéstrame otra vez las opciones', 'Muéstrame otra vez las opciones que hay',
            ],

            /*
            | The automatic reactivation (App\Jobs\SendProspectReactivation).
            | A refusal is the customer's whole last message and only cancels
            | the current episode; an objection ("lo voy a pensar") is not one.
            | A stop request is also the whole message, greetings and courtesies
            | aside (inside a longer one it may quote someone or concern another
            | service), and ends automatic reactivations for that conversation
            | for good.
            */
            'decline_phrases' => [
                'No gracias', 'No me interesa', 'Ya no me interesa', 'No estoy interesado', 'No estoy interesada',
                'No quiero', 'Ya no quiero', 'No lo quiero', 'No lo necesito', 'No necesito nada',
            ],
            'stop_phrases' => [
                'No me escribas más', 'No me escriban más', 'No me vuelvas a escribir', 'No me vuelvan a escribir',
                'Deja de escribirme', 'Dejen de escribirme', 'Deja de enviarme mensajes', 'Dejen de enviarme mensajes',
                'No me envíes más mensajes', 'No me envíen más mensajes', 'No me mandes más mensajes', 'No me manden más mensajes',
                'No quiero recibir más mensajes', 'No me contactes más', 'No me contacten más',
                'Dame de baja', 'Denme de baja', 'Darme de baja', 'Stop',
            ],
        ],
    ],

];
