<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One payment attempt of a customer for a subscription product. The
        // plan's price and duration are copied at creation and never change.
        // Only a human review confirms it.
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            // A product with payments cannot be deleted: they are its record.
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $table->string('plan_name');
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3);
            $table->unsignedSmallInteger('duration_days');
            // pending | under_review | not_reflected | confirmed | rejected | expired
            $table->string('status')->default('pending');
            // Normalized payment reference, to spot one reused across payments.
            $table->string('reference')->nullable();
            // Nullable timestamps: no implicit default or ON UPDATE on MySQL/MariaDB.
            $table->timestamp('expires_at')->nullable();        // pending without receipt until then
            $table->timestamp('receipt_submitted_at')->nullable();
            $table->timestamp('review_due_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            // "product:{id}" while the payment is open, NULL once closed
            // (kept by the model): one open payment per customer and product.
            $table->string('open_marker')->nullable();
            $table->timestamps();

            $table->unique(['customer_id', 'open_marker']);
            $table->index(['store_id', 'status']);
            $table->index(['store_id', 'reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
