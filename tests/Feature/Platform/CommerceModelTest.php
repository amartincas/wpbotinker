<?php

/*
 * C1 — commercial model: plans, payments, receipts, subscriptions and free
 * sample deliveries, with their relations, states and unique constraints.
 */

use App\Models\Customer;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Models\Plan;
use App\Models\Product;
use App\Models\SampleDelivery;
use App\Models\Store;
use App\Models\Subscription;
use App\Platform\Domains\DomainRegistry;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Domains\FinanceTestDomain;
use Tests\Support\Domains\OracleTestDomain;

beforeEach(function () {
    config(['botinker.domains' => ['oracle' => OracleTestDomain::class, 'finance' => FinanceTestDomain::class]]);
    Storage::fake(PaymentReceipt::DISK);

    $this->store = makeStore(['conversation_mode' => Store::MODE_KNOWLEDGE]);
    $this->product = commerceProduct($this->store);
    $this->plan = Plan::create(['product_id' => $this->product->id, 'name' => 'Mensual', 'price' => 20000]);
    $this->customer = Customer::create(['store_id' => $this->store->id, 'customer_phone' => '573001110000']);
});

function commerceProduct(Store $store, array $overrides = []): Product
{
    return Product::create(array_merge([
        'store_id' => $store->id, 'domain_key' => 'oracle', 'name' => 'Lectura', 'description' => 'x', 'price' => 1, 'stock' => 1,
        'required_facts' => ['birth_date'], 'capability_key' => 'oracle.lucky_number',
        'access_mode' => Product::ACCESS_SUBSCRIPTION, 'sample_instructions' => 'Ofrece una muestra breve del servicio.',
    ], $overrides));
}

function startPayment(Customer $customer, Plan $plan): Payment
{
    return Payment::start($customer, $plan, now()->addHours(48));
}

function receiptFor(Payment $payment, array $overrides = []): PaymentReceipt
{
    $path = "{$payment->store_id}/".uniqid().'.jpg';
    Storage::disk(PaymentReceipt::DISK)->put($path, 'imagen');

    return PaymentReceipt::create(array_merge([
        'payment_id' => $payment->id, 'file_path' => $path, 'mime_type' => 'image/jpeg', 'file_size' => 6,
        'file_hash' => hash('sha256', uniqid('', true)), 'source_wamid' => 'wamid.'.uniqid(),
        'extracted_data' => ['amount' => 20000, 'reference' => 'AB12', 'payer_name' => 'Ana Pérez'],
    ], $overrides));
}

function moveTo(Payment $payment, string ...$statuses): Payment
{
    foreach ($statuses as $status) {
        $payment->update(['status' => $status]);
    }

    return $payment;
}

