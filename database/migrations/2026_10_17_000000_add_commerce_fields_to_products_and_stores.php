<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // 'open': no access control. 'subscription': the paid service
            // needs an active subscription (per customer and product).
            $table->string('access_mode')->default('open')->after('capability_key');
            // The free sample: what to offer is defined by these
            // instructions (and optionally a registered capability of the
            // product's domain), never by code.
            $table->text('sample_instructions')->nullable()->after('access_mode');
            $table->string('sample_capability_key')->nullable()->after('sample_instructions');
        });

        Schema::table('stores', function (Blueprint $table) {
            // Sent as-is to customers who start a payment (Nequi, Daviplata,
            // Llave...): one free text per store.
            $table->text('payment_instructions')->nullable()->after('alert_phone');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['access_mode', 'sample_instructions', 'sample_capability_key']);
        });

        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('payment_instructions');
        });
    }
};
