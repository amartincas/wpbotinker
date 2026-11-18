<?php

/*
 * A product's optional audio opening: its opening text, then its audio as
 * WhatsApp's own audio message, then its follow-up text, once per product
 * and conversation. WhatsApp and the model are simulated: these tests prove
 * what is sent, in which order of sending, and what is recorded, never how
 * a phone shows it.
 */

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\ConversationFunnelState;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Product;
use App\Models\SampleDelivery;
use App\Models\Store;
use App\Models\Topic;
use App\Models\WhatsAppMessage;
use App\Platform\Commerce\OpeningAudio;
use App\Platform\Conversation\ConversationPipeline;
use App\Platform\Funnel\GuidedFunnel;
use App\Platform\Funnel\SalesFunnelVersions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

const OA_PHONE = '573004445566';

const OA_OPUS = "OggS\x00\x02\x00\x00\x00\x00\x00\x00\x00\x00\x01\x02\x03\x04\x00\x00\x00\x00\x00\x00\x00\x00\x01\x13OpusHead\x01\x02";

beforeEach(function () {
    Storage::fake(OpeningAudio::DISK);
    $this->freezeSecond();

    // What fails, one switch per kind of send.
    $this->down = ['text' => false, 'upload' => false, 'audio' => false, 'followup' => false];
    $this->texts = 0;

    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'api.openai.com')) {
            return Http::response(['choices' => [['message' => ['content' => json_encode(['reply' => 'RESPUESTA-DEL-TURNO', 'intent' => null, 'confidence' => 0, 'question' => 'comercial'])]]]]);
        }

        if (str_ends_with($request->url(), '/media')) {
            return $this->down['upload'] ? Http::response(['error' => 'upload'], 500) : Http::response(['id' => 'media-'.Str::random(6)]);
        }

        $type = $request->data()['type'] ?? null;

        if ($type === 'audio') {
            return $this->down['audio'] ? Http::response(['error' => 'audio'], 400) : Http::response(['messages' => [['id' => 'wamid.audio.'.Str::random(6)]]]);
        }

        // The first text is the opening; a later one, the follow-up.
        $failing = $this->texts++ === 0 ? $this->down['text'] : $this->down['followup'];

        return $failing ? Http::response(['error' => 'down'], 500) : Http::response(['messages' => [['id' => 'wamid.out.'.Str::random(8)]]]);
    });

    $this->store = makeStore(['conversation_mode' => Store::MODE_KNOWLEDGE, 'welcome_message' => 'BIENVENIDA-TIENDA', 'payment_instructions' => 'INSTRUCCIONES-PAGO']);
    Topic::create(['store_id' => $this->store->id, 'key' => 'tema', 'label' => 'Tema', 'instructions' => 'x']);
    $this->product = oaProduct('Asesor', 'tema');
    $this->versions = app(SalesFunnelVersions::class);
    $this->versions->publish($this->product, GuidedFunnel::definition('INSTRUCCION', 1));
});

function oaProduct(string $name, string $domain, array $overrides = []): Product
{
    $product = Product::create(array_merge([
        'store_id' => test()->store->id, 'domain_key' => $domain, 'name' => $name, 'description' => 'd', 'price' => 1, 'stock' => 1,
        'access_mode' => Product::ACCESS_SUBSCRIPTION, 'opening_message' => "APERTURA-{$name}",
    ], $overrides));
    Plan::create(['product_id' => $product->id, 'name' => 'Semanal', 'price' => 9900, 'duration_days' => 7, 'sort_order' => 1]);

    return $product;
}

function oaAudio(Product $product, string $bytes = OA_OPUS, ?string $followup = 'POSTERIOR de {producto}'): string
{
    $path = OpeningAudio::DIRECTORY.'/'.Str::random(8).'.ogg';
    Storage::disk(OpeningAudio::DISK)->put($path, $bytes);
    $product->update(['opening_audio_enabled' => true, 'opening_audio_path' => $path, 'opening_audio_followup' => $followup]);

    return $path;
}

