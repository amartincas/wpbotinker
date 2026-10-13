<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Domain module this product belongs to (key registered in
            // config/botinker.php). Null = a plain sales product.
            $table->string('domain_key')->nullable()->after('store_id')->index();
            // Alternative names a customer may use for the product.
            $table->json('aliases')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['domain_key']);
            $table->dropColumn(['domain_key', 'aliases']);
        });
    }
};
