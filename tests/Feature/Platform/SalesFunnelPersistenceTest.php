<?php

/*
 * Sales funnel persistence: immutable versions of a product's funnel, one
 * active at most, and each conversation's state in it. Nothing here runs a
 * funnel, and none of it touches the free replies (sample_deliveries).
 */

use App\Models\Conversation;
use App\Models\ConversationFunnelState;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Product;
use App\Models\SalesFunnel;
use App\Models\SampleDelivery;
use App\Models\Store;
use App\Models\User;
use App\Platform\Commerce\AccessGate;
use App\Platform\Funnel\FunnelStates;
use App\Platform\Funnel\SalesFunnelVersions;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;
use Tests\Support\Domains\FinanceTestDomain;
use Tests\Support\Domains\OracleTestDomain;

beforeEach(function () {
    config(['botinker.domains' => ['oracle' => OracleTestDomain::class, 'finance' => FinanceTestDomain::class]]);
    $this->freezeSecond();

    $this->store = makeStore(['conversation_mode' => Store::MODE_KNOWLEDGE]);
    $this->product = sfProduct($this->store, 'Producto A');
    $this->versions = app(SalesFunnelVersions::class);
    $this->states = app(FunnelStates::class);
});

function sfProduct(Store $store, string $name, array $overrides = []): Product
{
    return Product::create(array_merge([
        'store_id' => $store->id, 'domain_key' => 'oracle', 'name' => $name, 'description' => 'x', 'price' => 1, 'stock' => 1,
        'access_mode' => Product::ACCESS_SUBSCRIPTION,
    ], $overrides));
}

function sfDefinition(array $keys = ['inicio', 'cierre'], array $extra = []): array
{
    return array_merge(['name' => 'Embudo', 'stages' => array_map(fn (string $key) => ['key' => $key, 'type' => 'ai'], $keys)], $extra);
}

function sfConversation(Store $store, string $phone = '573001112233'): Conversation
{
    return Conversation::create(['store_id' => $store->id, 'customer_phone' => $phone, 'last_session_at' => now()]);
}

function sfSample(Product $product, int $turns, string $phone = '573001112233'): SampleDelivery
{
    $customer = Customer::firstOrCreate(['store_id' => $product->store_id, 'customer_phone' => $phone]);

    return SampleDelivery::create(['customer_id' => $customer->id, 'product_id' => $product->id, 'turns_used' => $turns]);
}

/** The exception the MariaDB driver raises for this error code. */
function sfCollision(int $code): QueryException
{
    $driver = new PDOException("SQLSTATE: {$code} simulated");
    $driver->errorInfo = ['HY000', $code, 'simulated'];

    return $code === 1062
        ? new UniqueConstraintViolationException('mysql', 'insert', [], $driver)
        : new QueryException('mysql', 'insert', [], $driver);
}

/** Tests run inside a transaction: this one behaves as a real job, outside of any. */
function sfStatesOutsideTransaction(): FunnelStates
{
    return new class extends FunnelStates
    {
        protected function insideTransaction(): bool
        {
            return false;
        }
    };
}