describe('products', function () {
    it('are open by default and keep sales products unaffected', function () {
        $sales = Product::create(['store_id' => $this->store->id, 'name' => 'Camisa', 'description' => 'x', 'price' => 1, 'stock' => 1]);

        expect($sales->fresh()->access_mode)->toBe(Product::ACCESS_OPEN)
            ->and($sales->requiresSubscription())->toBeFalse()
            ->and($sales->offersSample())->toBeFalse();
    });

    it('reject an unknown access mode', function () {
        commerceProduct($this->store, ['access_mode' => 'gratis']);
    })->throws(InvalidArgumentException::class, 'Unknown access mode');

    it('only require a subscription when they belong to a domain', function () {
        Product::create(['store_id' => $this->store->id, 'name' => 'Camisa', 'description' => 'x', 'price' => 1, 'stock' => 1, 'access_mode' => Product::ACCESS_SUBSCRIPTION]);
    })->throws(InvalidArgumentException::class, 'Only a domain product');

    it('offer a sample only with instructions and a subscription', function () {
        expect($this->product->offersSample())->toBeTrue()
            ->and(commerceProduct($this->store, ['name' => 'Sin muestra', 'sample_instructions' => null])->offersSample())->toBeFalse()
            ->and(commerceProduct($this->store, ['name' => 'Libre', 'access_mode' => Product::ACCESS_OPEN])->offersSample())->toBeFalse();
    });

    it('accept a registered capability of their own domain for the sample', function () {
        $product = commerceProduct($this->store, ['sample_capability_key' => 'oracle.lucky_number']);

        expect($product->fresh()->sample_capability_key)->toBe('oracle.lucky_number')
            ->and(app(DomainRegistry::class)->capabilityKeys('oracle'))->toBe(['oracle.lucky_number']);
    });

    it('reject an invalid sample configuration', function (array $overrides, string $message) {
        expect(fn () => commerceProduct($this->store, $overrides))->toThrow(InvalidArgumentException::class, $message);
    })->with([
        'capability of another domain' => [['sample_capability_key' => 'finance.savings_target'], 'belongs to domain [finance]'],
        'unknown capability' => [['sample_capability_key' => 'oracle.unknown'], 'has no capability'],
        'facts not required by the product' => [['required_facts' => [], 'capability_key' => null, 'sample_capability_key' => 'oracle.lucky_number'], 'requires fact [birth_date]'],
        'capability without instructions' => [['sample_instructions' => null, 'sample_capability_key' => 'oracle.lucky_number'], 'needs sample instructions'],
    ]);
});

describe('plans', function () {
    it('take their store from the product and normalize the currency', function () {
        $plan = Plan::create(['product_id' => $this->product->id, 'name' => 'Anual', 'price' => 200000, 'currency' => 'cop', 'duration_days' => 365]);

        expect($plan->store_id)->toBe($this->store->id)->and($plan->currency)->toBe('COP');
    });

    it('default to 30 days', function () {
        expect($this->plan->fresh()->duration_days)->toBe(30);
    });

    it('reject an invalid configuration', function (Closure $attributes, string $message) {
        expect(fn () => Plan::create($attributes->call($this)))->toThrow(InvalidArgumentException::class, $message);
    })->with([
        'product of another store' => [fn () => ['store_id' => makeStore()->id, 'product_id' => $this->product->id, 'name' => 'X', 'price' => 1], 'does not belong to store'],
        'open product' => [fn () => ['product_id' => commerceProduct($this->store, ['access_mode' => Product::ACCESS_OPEN])->id, 'name' => 'X', 'price' => 1], 'subscription product'],
        'zero price' => [fn () => ['product_id' => $this->product->id, 'name' => 'X', 'price' => 0], 'greater than zero'],
        'zero days' => [fn () => ['product_id' => $this->product->id, 'name' => 'X', 'price' => 1, 'duration_days' => 0], 'between 1 and'],
        'too many days' => [fn () => ['product_id' => $this->product->id, 'name' => 'X', 'price' => 1, 'duration_days' => 4000], 'between 1 and'],
        'bad currency' => [fn () => ['product_id' => $this->product->id, 'name' => 'X', 'price' => 1, 'currency' => 'PESOS'], '3-letter ISO'],
    ]);
});

