<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Each row is a published version of a product's sales funnel: never
        // edited, a new publication is a new row. Restoring publishes a copy.
        Schema::create('sales_funnels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('definition');
            // What the product said when this version was published.
            $table->json('product_snapshot');
            $table->foreignId('restored_from_id')->nullable()->constrained('sales_funnels')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // DATETIME: see the subscriptions migration.
            $table->dateTime('published_at');
            $table->timestamps();

            $table->unique(['product_id', 'version']);
        });

        // A single column: a product cannot have two active versions. Empty,
        // the product works as it did before funnels existed.
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('active_sales_funnel_id')->nullable()->constrained('sales_funnels')->nullOnDelete();
        });

        // Where a conversation is in a product's funnel. The free replies are
        // not here: they stay in sample_deliveries, whatever happens to this.
        Schema::create('conversation_funnel_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_funnel_id')->constrained()->cascadeOnDelete();
            $table->string('stage', 100);
            $table->unsignedInteger('stage_interactions')->default(0);
            $table->json('context')->nullable();
            $table->dateTime('stage_entered_at');
            $table->timestamps();

            $table->unique(['conversation_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_funnel_states');

        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('active_sales_funnel_id');
        });

        Schema::dropIfExists('sales_funnels');
    }
};
