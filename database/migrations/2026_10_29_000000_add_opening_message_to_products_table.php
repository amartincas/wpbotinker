<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Subscription products: sent exactly as written as the first free
            // reply when the customer's first message is one of the triggers
            // (an ad's pre-filled message). Empty: no opening.
            $table->text('opening_message')->nullable()->after('sample_closing_message');
            $table->json('opening_triggers')->nullable()->after('opening_message');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['opening_message', 'opening_triggers']);
        });
    }
};
