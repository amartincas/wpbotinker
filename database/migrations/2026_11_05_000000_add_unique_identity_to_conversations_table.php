<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A customer has one conversation per store: the store determines the
     * WhatsApp account (stores.wa_phone_number_id is unique) and WhatsApp is
     * the only channel. Customers already have this same identity.
     *
     * If a customer already has several, this migration stops before creating
     * the index and changes no data: merging them is a decision for a person,
     * never made automatically.
     */
    public function up(): void
    {
        $duplicates = DB::table('conversations')
            ->select('store_id', 'customer_phone')
            ->groupBy('store_id', 'customer_phone')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($duplicates->isNotEmpty()) {
            $storeIds = $duplicates->pluck('store_id')->unique()->sort()->implode(', ');

            throw new RuntimeException(
                "{$duplicates->count()} customer(s) have several conversations in one store (store ids: {$storeIds}). "
                .'Merge them, keeping their data, and run the migration again. No data was changed.'
            );
        }

        Schema::table('conversations', function (Blueprint $table) {
            $table->unique(['store_id', 'customer_phone']);
        });
    }

    /**
     * MariaDB gives up the foreign key's own index of store_id when the unique
     * one covers it, so the foreign key needs another before this one goes.
     * An earlier rollback may have left it there already.
     */
    public function down(): void
    {
        $needsIndex = ! Schema::hasIndex('conversations', ['store_id']);

        Schema::table('conversations', function (Blueprint $table) use ($needsIndex) {
            if ($needsIndex) {
                $table->index('store_id');
            }

            $table->dropUnique(['store_id', 'customer_phone']);
        });
    }
};
