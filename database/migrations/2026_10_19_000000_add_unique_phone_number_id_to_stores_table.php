<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A WhatsApp phone number id routes incoming webhooks to exactly one
     * store. If two stores already share one, this migration stops before
     * creating the index and changes no data: which store keeps the number
     * is a decision for a person, never made automatically. Stores without
     * a number (NULL) are not affected.
     */
    public function up(): void
    {
        $shared = DB::table('stores')
            ->whereNotNull('wa_phone_number_id')
            ->groupBy('wa_phone_number_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('wa_phone_number_id');

        if ($shared->isNotEmpty()) {
            $storeIds = DB::table('stores')->whereIn('wa_phone_number_id', $shared)->orderBy('id')->pluck('id')->implode(', ');

            throw new RuntimeException(
                "{$shared->count()} WhatsApp phone number id(s) are shared by several stores (store ids: {$storeIds}). "
                .'Give each store its own number, or clear it, and run the migration again. No data was changed.'
            );
        }

        Schema::table('stores', function (Blueprint $table) {
            $table->unique('wa_phone_number_id');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropUnique(['wa_phone_number_id']);
        });
    }
};
