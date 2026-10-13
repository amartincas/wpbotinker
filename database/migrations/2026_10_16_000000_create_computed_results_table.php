<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cached output of a deterministic capability for one customer.
        // input_hash covers the capability key, its version() and the exact
        // fact versions used, so a changed fact or capability never reuses
        // an old result.
        Schema::create('computed_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('domain_key');
            $table->string('capability_key');
            $table->string('capability_version');
            $table->string('input_hash', 64);
            $table->json('input_fact_ids');                  // fact key => customer_facts.id (no values)
            $table->text('payload');                         // encrypted: data + summary
            $table->timestamp('computed_at')->useCurrent(); // explicit default: no implicit ON UPDATE on MySQL/MariaDB
            $table->timestamps();

            $table->unique(['customer_id', 'capability_key', 'input_hash']);
            $table->index(['store_id', 'customer_id', 'domain_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('computed_results');
    }
};
