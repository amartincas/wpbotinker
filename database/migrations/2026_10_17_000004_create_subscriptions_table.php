<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Paid access of a customer to one product. "Expired" is never
        // stored: a subscription is valid while active and expires_at is in
        // the future, so expiry needs no scheduled job.
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('active');     // active | revoked
            // DATETIME, not TIMESTAMP: a NOT NULL TIMESTAMP may get an
            // implicit default and ON UPDATE on MySQL/MariaDB.
            $table->dateTime('started_at');
            $table->dateTime('expires_at');
            $table->foreignId('last_payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->timestamps();

            $table->unique(['customer_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
