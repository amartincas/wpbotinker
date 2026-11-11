<?php

/*
 * meta:backfill-conversions sends the Purchase of a Lead in the currency of
 * its confirmed payment, on both paths (business_messaging with a ctwa_clid,
 * offline without one); a Lead without a payment keeps the store's
 * configured currency. Meta is simulated (Http::fake).
 */

use App\Jobs\RecordPaymentLead;
use App\Models\Lead;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Store;
use App\Platform\Commerce\PaymentReview;
use App\Platform\Memory\CustomerMemory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Domains\OracleTestDomain;

const BF_CUSTOMER = '573007778888';

beforeEach(function () {
    config(['botinker.domains' => ['oracle' => OracleTestDomain::class]]);
    Http::fake(['*/events' => Http::response(['events_received' => 1])]);
    // The payment's own Lead is created below by hand, as one still pending for the backfill.
    Queue::fake([RecordPaymentLead::class]);
});

function bfStore(string $currency): Store
{
    return makeStore([
        'conversation_mode' => Store::MODE_KNOWLEDGE,
        'meta_dataset_id' => 'DATASET-1', 'meta_capi_access_token' => 'capi-token', 'meta_capi_currency' => $currency, 'wa_business_account_id' => 'WABA-1',
    ]);
}

/** A confirmed payment of a plan in the given currency. */
function bfConfirmedPayment(Store $store, string $price, string $currency): Payment
{
    $product = Product::create([
        'store_id' => $store->id, 'domain_key' => 'oracle', 'name' => 'Lectura', 'description' => 'x', 'price' => 1, 'stock' => 1,
        'access_mode' => Product::ACCESS_SUBSCRIPTION, 'ai_sales_strategy' => 'SERVICIO',
    ]);
    $plan = Plan::create(['product_id' => $product->id, 'name' => 'Mensual', 'price' => $price, 'currency' => $currency]);
    $payment = Payment::start(app(CustomerMemory::class)->customerFor($store, BF_CUSTOMER), $plan, now()->addHours(48));
    $payment->update(['status' => Payment::STATUS_UNDER_REVIEW]);
    app(PaymentReview::class)->confirm($payment);

    return $payment->fresh();
}

/** A Lead the observer never sent to Meta (meta_capi_sent_at empty). */
function bfPendingLead(Store $store, ?Payment $payment, string $saleValue, ?string $ctwaClid): Lead
{
    return Lead::withoutEvents(fn () => Lead::create([
        'store_id' => $store->id, 'payment_id' => $payment?->id, 'customer_phone' => BF_CUSTOMER, 'customer_name' => 'Ana',
        'summary' => 'x', 'sale_value' => $saleValue, 'ctwa_clid' => $ctwaClid,
    ]));
}

/** @return list<array<string, mixed>> the custom_data of the Purchase events sent */
function bfPurchases(): array
{
    return Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/events') && $r->data()['data'][0]['event_name'] === 'Purchase')
        ->map(fn ($p) => $p[0]->data()['data'][0]['custom_data'])->values()->all();
}

dataset('backfill paths', [
    'business_messaging (ctwa_clid)' => ['CLID-1', 'business_messaging'],
    'offline (no ctwa_clid)' => [null, 'physical_store'],
]);

it('sends a payment Lead in the payment currency, not the store one', function (?string $ctwaClid, string $actionSource) {
    $store = bfStore('COP');
    bfPendingLead($store, bfConfirmedPayment($store, '12.5', 'USD'), '12.5', $ctwaClid);

    $this->artisan('meta:backfill-conversions', ['--store' => $store->id])->assertSuccessful();

    $purchase = Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/events') && $r->data()['data'][0]['event_name'] === 'Purchase')->sole()[0];
    expect($purchase->data()['data'][0]['action_source'])->toBe($actionSource)
        ->and(bfPurchases())->toBe([['value' => 12.5, 'currency' => 'USD']]);
})->with('backfill paths');

it('keeps the store currency for a Lead without a payment', function (?string $ctwaClid) {
    $store = bfStore('COP');
    bfPendingLead($store, null, '20000', $ctwaClid);

    $this->artisan('meta:backfill-conversions', ['--store' => $store->id])->assertSuccessful();

    expect(bfPurchases())->toBe([['value' => 20000.0, 'currency' => 'COP']]);
})->with('backfill paths');

it('sends a COP payment in COP even when the store is set to USD', function (?string $ctwaClid) {
    $store = bfStore('USD');
    bfPendingLead($store, bfConfirmedPayment($store, '35000', 'COP'), '35000', $ctwaClid);

    $this->artisan('meta:backfill-conversions', ['--store' => $store->id])->assertSuccessful();

    expect(bfPurchases())->toBe([['value' => 35000.0, 'currency' => 'COP']]);
})->with('backfill paths');