describe('payments', function () {
    it('start pending from an active plan, copying its price and duration', function () {
        $payment = startPayment($this->customer, $this->plan);
        $this->plan->update(['price' => 99000, 'duration_days' => 7, 'name' => 'Otro']);

        expect($payment->fresh())
            ->status->toBe(Payment::STATUS_PENDING)
            ->store_id->toBe($this->store->id)
            ->plan_name->toBe('Mensual')
            ->amount->toBe('20000.00')
            ->currency->toBe('COP')
            ->duration_days->toBe(30);
    });

    it('cannot start from an inactive plan', function () {
        $this->plan->update(['is_active' => false]);

        startPayment($this->customer, $this->plan);
    })->throws(InvalidArgumentException::class, 'not active');

    it('never change their snapshot', function (string $field, mixed $value) {
        startPayment($this->customer, $this->plan)->update([$field => $value]);
    })->throws(InvalidArgumentException::class, 'never change')->with([
        'amount' => ['amount', 1],
        'duration' => ['duration_days', 365],
        'currency' => ['currency', 'USD'],
        'plan name' => ['plan_name', 'Otro'],
    ]);

    it('reject a customer and product of different stores', function () {
        $stranger = Customer::create(['store_id' => makeStore()->id, 'customer_phone' => '573001110000']);

        startPayment($stranger, $this->plan);
    })->throws(InvalidArgumentException::class, 'different stores');

    it('reject a plan of another product', function () {
        $other = commerceProduct($this->store, ['name' => 'Otra']);

        Payment::create([
            'customer_id' => $this->customer->id, 'product_id' => $other->id, 'plan_id' => $this->plan->id,
            'plan_name' => 'Mensual', 'amount' => 1, 'currency' => 'COP', 'duration_days' => 30,
        ]);
    })->throws(InvalidArgumentException::class, 'is not a plan of product');

    it('must start pending', function () {
        Payment::create([
            'customer_id' => $this->customer->id, 'product_id' => $this->product->id, 'plan_id' => $this->plan->id,
            'plan_name' => 'Mensual', 'amount' => 1, 'currency' => 'COP', 'duration_days' => 30, 'status' => Payment::STATUS_CONFIRMED,
        ]);
    })->throws(InvalidArgumentException::class, 'starts as pending');

    it('allow one open payment per customer and product', function () {
        startPayment($this->customer, $this->plan);

        expect(fn () => startPayment($this->customer, $this->plan))->toThrow(UniqueConstraintViolationException::class);
    });

    it('keep "not reflected" open, and allow a new payment once closed', function () {
        $payment = moveTo(startPayment($this->customer, $this->plan), Payment::STATUS_UNDER_REVIEW, Payment::STATUS_NOT_REFLECTED);

        expect($payment->isOpen())->toBeTrue()
            ->and(fn () => startPayment($this->customer, $this->plan))->toThrow(UniqueConstraintViolationException::class);

        moveTo($payment, Payment::STATUS_REJECTED);

        expect(startPayment($this->customer, $this->plan)->isOpen())->toBeTrue()
            ->and(startPayment($this->customer, Plan::create(['product_id' => commerceProduct($this->store, ['name' => 'Otra'])->id, 'name' => 'M', 'price' => 1]))->isOpen())->toBeTrue();
    });

    it('follow the allowed status changes', function (array $path) {
        $payment = moveTo(startPayment($this->customer, $this->plan), ...$path);

        expect($payment->fresh()->status)->toBe(end($path));
    })->with([
        'expired' => [[Payment::STATUS_EXPIRED]],
        'confirmed' => [[Payment::STATUS_UNDER_REVIEW, Payment::STATUS_CONFIRMED]],
        'rejected' => [[Payment::STATUS_UNDER_REVIEW, Payment::STATUS_REJECTED]],
        'confirmed once reflected' => [[Payment::STATUS_UNDER_REVIEW, Payment::STATUS_NOT_REFLECTED, Payment::STATUS_CONFIRMED]],
        'rejected after not reflected' => [[Payment::STATUS_UNDER_REVIEW, Payment::STATUS_NOT_REFLECTED, Payment::STATUS_REJECTED]],
        'new receipt after not reflected' => [[Payment::STATUS_UNDER_REVIEW, Payment::STATUS_NOT_REFLECTED, Payment::STATUS_UNDER_REVIEW]],
    ]);

    it('reject any other status change', function (array $path, string $to) {
        $payment = moveTo(startPayment($this->customer, $this->plan), ...$path);

        expect(fn () => $payment->update(['status' => $to]))->toThrow(InvalidArgumentException::class, 'cannot go from');
    })->with([
        'confirm without receipt' => [[], Payment::STATUS_CONFIRMED],
        'reject without receipt' => [[], Payment::STATUS_REJECTED],
        'expire under review' => [[Payment::STATUS_UNDER_REVIEW], Payment::STATUS_EXPIRED],
        'reopen confirmed' => [[Payment::STATUS_UNDER_REVIEW, Payment::STATUS_CONFIRMED], Payment::STATUS_UNDER_REVIEW],
        'reject confirmed' => [[Payment::STATUS_UNDER_REVIEW, Payment::STATUS_CONFIRMED], Payment::STATUS_REJECTED],
        'confirm rejected' => [[Payment::STATUS_UNDER_REVIEW, Payment::STATUS_REJECTED], Payment::STATUS_CONFIRMED],
        'revive expired' => [[Payment::STATUS_EXPIRED], Payment::STATUS_PENDING],
        'unknown status' => [[], 'paid'],
    ]);

    it('normalize the payment reference', function () {
        $payment = startPayment($this->customer, $this->plan);
        $payment->update(['reference' => ' ab-12 34 ']);

        expect($payment->fresh()->reference)->toBe('AB1234')
            ->and(Payment::normalizeReference(' - '))->toBeNull();
    });
});