function oaSay(?string $body, ?int $adProduct = null, ?string $wamid = null): void
{
    ProcessWhatsAppMessage::dispatchSync(test()->store->fresh(), OA_PHONE, $body, $wamid ?? 'wamid.in.'.Str::random(10), 'text', null, $adProduct);
    test()->travel(1)->seconds();
}

/** @return list<array{type: string, body: ?string, audio: ?array}> what went to WhatsApp, in the order it was sent */
function oaSent(): array
{
    return Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/messages'))
        ->map(fn ($pair) => [
            'type' => $pair[0]->data()['type'],
            'body' => $pair[0]->data()['text']['body'] ?? $pair[0]->data()['interactive']['body']['text'] ?? null,
            'audio' => $pair[0]->data()['audio'] ?? null,
        ])->values()->all();
}

function oaLastBody(): ?string
{
    $sent = oaSent();

    return end($sent)['body'];
}

function oaUploads(): array
{
    return Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/media'))->values()->all();
}

function oaHistory(): array
{
    return WhatsAppMessage::where('customer_phone', OA_PHONE)->orderBy('id')->get(['role', 'content'])->map(fn ($m) => [$m->role, $m->content])->all();
}

describe('the audio file', function () {
    it('reads what the file is from its own bytes, not its name', function (string $bytes, ?string $type) {
        $path = tempnam(sys_get_temp_dir(), 'oa');
        file_put_contents($path, $bytes);

        expect(OpeningAudio::mimeType($path))->toBe($type);

        unlink($path);
    })->with([
        'OGG con Opus' => [OA_OPUS, 'audio/ogg'],
        'OGG con Vorbis' => ["OggS\x00\x02".str_repeat("\x00", 22)."\x01vorbis", null],
        'MP3 con etiqueta' => ["ID3\x04\x00\x00\x00\x00\x00\x00", 'audio/mpeg'],
        'MP3 sin etiqueta' => ["\xFF\xFB\x90\x64\x00\x00", 'audio/mpeg'],
        'AAC (ADTS)' => ["\xFF\xF1\x50\x80\x00\x1F", 'audio/aac'],
        'M4A' => ["\x00\x00\x00\x20ftypM4A \x00\x00\x00\x00", 'audio/mp4'],
        'AMR' => ["#!AMR\n\x3C\x00", 'audio/amr'],
        'AMR-WB' => ["#!AMR-WB\n\x00", null],
        'WAV' => ["RIFF\x24\x00\x00\x00WAVEfmt ", null],
        'texto con nombre de audio' => ['esto no es un audio', null],
        'vacío' => ['', null],
    ]);

    it('deletes the previous file when it is replaced or removed, and with its product', function () {
        $first = oaAudio($this->product);
        $second = oaAudio($this->product);

        expect(Storage::disk(OpeningAudio::DISK)->exists($first))->toBeFalse()->and(Storage::disk(OpeningAudio::DISK)->exists($second))->toBeTrue();

        // Turned off, the file stays for when it is turned on again.
        $this->product->update(['opening_audio_enabled' => false]);
        expect(Storage::disk(OpeningAudio::DISK)->exists($second))->toBeTrue();

        $this->product->update(['opening_audio_path' => null]);
        expect(Storage::disk(OpeningAudio::DISK)->exists($second))->toBeFalse();

        $third = oaAudio($this->product);
        $this->product->delete();
        expect(Storage::disk(OpeningAudio::DISK)->exists($third))->toBeFalse();
    });

    it('is off for every product until it is turned on', function () {
        expect(Product::find($this->product->id)->opening_audio_enabled)->toBeFalse()
            ->and(app(OpeningAudio::class)->for($this->product))->toBeNull();
    });
});

