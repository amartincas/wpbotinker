<?php

/*
 * Retry behavior of ProcessWhatsAppMessage on the real database queue.
 */

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Product;
use App\Models\WhatsAppMessage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    config(['queue.default' => 'database']);
    $this->store = makeStore();
    Product::create(['store_id' => $this->store->id, 'name' => 'Crema Facial', 'description' => 'Crema', 'price' => 45000, 'stock' => 10]);
});

function workOnce($test): void
{
    $test->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0, '--stop-when-empty' => true]);
}

it('still answers a message that waited several times for the previous one of the same customer', function () {
    fakeExternalApis('Respuesta al segundo mensaje');

    // Another message of the same customer is still being processed.
    $lock = Cache::lock('laravel-queue-overlap:'.ProcessWhatsAppMessage::class.":whatsapp-message:{$this->store->id}:573001112233", 120);
    $lock->get();

    ProcessWhatsAppMessage::dispatch($this->store, '573001112233', '¿y el envío?', 'wamid.wait.1');

    foreach (range(1, 4) as $i) {
        workOnce($this);
        $this->travel(6)->seconds();
    }

    $lock->release();
    workOnce($this);

    expect(DB::table('failed_jobs')->count())->toBe(0)
        ->and(sentWhatsAppTexts())->toHaveCount(1)
        ->and(sentWhatsAppTexts()[0]->data()['text']['body'])->toBe('Respuesta al segundo mensaje');
});

it('retries a failing AI call and apologizes only once, after the last attempt', function () {
    fakeExternalApis(failChat: true);

    ProcessWhatsAppMessage::dispatch($this->store, '573001112233', 'Hola', 'wamid.fail.1');

    foreach (range(1, 4) as $i) {
        workOnce($this);
    }

    $apologies = collect(sentWhatsAppTexts())
        ->filter(fn ($request) => str_starts_with($request->data()['text']['body'], 'Lo siento, estoy experimentando dificultades técnicas'));

    expect(mainChatRequests())->toHaveCount(3)
        ->and($apologies)->toHaveCount(1)
        ->and(DB::table('failed_jobs')->count())->toBe(1)
        ->and(WhatsAppMessage::count())->toBe(0);
});
