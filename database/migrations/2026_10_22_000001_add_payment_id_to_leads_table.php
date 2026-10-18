<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            // The confirmed subscription payment this lead records (knowledge
            // mode, PaymentObserver). Unique: one lead per payment, whatever
            // the retries. Sales leads leave it empty.
            $table->foreignId('payment_id')->nullable()->unique()->after('product_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_id');
        });
    }
};
