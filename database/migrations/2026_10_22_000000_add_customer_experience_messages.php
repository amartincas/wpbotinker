<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            // Sent once, with the first reply to a customer who never wrote
            // to this store before (knowledge mode). Optional.
            $table->text('welcome_message')->nullable()->after('payment_instructions');
        });

        Schema::table('products', function (Blueprint $table) {
            // Closes the free sample, before the plans of the product.
            $table->text('sample_closing_message')->nullable()->after('sample_capability_key');
            // Added to the payment confirmation sent as text: how to use the
            // service, example questions, an invitation to start.
            $table->text('activation_message')->nullable()->after('sample_closing_message');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('welcome_message');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['sample_closing_message', 'activation_message']);
        });
    }
};
