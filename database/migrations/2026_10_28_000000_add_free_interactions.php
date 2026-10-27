<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sample_deliveries', function (Blueprint $table) {
            // Free replies already given to the customer for the product, up to
            // the Core's global limit (botinker.free_interactions.max). An
            // existing row was a one-reply sample: it counts as one used.
            $table->unsignedTinyInteger('turns_used')->default(1)->after('capability_key');
        });

        Schema::table('conversations', function (Blueprint $table) {
            // product id => when the plan list was last shown unasked, so a
            // commercial reply does not repeat it every time.
            $table->json('offers_shown')->nullable()->after('pending_state');
        });
    }

    public function down(): void
    {
        Schema::table('sample_deliveries', function (Blueprint $table) {
            $table->dropColumn('turns_used');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn('offers_shown');
        });
    }
};
