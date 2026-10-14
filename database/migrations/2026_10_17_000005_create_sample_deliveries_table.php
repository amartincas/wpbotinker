<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The free sample of a product, delivered once per customer. A
        // record of what was given, not access: it has no status and no
        // expiry, and it never grants the paid service.
        Schema::create('sample_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('capability_key')->nullable();
            // DATETIME: see the subscriptions migration.
            $table->dateTime('delivered_at');
            $table->timestamps();

            $table->unique(['customer_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sample_deliveries');
    }
};