describe('versions', function () {
    it('publishes numbered versions per product, newest first in the history', function () {
        $first = $this->versions->publish($this->product, sfDefinition(['a']));
        $second = $this->versions->publish($this->product, sfDefinition(['b']));
        $other = $this->versions->publish(sfProduct($this->store, 'Producto B'), sfDefinition(['c']));

        expect($first->version)->toBe(1)
            ->and($second->version)->toBe(2)
            ->and($other->version)->toBe(1)
            ->and($this->versions->history($this->product)->pluck('version')->all())->toBe([2, 1])
            ->and($first->store_id)->toBe($this->store->id)
            ->and($first->published_at)->not->toBeNull();
    });

    it('keeps the whole definition as published', function () {
        $definition = sfDefinition(['a'], ['goal' => 'Vender', 'free_replies' => ['limit' => 2, 'on_exhausted' => 'a']]);

        expect($this->versions->publish($this->product, $definition)->fresh()->definition)->toBe($definition);
    });

    it('records who published', function () {
        $user = User::factory()->create();

        expect($this->versions->publish($this->product, sfDefinition(), $user->id)->creator->id)->toBe($user->id);
    });

    it('rejects a definition without stages or with repeated or missing keys', function (array $definition) {
        $this->versions->publish($this->product, $definition);
    })->with([
        'no stages' => [['name' => 'x']],
        'empty stages' => [['stages' => []]],
        'stages not a list' => [['stages' => ['a' => ['key' => 'a']]]],
        'stage without a key' => [['stages' => [['type' => 'ai']]]],
        'repeated keys' => [['stages' => [['key' => 'a'], ['key' => 'a']]]],
        'key too long' => [['stages' => [['key' => str_repeat('a', 101)]]]],
    ])->throws(InvalidArgumentException::class);

    it('adds nothing when the definition is rejected', function () {
        expect(fn () => $this->versions->publish($this->product, ['stages' => []]))->toThrow(InvalidArgumentException::class)
            ->and(SalesFunnel::count())->toBe(0)
            ->and($this->product->fresh()->active_sales_funnel_id)->toBeNull();
    });
});

describe('publishing and the active version', function () {
    it('makes the last publication the only active version', function () {
        $first = $this->versions->publish($this->product, sfDefinition());
        expect($this->versions->active($this->product)->id)->toBe($first->id);

        $second = $this->versions->publish($this->product, sfDefinition());

        expect($this->versions->active($this->product)->id)->toBe($second->id)
            ->and($this->product->fresh()->activeSalesFunnel->id)->toBe($second->id)
            ->and($this->product->active_sales_funnel_id)->toBe($second->id);
    });

    it('deactivates without losing versions, and can be republished', function () {
        $first = $this->versions->publish($this->product, sfDefinition());

        $this->versions->deactivate($this->product);

        expect($this->versions->active($this->product))->toBeNull()
            ->and($this->versions->history($this->product))->toHaveCount(1);

        expect($this->versions->restore($first)->version)->toBe(2)
            ->and($this->versions->active($this->product)->version)->toBe(2);
    });

    it('does not edit the product when publishing', function () {
        $before = $this->product->fresh()->getAttributes();
        $this->travel(1)->hours();

        $funnel = $this->versions->publish($this->product, sfDefinition());

        expect(collect($this->product->fresh()->getAttributes())->except('active_sales_funnel_id')->all())
            ->toBe(collect($before)->except('active_sales_funnel_id')->all())
            ->and($this->product->fresh()->active_sales_funnel_id)->toBe($funnel->id);
    });

    it('never points a product to a version of another one', function () {
        $foreign = $this->versions->publish(sfProduct($this->store, 'Producto B'), sfDefinition());

        $this->product->forceFill(['active_sales_funnel_id' => $foreign->id])->save();
    })->throws(InvalidArgumentException::class, 'does not belong');

    it('does not let the active version be mass assigned', function () {
        $funnel = $this->versions->publish($this->product, sfDefinition());
        $this->versions->deactivate($this->product);

        $this->product->fresh()->update(['active_sales_funnel_id' => $funnel->id, 'name' => 'Otro nombre']);

        expect($this->product->fresh())->active_sales_funnel_id->toBeNull()->name->toBe('Otro nombre');
    });
});

describe('immutability', function () {
    it('refuses to change a published version', function () {
        $this->versions->publish($this->product, sfDefinition())->update(['definition' => sfDefinition(['otra'])]);
    })->throws(InvalidArgumentException::class, 'never changes');

    it('refuses to remove a published version on its own', function () {
        $this->versions->publish($this->product, sfDefinition())->delete();
    })->throws(InvalidArgumentException::class, 'is a record');
});