describe('receipts', function () {
    beforeEach(function () {
        $this->payment = startPayment($this->customer, $this->plan);
    });

    it('belong to an open payment of the same store', function () {
        $receipt = receiptFor($this->payment);

        expect($receipt->store_id)->toBe($this->store->id);
    });

    it('are refused without an open payment', function (Closure $payment) {
        expect(fn () => receiptFor($payment->call($this)))->toThrow(InvalidArgumentException::class, 'open payment');
    })->with([
        'expired' => [fn () => moveTo($this->payment, Payment::STATUS_EXPIRED)],
        'confirmed' => [fn () => moveTo($this->payment, Payment::STATUS_UNDER_REVIEW, Payment::STATUS_CONFIRMED)],
        'rejected' => [fn () => moveTo($this->payment, Payment::STATUS_UNDER_REVIEW, Payment::STATUS_REJECTED)],
        'nonexistent' => [fn () => new Payment(['store_id' => $this->store->id])],
    ]);

    it('are refused for another store', function () {
        receiptFor($this->payment, ['store_id' => makeStore()->id]);
    })->throws(InvalidArgumentException::class, 'does not belong to store');

    it('are unique per store by file hash and by source message', function () {
        receiptFor($this->payment, ['file_hash' => str_repeat('a', 64), 'source_wamid' => 'wamid.1']);

        expect(fn () => receiptFor($this->payment, ['file_hash' => str_repeat('a', 64)]))->toThrow(UniqueConstraintViolationException::class)
            ->and(fn () => receiptFor($this->payment, ['source_wamid' => 'wamid.1']))->toThrow(UniqueConstraintViolationException::class);

        $otherStore = makeStore(['conversation_mode' => Store::MODE_KNOWLEDGE]);
        $otherPayment = startPayment(
            Customer::create(['store_id' => $otherStore->id, 'customer_phone' => '573001110000']),
            Plan::create(['product_id' => commerceProduct($otherStore)->id, 'name' => 'M', 'price' => 1]),
        );

        expect(receiptFor($otherPayment, ['file_hash' => str_repeat('a', 64), 'source_wamid' => 'wamid.1'])->exists)->toBeTrue();
    });

    it('never replace their file or identity', function (array $changes) {
        receiptFor($this->payment)->update($changes);
    })->throws(InvalidArgumentException::class)->with([
        'file' => [['file_path' => 'otro.jpg']],
        'hash' => [['file_hash' => str_repeat('b', 64)]],
        'payment' => [['payment_id' => 999]],
    ]);

    it('encrypt the extracted data at rest', function () {
        $receipt = receiptFor($this->payment);

        expect(DB::table('payment_receipts')->where('id', $receipt->id)->value('extracted_data'))->not->toContain('Ana Pérez')
            ->and($receipt->toArray())->not->toHaveKey('extracted_data');
    });

    it('keep their file while the payment is open, also when not reflected', function (array $path) {
        $receipt = receiptFor($this->payment);
        moveTo($this->payment, ...$path);

        expect(fn () => $receipt->purgeAfterReview())->toThrow(LogicException::class, 'still under review');
        Storage::disk(PaymentReceipt::DISK)->assertExists($receipt->file_path);
    })->with([
        'pending' => [[]],
        'under review' => [[Payment::STATUS_UNDER_REVIEW]],
        'not reflected' => [[Payment::STATUS_UNDER_REVIEW, Payment::STATUS_NOT_REFLECTED]],
    ]);

    it('lose their file and personal data once the review is over, keeping the metadata', function (string $closing) {
        $receipt = receiptFor($this->payment, ['file_hash' => str_repeat('c', 64)]);
        $path = $receipt->file_path;
        moveTo($this->payment, Payment::STATUS_UNDER_REVIEW, Payment::STATUS_NOT_REFLECTED, $closing);

        $receipt->purgeAfterReview();
        $receipt->refresh();

        Storage::disk(PaymentReceipt::DISK)->assertMissing($path);
        expect($receipt->hasFile())->toBeFalse()
            ->and($receipt->file_deleted_at)->not->toBeNull()
            ->and($receipt->file_hash)->toBe(str_repeat('c', 64))
            ->and($receipt->extracted_data)->toBe(['amount' => 20000, 'reference' => 'AB12']);
    })->with([Payment::STATUS_CONFIRMED, Payment::STATUS_REJECTED]);

    it('can be purged again, or with the file already gone, without errors', function () {
        $receipt = receiptFor($this->payment);
        moveTo($this->payment, Payment::STATUS_UNDER_REVIEW, Payment::STATUS_CONFIRMED);
        Storage::disk(PaymentReceipt::DISK)->delete($receipt->file_path);

        $receipt->purgeAfterReview();
        $deletedAt = $receipt->fresh()->file_deleted_at;
        $this->travel(1)->minutes();
        $receipt->fresh()->purgeAfterReview();

        expect($receipt->fresh()->file_deleted_at->equalTo($deletedAt))->toBeTrue()
            ->and($receipt->fresh()->hasFile())->toBeFalse();
    });

    it('stay marked with their file when the disk refuses to delete it', function () {
        $receipt = receiptFor($this->payment);
        moveTo($this->payment, Payment::STATUS_UNDER_REVIEW, Payment::STATUS_CONFIRMED);
        $disk = Mockery::mock();
        $disk->shouldReceive('exists')->andReturn(true);
        $disk->shouldReceive('delete')->andReturn(false);
        Storage::shouldReceive('disk')->with(PaymentReceipt::DISK)->andReturn($disk);

        expect(fn () => $receipt->purgeAfterReview())->toThrow(RuntimeException::class, 'could not be deleted')
            ->and($receipt->fresh()->hasFile())->toBeTrue()
            ->and($receipt->fresh()->file_deleted_at)->toBeNull();
    });
});