describe('the opening with its audio', function () {
    it('changes nothing for a product without the audio turned on', function () {
        $this->product->update(['opening_audio_followup' => 'NO-SE-ENVIA']);

        oaSay('Hola', $this->product->id);

        expect(oaSent())->toHaveCount(1)->and(oaSent()[0]['body'])->toBe('APERTURA-Asesor')
            ->and(oaUploads())->toBe([])
            ->and(oaHistory())->toBe([['user', 'Hola'], ['assistant', 'APERTURA-Asesor']]);
    });

    it('sends the opening, its audio and its follow-up, in that order, without waiting for the customer', function () {
        oaAudio($this->product);

        oaSay('Hola! Quiero conocer el co', $this->product->id);

        $sent = oaSent();

        expect(array_column($sent, 'type'))->toBe(['text', 'audio', 'text'])
            ->and($sent[0]['body'])->toBe('APERTURA-Asesor')->not->toContain('BIENVENIDA-TIENDA')
            // WhatsApp's own audio, by its media id: no link, no document, no caption.
            ->and($sent[1]['audio'])->toHaveKey('id')->not->toHaveKey('link')->not->toHaveKey('caption')
            ->and($sent[2]['body'])->toBe('POSTERIOR de Asesor')
            ->and(oaUploads())->toHaveCount(1)
            ->and(oaHistory())->toBe([
                ['user', 'Hola! Quiero conocer el co'],
                ['assistant', 'APERTURA-Asesor'],
                ['assistant', ConversationPipeline::OPENING_AUDIO_HISTORY],
                ['assistant', 'POSTERIOR de Asesor'],
            ])
            // Every message WhatsApp took is tracked by its id.
            ->and(WhatsAppMessage::where('role', 'assistant')->whereNull('wamid')->count())->toBe(0)
            ->and(ConversationFunnelState::where('product_id', $this->product->id)->exists())->toBeTrue();
    });

    it('fills {producto} and [Planes] in the follow-up with the official plans, and counts them as shown', function () {
        oaAudio($this->product, followup: "Elige tu plan de {producto}:\n\n[Planes]");

        oaSay('Hola', $this->product->id);

        expect(oaLastBody())->toContain('Elige tu plan de Asesor')->toContain('1. Semanal: $9.900 COP por 7 días')
            ->and(\App\Models\Conversation::sole()->offers_shown)->toHaveKey((string) $this->product->id);
    });

    it('sends no follow-up when none is configured', function () {
        oaAudio($this->product, followup: null);

        oaSay('Hola', $this->product->id);

        expect(array_column(oaSent(), 'type'))->toBe(['text', 'audio']);
    });

    it('presents the product the message identifies, with its own audio, in a store with several products of different topics', function () {
        Topic::create(['store_id' => $this->store->id, 'key' => 'otro', 'label' => 'Otro', 'instructions' => 'x']);
        $other = oaProduct('Cocina', 'otro');
        $this->versions->publish($other, GuidedFunnel::definition('INSTRUCCION', 1));
        oaAudio($this->product, followup: 'POSTERIOR-ASESOR');
        $otherPath = oaAudio($other, "ID3\x04\x00\x00\x00\x00\x00\x00", 'POSTERIOR-COCINA');

        oaSay('Quiero saber de Cocina');

        expect(array_column(oaSent(), 'body'))->toBe(['APERTURA-Cocina', null, 'POSTERIOR-COCINA'])
            ->and(ConversationFunnelState::where('product_id', $other->id)->exists())->toBeTrue()
            ->and(ConversationFunnelState::where('product_id', $this->product->id)->exists())->toBeFalse()
            // The file sent is that product's, with its own type.
            ->and(oaUploads()[0][0]->body())->toContain(basename($otherPath))->toContain('audio/mpeg');
    });

    it('never presents again: neither on later messages nor when the same message is processed again', function () {
        oaAudio($this->product);
        oaSay('Hola', $this->product->id, 'wamid.in.mismo');

        oaSay('Hola', $this->product->id, 'wamid.in.mismo');
        oaSay('¿Cómo funciona?');

        expect(collect(oaSent())->where('type', 'audio'))->toHaveCount(1)
            ->and(collect(oaSent())->where('body', 'APERTURA-Asesor'))->toHaveCount(1)
            ->and(collect(oaSent())->where('body', 'POSTERIOR de Asesor'))->toHaveCount(1);
    });

    it('lets a first message that asks to buy or for the plans go first, without the opening nor its audio', function (string $message) {
        oaAudio($this->product);

        oaSay($message, $this->product->id);

        expect(collect(oaSent())->where('type', 'audio'))->toBeEmpty()->and(collect(oaSent())->where('body', 'APERTURA-Asesor'))->toBeEmpty();
    })->with(['compra' => ['Quiero mi acceso'], 'oferta' => ['Ver opciones'], 'precios' => ['¿Cuánto cuesta?']]);

    it('does not present the product with its payment already open', function () {
        oaAudio($this->product);
        \App\Models\Conversation::create(['store_id' => $this->store->id, 'customer_phone' => OA_PHONE, 'last_session_at' => now(), 'current_product_id' => $this->product->id]);
        ProcessWhatsAppMessage::dispatchSync($this->store->fresh(), OA_PHONE, 'Semanal', 'wamid.in.'.Str::random(10), 'interactive', null, null, null, 'plan:'.Plan::where('product_id', $this->product->id)->value('id'));
        $this->travel(1)->seconds();
        expect(Payment::sole()->status)->toBe(Payment::STATUS_PENDING);

        oaSay('¿El Asesor llega por WhatsApp?');

        expect(collect(oaSent())->where('type', 'audio'))->toBeEmpty()->and(Payment::count())->toBe(1);
    });

    it('spends no free reply for the opening of a guided funnel, nor for its audio', function () {
        oaAudio($this->product);

        oaSay('Hola', $this->product->id);

        expect(SampleDelivery::where('product_id', $this->product->id)->value('turns_used'))->toBeNull();
    });

    it('spends one free reply, and only one, for the whole opening of a product without a funnel', function () {
        $this->versions->deactivate($this->product);
        oaAudio($this->product);

        oaSay('Hola', $this->product->id);

        expect(array_column(oaSent(), 'type'))->toBe(['text', 'audio', 'text'])
            ->and(oaSent()[0]['body'])->toStartWith('APERTURA-Asesor')
            ->and(SampleDelivery::where('product_id', $this->product->id)->value('turns_used'))->toBe(1);
    });

    it('presents with the product\'s current configuration whatever version of the funnel is active, and the version keeps a copy of it', function () {
        $this->versions->publish($this->product, GuidedFunnel::definition('INSTRUCCION-2', 1));
        oaAudio($this->product, followup: 'POSTERIOR-ACTUAL');
        $v3 = $this->versions->publish($this->product, GuidedFunnel::definition('INSTRUCCION-3', 1));

        oaSay('Hola', $this->product->id);

        expect(oaLastBody())->toBe('POSTERIOR-ACTUAL')
            ->and($v3->product_snapshot)->toMatchArray(['opening_audio_enabled' => true, 'opening_audio_followup' => 'POSTERIOR-ACTUAL'])
            // The first version kept the product as it was then, without any audio.
            ->and($this->versions->history($this->product)->last()->product_snapshot)->toMatchArray(['opening_audio_followup' => null]);
    });

    it('still presents, with its audio, when the active version was published before these fields existed', function () {
        $active = $this->versions->active($this->product);
        $legacy = $active->product_snapshot;
        unset($legacy['opening_audio_enabled'], $legacy['opening_audio_followup']);
        \App\Models\SalesFunnel::whereKey($active->id)->update(['product_snapshot' => json_encode($legacy)]);
        oaAudio($this->product);

        oaSay('Hola', $this->product->id);

        expect(array_column(oaSent(), 'type'))->toBe(['text', 'audio', 'text']);
    });
});