describe('restoring', function () {
    it('publishes a copy as a new version and leaves the history as it was', function () {
        $first = $this->versions->publish($this->product, sfDefinition(['a']));
        $second = $this->versions->publish($this->product, sfDefinition(['b']));
        $before = [$first->fresh()->getAttributes(), $second->fresh()->getAttributes()];

        $restored = $this->versions->restore($first);

        expect($restored->version)->toBe(3)
            ->and($restored->id)->not->toBe($first->id)
            ->and($restored->definition)->toBe($first->definition)
            ->and($restored->restoredFrom->id)->toBe($first->id)
            ->and($this->versions->active($this->product)->id)->toBe($restored->id)
            ->and([$first->fresh()->getAttributes(), $second->fresh()->getAttributes()])->toBe($before);
    });

    it('refuses an origin from another product', function () {
        $foreign = $this->versions->publish(sfProduct($this->store, 'Producto B'), sfDefinition());

        SalesFunnel::create(['product_id' => $this->product->id, 'version' => 1, 'definition' => sfDefinition(), 'product_snapshot' => [], 'restored_from_id' => $foreign->id]);
    })->throws(InvalidArgumentException::class, 'does not belong');
});

describe('product snapshot', function () {
    it('records the commercial configuration and plans at publication, not later changes', function () {
        $this->product->update([
            'description' => 'DESC-1', 'faq_context' => 'FAQ-1', 'commercial_instructions' => 'COM-1',
            'opening_message' => 'INICIAL-1', 'opening_triggers' => ['Quiero conocer'], 'ai_sales_strategy' => 'SERVICIO-RESERVADO',
        ]);
        Plan::create(['product_id' => $this->product->id, 'name' => 'Mes', 'price' => 20000]);

        $funnel = $this->versions->publish($this->product, sfDefinition());
        $this->product->update(['description' => 'DESC-2', 'opening_message' => 'INICIAL-2']);
        Plan::where('product_id', $this->product->id)->update(['price' => 99000]);

        expect($funnel->fresh()->product_snapshot)
            ->toMatchArray(['description' => 'DESC-1', 'faq_context' => 'FAQ-1', 'commercial_instructions' => 'COM-1', 'opening_message' => 'INICIAL-1', 'opening_triggers' => ['Quiero conocer']])
            ->not->toHaveKey('ai_sales_strategy')
            ->and((float) $funnel->fresh()->product_snapshot['plans'][0]['price'])->toBe(20000.0);
    });

    it('takes the snapshot of a restored version from the product as it is now', function () {
        $this->product->update(['opening_message' => 'INICIAL-1']);
        $first = $this->versions->publish($this->product, sfDefinition());
        $this->product->update(['opening_message' => 'INICIAL-2']);

        $restored = $this->versions->restore($first);

        expect($restored->product_snapshot['opening_message'])->toBe('INICIAL-2')
            ->and($this->product->fresh()->opening_message)->toBe('INICIAL-2');
    });
});