describe('subscriptions', function () {
    function subscriptionFor(Customer $customer, Product $product, array $overrides = []): Subscription
    {
        return Subscription::create(array_merge([
            'customer_id' => $customer->id, 'product_id' => $product->id, 'started_at' => now(), 'expires_at' => now()->addDays(30),
        ], $overrides));
    }

    it('grant access while active and not expired', function () {
        $subscription = subscriptionFor($this->customer, $this->product);

        expect($subscription->store_id)->toBe($this->store->id)
            ->and($subscription->isValidAt())->toBeTrue()
            ->and($subscription->hasExpired())->toBeFalse()
            ->and($subscription->isValidAt(now()->addDays(30)))->toBeFalse()
            ->and($subscription->hasExpired(now()->addDays(31)))->toBeTrue();
    });

    it('never grant access once revoked', function () {
        $subscription = subscriptionFor($this->customer, $this->product, ['status' => Subscription::STATUS_REVOKED]);

        expect($subscription->isValidAt())->toBeFalse()->and($subscription->hasExpired())->toBeFalse();
    });

    it('are unique per customer and product', function () {
        subscriptionFor($this->customer, $this->product);

        expect(fn () => subscriptionFor($this->customer, $this->product))->toThrow(UniqueConstraintViolationException::class)
            ->and(subscriptionFor($this->customer, commerceProduct($this->store, ['name' => 'Otra']))->exists)->toBeTrue();
    });

    it('reject an invalid configuration', function (Closure $build, string $message) {
        expect(fn () => $build->call($this))->toThrow(InvalidArgumentException::class, $message);
    })->with([
        'expiry before start' => [fn () => subscriptionFor($this->customer, $this->product, ['expires_at' => now()->subDay()]), 'expiry after it'],
        'unknown status' => [fn () => subscriptionFor($this->customer, $this->product, ['status' => 'expired']), 'Unknown subscription status'],
        'open product' => [fn () => subscriptionFor($this->customer, commerceProduct($this->store, ['access_mode' => Product::ACCESS_OPEN])), 'not a subscription product'],
        'unconfirmed payment' => [fn () => subscriptionFor($this->customer, $this->product, ['last_payment_id' => moveTo(startPayment($this->customer, $this->plan), Payment::STATUS_UNDER_REVIEW)->id]), 'Only a confirmed payment'],
        'payment of another customer' => [function () {
            $other = Customer::create(['store_id' => $this->store->id, 'customer_phone' => '573002220000']);
            $payment = moveTo(startPayment($other, $this->plan), Payment::STATUS_UNDER_REVIEW, Payment::STATUS_CONFIRMED);

            return subscriptionFor($this->customer, $this->product, ['last_payment_id' => $payment->id]);
        }, 'Only a confirmed payment'],
    ]);

    it('record the confirmed payment that granted them', function () {
        $payment = moveTo(startPayment($this->customer, $this->plan), Payment::STATUS_UNDER_REVIEW, Payment::STATUS_CONFIRMED);

        expect(subscriptionFor($this->customer, $this->product, ['last_payment_id' => $payment->id])->lastPayment->is($payment))->toBeTrue();
    });
});