describe('when a send fails', function () {
    it('fails the job when the opening did not go out, with nothing sent after it, and the retry sends the whole opening once', function () {
        oaAudio($this->product);
        $this->down['text'] = true;

        expect(fn () => oaSay('Hola', $this->product->id, 'wamid.in.reintento'))->toThrow(RuntimeException::class)
            ->and(collect(oaSent())->where('type', 'audio'))->toBeEmpty()
            ->and(ConversationFunnelState::count())->toBe(0);

        $this->down['text'] = false;
        oaSay('Hola', $this->product->id, 'wamid.in.reintento');

        expect(collect(oaSent())->where('type', 'audio'))->toHaveCount(1)
            ->and(collect(oaSent())->where('body', 'POSTERIOR de Asesor'))->toHaveCount(1);
    });

    it('logs a failed audio and still sends the follow-up, without failing the job', function (string $failing) {
        Log::spy();
        oaAudio($this->product);
        $this->down[$failing] = true;

        oaSay('Hola', $this->product->id);

        expect(oaLastBody())->toBe('POSTERIOR de Asesor')
            ->and(oaHistory())->not->toContain(['assistant', ConversationPipeline::OPENING_AUDIO_HISTORY])
            ->and(ConversationFunnelState::where('product_id', $this->product->id)->exists())->toBeTrue();
        Log::shouldHaveReceived('warning')->with('OPENING_AUDIO_FAILED', Mockery::on(fn (array $context) => $context['reason'] === 'send' && ! in_array(OA_PHONE, $context, true)));
    })->with(['la subida a Meta' => ['upload'], 'el envío del audio' => ['audio']]);

    it('logs an audio WhatsApp could not play, or a missing file, and still sends the follow-up', function (Closure $setUp) {
        Log::spy();
        $setUp->call($this);

        oaSay('Hola', $this->product->id);

        expect(oaUploads())->toBe([])->and(oaLastBody())->toBe('POSTERIOR de Asesor');
        Log::shouldHaveReceived('warning')->with('OPENING_AUDIO_FAILED', Mockery::on(fn (array $context) => $context['reason'] === 'file'));
    })->with([
        'OGG sin Opus' => [fn () => oaAudio($this->product, "OggS\x00\x02".str_repeat("\x00", 22)."\x01vorbis")],
        'archivo borrado' => [fn () => Storage::disk(OpeningAudio::DISK)->delete(oaAudio($this->product))],
        'sin archivo' => [fn () => $this->product->update(['opening_audio_enabled' => true, 'opening_audio_followup' => 'POSTERIOR de {producto}'])],
    ]);

    it('logs a failed follow-up, keeps the audio, and never sends the opening again', function () {
        Log::spy();
        oaAudio($this->product);
        $this->down['followup'] = true;

        oaSay('Hola', $this->product->id, 'wamid.in.uno');
        // WhatsApp is back for the same message processed again: still nothing of the opening twice.
        $this->down['followup'] = false;
        oaSay('Hola', $this->product->id, 'wamid.in.uno');

        expect(collect(oaSent())->where('body', 'APERTURA-Asesor'))->toHaveCount(1)
            ->and(collect(oaSent())->where('type', 'audio'))->toHaveCount(1)
            ->and(oaHistory())->toContain(['assistant', ConversationPipeline::OPENING_AUDIO_HISTORY])->not->toContain(['assistant', 'POSTERIOR de Asesor']);
        Log::shouldHaveReceived('warning')->with('OPENING_FOLLOWUP_FAILED', Mockery::any());
    });
});