describe('conversation state', function () {
    beforeEach(function () {
        $this->funnel = $this->versions->publish($this->product, sfDefinition());
        $this->conversation = sfConversation($this->store);
    });

    it('starts with no state, then keeps version, stage, interactions and context', function () {
        expect($this->states->find($this->conversation, $this->product))->toBeNull();

        $this->states->save($this->conversation, $this->product, $this->funnel, 'inicio', 2, ['consulta' => 'x']);

        expect($this->states->find($this->conversation, $this->product))
            ->sales_funnel_id->toBe($this->funnel->id)
            ->stage->toBe('inicio')
            ->stage_interactions->toBe(2)
            ->context->toBe(['consulta' => 'x'])
            ->stage_entered_at->not->toBeNull();
    });

    it('updates the same row and moves the entry time only when the stage changes', function () {
        $entered = $this->states->save($this->conversation, $this->product, $this->funnel, 'inicio')->stage_entered_at;

        $this->travel(5)->minutes();
        $same = $this->states->save($this->conversation, $this->product, $this->funnel, 'inicio', 1);
        expect($same->stage_entered_at->equalTo($entered))->toBeTrue();

        $this->travel(5)->minutes();
        $moved = $this->states->save($this->conversation, $this->product, $this->funnel, 'cierre');

        expect($moved->stage_entered_at->greaterThan($entered))->toBeTrue()
            ->and($moved->stage_interactions)->toBe(0)
            ->and(ConversationFunnelState::count())->toBe(1);
    });

    it('keeps the state of each product and conversation apart', function () {
        $otherProduct = sfProduct($this->store, 'Producto B');
        $otherFunnel = $this->versions->publish($otherProduct, sfDefinition(['otra']));
        $otherConversation = sfConversation($this->store, '573009998877');

        $this->states->save($this->conversation, $this->product, $this->funnel, 'inicio');
        $this->states->save($this->conversation, $otherProduct, $otherFunnel, 'otra');
        $this->states->save($otherConversation, $this->product, $this->funnel, 'cierre');

        expect($this->states->find($this->conversation, $this->product)->stage)->toBe('inicio')
            ->and($this->states->find($this->conversation, $otherProduct)->stage)->toBe('otra')
            ->and($this->states->find($otherConversation, $this->product)->stage)->toBe('cierre')
            ->and($this->states->find($otherConversation, $otherProduct))->toBeNull();

        $this->states->reset($this->conversation, $this->product);

        expect($this->states->find($this->conversation, $this->product))->toBeNull()
            ->and($this->states->find($this->conversation, $otherProduct))->not->toBeNull()
            ->and($this->states->find($otherConversation, $this->product))->not->toBeNull();
    });

    it('keeps a conversation on its version when another one is published', function () {
        $this->states->save($this->conversation, $this->product, $this->funnel, 'inicio');

        $this->versions->publish($this->product, sfDefinition(['nueva']));

        expect($this->states->find($this->conversation, $this->product)->sales_funnel_id)->toBe($this->funnel->id);
    });

    it('reports whether there was a state to reset', function () {
        expect($this->states->reset($this->conversation, $this->product))->toBeFalse();

        $this->states->save($this->conversation, $this->product, $this->funnel, 'inicio');

        expect($this->states->reset($this->conversation, $this->product))->toBeTrue();
    });

    it('refuses inconsistent data', function (Closure $save, string $message) {
        expect(fn () => $save->call($this))->toThrow(InvalidArgumentException::class, $message);
    })->with([
        'stage the version does not have' => [fn () => $this->states->save($this->conversation, $this->product, $this->funnel, 'no-existe'), 'has no stage'],
        'version of another product' => [fn () => $this->states->save($this->conversation, $this->product, $this->versions->publish(sfProduct($this->store, 'Producto B'), sfDefinition()), 'inicio'), 'does not belong'],
        'conversation of another store' => [fn () => $this->states->save(sfConversation(makeStore()), $this->product, $this->funnel, 'inicio'), 'different stores'],
    ]);
});