describe('sample deliveries', function () {
    it('are recorded once per customer and product, without granting access', function () {
        $delivery = SampleDelivery::create(['customer_id' => $this->customer->id, 'product_id' => $this->product->id, 'capability_key' => 'oracle.lucky_number']);

        expect($delivery->store_id)->toBe($this->store->id)
            ->and($delivery->delivered_at)->not->toBeNull()
            ->and($this->customer->subscriptions()->exists())->toBeFalse()
            ->and(fn () => SampleDelivery::create(['customer_id' => $this->customer->id, 'product_id' => $this->product->id]))
            ->toThrow(UniqueConstraintViolationException::class);
    });

    it('are refused for a product without a sample', function () {
        SampleDelivery::create(['customer_id' => $this->customer->id, 'product_id' => commerceProduct($this->store, ['name' => 'Sin muestra', 'sample_instructions' => null])->id]);
    })->throws(InvalidArgumentException::class, 'offers no sample');

    it('are refused for a customer of another store', function () {
        SampleDelivery::create(['customer_id' => Customer::create(['store_id' => makeStore()->id, 'customer_phone' => '1'])->id, 'product_id' => $this->product->id]);
    })->throws(InvalidArgumentException::class, 'different stores');

    it('never change', function () {
        SampleDelivery::create(['customer_id' => $this->customer->id, 'product_id' => $this->product->id])->update(['capability_key' => 'x']);
    })->throws(InvalidArgumentException::class, 'never changes');
});