describe('the panel', function () {
    beforeEach(function () {
        $this->actingAs(\App\Models\User::factory()->create(['store_id' => $this->store->id, 'is_super_admin' => false]));
        $this->edit = fn () => \Livewire\Livewire::test(\App\Filament\Resources\Products\Pages\EditProduct::class, ['record' => $this->product->getRouteKey()]);
    });

    it('shows the audio and its follow-up only once the audio is turned on', function () {
        ($this->edit)()
            ->assertFormFieldExists('opening_audio_enabled')
            ->assertFormFieldHidden('opening_audio_path')->assertFormFieldHidden('opening_audio_followup')
            ->fillForm(['opening_audio_enabled' => true])
            ->assertFormFieldVisible('opening_audio_path')->assertFormFieldVisible('opening_audio_followup');
    });

    it('saves an audio WhatsApp plays in private storage, with its follow-up', function () {
        ($this->edit)()
            ->fillForm([
                'opening_audio_enabled' => true,
                'opening_audio_path' => \Illuminate\Http\UploadedFile::fake()->createWithContent('presentacion.ogg', OA_OPUS),
                'opening_audio_followup' => 'POSTERIOR',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $product = $this->product->fresh();

        expect($product->opening_audio_enabled)->toBeTrue()->and($product->opening_audio_followup)->toBe('POSTERIOR')
            ->and($product->opening_audio_path)->toStartWith(OpeningAudio::DIRECTORY.'/')
            ->and(Storage::disk(OpeningAudio::DISK)->exists($product->opening_audio_path))->toBeTrue()
            ->and(Storage::disk('public')->exists($product->opening_audio_path))->toBeFalse();
    });

    it('refuses a file WhatsApp would not play as audio, whatever its name, and an audio turned on without a file', function (array $data) {
        ($this->edit)()
            ->fillForm(['opening_audio_enabled' => true] + $data)
            ->call('save')
            ->assertHasFormErrors(['opening_audio_path']);

        expect($this->product->fresh()->opening_audio_enabled)->toBeFalse();
    })->with([
        'OGG sin Opus' => [['opening_audio_path' => \Illuminate\Http\UploadedFile::fake()->createWithContent('voz.ogg', "OggS\x00\x02".str_repeat("\x00", 22)."\x01vorbis")]],
        'texto con nombre de audio' => [['opening_audio_path' => \Illuminate\Http\UploadedFile::fake()->createWithContent('voz.mp3', 'esto no es un audio')]],
        'sin archivo' => [[]],
    ]);

    it('replaces the audio and deletes the previous file', function () {
        $previous = oaAudio($this->product);

        // As the panel does: the file shown is removed, then the new one uploaded in its place.
        ($this->edit)()
            ->fillForm(['opening_audio_path' => []])
            ->fillForm(['opening_audio_path' => \Illuminate\Http\UploadedFile::fake()->createWithContent('nuevo.mp3', "ID3\x04\x00\x00\x00\x00\x00\x00")])
            ->call('save')
            ->assertHasNoFormErrors();

        $current = $this->product->fresh()->opening_audio_path;

        expect($current)->not->toBe($previous)->toEndWith('.mp3')
            ->and(Storage::disk(OpeningAudio::DISK)->exists($previous))->toBeFalse()
            ->and(Storage::disk(OpeningAudio::DISK)->exists($current))->toBeTrue();
    });
});

describe('what the review checked', function () {
    it('shows the plans once when both the opening and its follow-up ask for them', function () {
        $this->product->update(['opening_message' => "APERTURA\n\n[Planes]"]);
        oaAudio($this->product, followup: "Elige el tuyo:\n\n[Planes]");

        oaSay('Hola', $this->product->id);

        $bodies = array_column(oaSent(), 'body');

        expect(substr_count(implode("\n", array_filter($bodies)), '1. Semanal: $9.900 COP por 7 días'))->toBe(1)
            ->and($bodies[0])->toContain('1. Semanal: $9.900 COP por 7 días')
            // The configured follow-up keeps its words; where it asked for the plans, how to see them again.
            ->and(oaLastBody())->toBe("Elige el tuyo:\n\nEscribe «planes» para ver los precios o «pagar» para suscribirte.")
            ->and($this->product->fresh()->opening_audio_followup)->toBe("Elige el tuyo:\n\n[Planes]");
    });

    it('sends neither the opening nor its audio for an open product, which has no opening', function () {
        $open = Product::create(['store_id' => $this->store->id, 'domain_key' => 'tema', 'name' => 'Abierto', 'description' => 'd', 'price' => 1, 'stock' => 1,
            'access_mode' => Product::ACCESS_OPEN, 'opening_message' => 'APERTURA-ABIERTO']);
        oaAudio($open);

        oaSay('Hola', $open->id);

        expect(app(OpeningAudio::class)->for($open->fresh()))->toBeNull()
            ->and(oaUploads())->toBe([])->and(collect(oaSent())->where('type', 'audio'))->toBeEmpty()
            ->and(collect(oaSent())->where('body', 'APERTURA-ABIERTO'))->toBeEmpty();
    });

    it('lets the model go on after the opening, knowing an audio went out but not what it says', function () {
        oaAudio($this->product);
        oaSay('Hola', $this->product->id);

        // The whole opening, its audio included, calls no model.
        expect(mainChatRequests())->toBe([]);

        oaSay('¿Cómo funciona?');

        $chat = mainChatRequests()[0]->data()['messages'];

        expect(oaLastBody())->toStartWith('RESPUESTA-DEL-TURNO')
            ->and(collect($chat)->pluck('content'))->toContain(ConversationPipeline::OPENING_AUDIO_HISTORY)->toContain('POSTERIOR de Asesor');
    });
});