describe('database integrity', function () {
    it('refuses a repeated version of a product', function () {
        $funnel = $this->versions->publish($this->product, sfDefinition());

        SalesFunnel::create(['product_id' => $this->product->id, 'version' => $funnel->version, 'definition' => sfDefinition(), 'product_snapshot' => []]);
    })->throws(UniqueConstraintViolationException::class);

    it('refuses a second state for a conversation and product', function () {
        $funnel = $this->versions->publish($this->product, sfDefinition());
        $conversation = sfConversation($this->store);
        $this->states->save($conversation, $this->product, $funnel, 'inicio');

        ConversationFunnelState::create(['conversation_id' => $conversation->id, 'product_id' => $this->product->id, 'sales_funnel_id' => $funnel->id, 'stage' => 'inicio']);
    })->throws(UniqueConstraintViolationException::class);

    it('refuses a pointer to a version that does not exist', function () {
        DB::table('products')->where('id', $this->product->id)->update(['active_sales_funnel_id' => 999999]);
    })->throws(QueryException::class);

    it('removes versions and states with the product, and keeps the others', function () {
        $other = sfProduct($this->store, 'Producto B');
        $otherFunnel = $this->versions->publish($other, sfDefinition());
        $funnel = $this->versions->publish($this->product, sfDefinition());
        $conversation = sfConversation($this->store);
        $this->states->save($conversation, $this->product, $funnel, 'inicio');

        $this->product->delete();

        expect(SalesFunnel::pluck('id')->all())->toBe([$otherFunnel->id])
            ->and(ConversationFunnelState::count())->toBe(0)
            ->and(Conversation::whereKey($conversation->id)->exists())->toBeTrue();
    });

    it('removes the state with the conversation, and keeps the version', function () {
        $funnel = $this->versions->publish($this->product, sfDefinition());
        $conversation = sfConversation($this->store);
        $this->states->save($conversation, $this->product, $funnel, 'inicio');

        $conversation->delete();

        expect(ConversationFunnelState::count())->toBe(0)
            ->and(SalesFunnel::whereKey($funnel->id)->exists())->toBeTrue();
    });

    it('keeps a version when its author is removed', function () {
        $user = User::factory()->create();
        $funnel = $this->versions->publish($this->product, sfDefinition(), $user->id);

        $user->delete();

        expect($funnel->fresh())->not->toBeNull()->created_by->toBeNull();
    });
});

describe('concurrency', function () {
    it('gives every publication its own version number, one active at the end', function () {
        // Real concurrency needs a server database: the row lock is ignored
        // by SQLite. This covers the sequence and the unique index behind it.
        foreach (range(1, 5) as $i) {
            $this->versions->publish($this->product->fresh(), sfDefinition());
        }

        expect(SalesFunnel::where('product_id', $this->product->id)->pluck('version')->sort()->values()->all())->toBe([1, 2, 3, 4, 5])
            ->and($this->versions->active($this->product)->version)->toBe(5);
    });

    it('works from a stale copy of the product', function () {
        $stale = Product::find($this->product->id);
        $this->versions->publish($this->product, sfDefinition());

        $second = $this->versions->publish($stale, sfDefinition());

        expect($second->version)->toBe(2)->and($this->versions->active($this->product)->id)->toBe($second->id);
    });

    it('leaves no version nor pointer when the transaction around it fails', function () {
        expect(fn () => DB::transaction(function () {
            $this->versions->publish($this->product, sfDefinition());

            throw new RuntimeException('falló a mitad');
        }))->toThrow(RuntimeException::class);

        expect(SalesFunnel::count())->toBe(0)
            ->and($this->product->fresh()->active_sales_funnel_id)->toBeNull();
    });

    it('takes the state of a first save made by someone else', function () {
        $funnel = $this->versions->publish($this->product, sfDefinition());
        $conversation = sfConversation($this->store);
        ConversationFunnelState::create(['conversation_id' => $conversation->id, 'product_id' => $this->product->id, 'sales_funnel_id' => $funnel->id, 'stage' => 'inicio']);

        $this->states->save($conversation, $this->product, $funnel, 'cierre');

        expect(ConversationFunnelState::sole()->stage)->toBe('cierre');
    });
});

describe('collisions while saving a state', function () {
    beforeEach(function () {
        Sleep::fake();
        $this->funnel = $this->versions->publish($this->product, sfDefinition());
        $this->conversation = sfConversation($this->store);
        $this->attempts = 0;
    });

    it('retries a duplicate entry that left no row, and saves', function () {
        ConversationFunnelState::creating(function () {
            if (++$this->attempts <= 2) {
                throw sfCollision(1062);
            }
        });

        $state = $this->states->save($this->conversation, $this->product, $this->funnel, 'inicio', 2);

        expect($this->attempts)->toBe(3)
            ->and($state->exists)->toBeTrue()
            ->and(ConversationFunnelState::sole())->stage->toBe('inicio')->stage_interactions->toBe(2);
    });

    it('retries a deadlock outside a transaction, and saves', function () {
        ConversationFunnelState::creating(function () {
            if (++$this->attempts <= 2) {
                throw sfCollision(1213);
            }
        });

        sfStatesOutsideTransaction()->save($this->conversation, $this->product, $this->funnel, 'inicio');

        expect($this->attempts)->toBe(3)->and(ConversationFunnelState::count())->toBe(1);
    });

    it('retries a deadlock while updating an existing state', function () {
        $this->states->save($this->conversation, $this->product, $this->funnel, 'inicio');
        ConversationFunnelState::updating(function () {
            if (++$this->attempts === 1) {
                throw sfCollision(1213);
            }
        });

        sfStatesOutsideTransaction()->save($this->conversation, $this->product, $this->funnel, 'cierre');

        expect($this->attempts)->toBe(2)->and(ConversationFunnelState::sole()->stage)->toBe('cierre');
    });

    it('reads the state again on each retry, and saves over what it finds', function () {
        ConversationFunnelState::creating(function (ConversationFunnelState $state) {
            if (++$this->attempts === 1) {
                throw sfCollision(1062);
            }

            // Another process created it between the retry's read and its insert.
            if ($this->attempts === 2) {
                DB::table('conversation_funnel_states')->insert([
                    'conversation_id' => $state->conversation_id, 'product_id' => $state->product_id, 'sales_funnel_id' => $state->sales_funnel_id,
                    'stage' => 'inicio', 'stage_interactions' => 9, 'stage_entered_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);

                throw sfCollision(1062);
            }
        });

        $this->states->save($this->conversation, $this->product, $this->funnel, 'cierre', 1);

        expect(ConversationFunnelState::sole())->stage->toBe('cierre')->stage_interactions->toBe(1);
    });

    it('gives up after 3 retries and lets the error go up', function (int $code) {
        ConversationFunnelState::creating(function () use ($code) {
            $this->attempts++;

            throw sfCollision($code);
        });

        expect(fn () => sfStatesOutsideTransaction()->save($this->conversation, $this->product, $this->funnel, 'inicio'))
            ->toThrow(QueryException::class, (string) $code)
            ->and($this->attempts)->toBe(4)
            ->and(ConversationFunnelState::count())->toBe(0);
    })->with([1062, 1213]);

    it('does not retry a deadlock inside a transaction', function () {
        ConversationFunnelState::creating(function () {
            $this->attempts++;

            throw sfCollision(1213);
        });

        // The test's own transaction: a deadlock would have undone it all.
        expect(fn () => $this->states->save($this->conversation, $this->product, $this->funnel, 'inicio'))->toThrow(QueryException::class, '1213')
            ->and($this->attempts)->toBe(1);
    });

    it('does not retry other database errors', function () {
        ConversationFunnelState::creating(function () {
            $this->attempts++;

            throw sfCollision(1452);
        });

        expect(fn () => sfStatesOutsideTransaction()->save($this->conversation, $this->product, $this->funnel, 'inicio'))->toThrow(QueryException::class, '1452')
            ->and($this->attempts)->toBe(1);
    });

    it('does not retry errors of another kind', function () {
        ConversationFunnelState::creating(function () {
            $this->attempts++;

            throw new RuntimeException('otro problema');
        });

        expect(fn () => sfStatesOutsideTransaction()->save($this->conversation, $this->product, $this->funnel, 'inicio'))->toThrow(RuntimeException::class, 'otro problema')
            ->and($this->attempts)->toBe(1);
    });

    it('does not retry inconsistent data', function () {
        expect(fn () => $this->states->save($this->conversation, $this->product, $this->funnel, 'no-existe'))->toThrow(InvalidArgumentException::class, 'has no stage');

        Sleep::assertNeverSlept();
    });

    it('retries a deadlock while resetting, and forgets the state', function () {
        $this->states->save($this->conversation, $this->product, $this->funnel, 'inicio');
        ConversationFunnelState::deleting(function () {
            if (++$this->attempts === 1) {
                throw sfCollision(1213);
            }
        });

        expect(sfStatesOutsideTransaction()->reset($this->conversation, $this->product))->toBeTrue()
            ->and($this->attempts)->toBe(2)
            ->and(ConversationFunnelState::count())->toBe(0);
    });

    it('gives up resetting after 3 retries and keeps the state', function () {
        $this->states->save($this->conversation, $this->product, $this->funnel, 'inicio');
        ConversationFunnelState::deleting(function () {
            $this->attempts++;

            throw sfCollision(1213);
        });

        expect(fn () => sfStatesOutsideTransaction()->reset($this->conversation, $this->product))->toThrow(QueryException::class, '1213')
            ->and($this->attempts)->toBe(4)
            ->and(ConversationFunnelState::count())->toBe(1);
    });

    it('does not retry a reset for other errors', function () {
        $this->states->save($this->conversation, $this->product, $this->funnel, 'inicio');
        ConversationFunnelState::deleting(function () {
            $this->attempts++;

            throw new RuntimeException('otro problema');
        });

        expect(fn () => sfStatesOutsideTransaction()->reset($this->conversation, $this->product))->toThrow(RuntimeException::class)
            ->and($this->attempts)->toBe(1);
    });

    it('does not touch the free replies while retrying', function () {
        $sample = sfSample($this->product, 2);
        $before = $sample->fresh()->getAttributes();
        ConversationFunnelState::creating(function () {
            if (++$this->attempts === 1) {
                throw sfCollision(1062);
            }
        });

        $this->states->save($this->conversation, $this->product, $this->funnel, 'inicio');

        expect(SampleDelivery::sole()->getAttributes())->toBe($before);
    });
});

describe('the free replies counter', function () {
    it('does not change when publishing, restoring, deactivating or resetting the state', function () {
        $sample = sfSample($this->product, 2);
        $before = $sample->fresh()->getAttributes();
        $conversation = sfConversation($this->store);

        $first = $this->versions->publish($this->product, sfDefinition());
        $this->states->save($conversation, $this->product, $first, 'inicio', 3);
        $this->versions->publish($this->product, sfDefinition());
        $this->versions->restore($first);
        $this->states->reset($conversation, $this->product);
        $this->versions->deactivate($this->product);

        expect(SampleDelivery::sole()->getAttributes())->toBe($before);
    });

    it('stays out of the funnel state', function () {
        expect(Schema::getColumnListing('conversation_funnel_states'))->not->toContain('turns_used');
    });
});

describe('products without a funnel', function () {
    it('have no active version and keep their access decision', function () {
        $customer = Customer::create(['store_id' => $this->store->id, 'customer_phone' => '573001112233']);
        $decision = app(AccessGate::class)->decide($customer, $this->product);

        expect($this->product->fresh()->active_sales_funnel_id)->toBeNull()
            ->and($this->product->fresh()->activeSalesFunnel)->toBeNull()
            ->and($this->versions->active($this->product))->toBeNull()
            ->and($this->versions->history($this->product))->toBeEmpty()
            ->and($decision->allowed)->toBeFalse()
            ->and($decision->sampleAvailable)->toBeTrue();
    });

    it('do not change their access decision with a funnel either, in this phase', function () {
        $customer = Customer::create(['store_id' => $this->store->id, 'customer_phone' => '573001112233']);

        $this->versions->publish($this->product, sfDefinition());

        expect(app(AccessGate::class)->decide($customer, $this->product->fresh())->sampleAvailable)->toBeTrue();
    });
});
