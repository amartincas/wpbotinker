<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Permission for a domain to read a person fact. One row per grant;
        // revoking keeps the row (revoked_at) as a record.
        Schema::create('customer_fact_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('fact_key');
            $table->string('grantee_domain_key');
            $table->string('basis');                         // provided | consented
            $table->timestamp('granted_at')->useCurrent(); // explicit default: no implicit ON UPDATE on MySQL/MariaDB
            $table->timestamp('revoked_at')->nullable();
            // "{fact_key}:{grantee_domain_key}" while active, NULL once
            // revoked (kept by the model): one active grant per pair.
            $table->string('active_marker')->nullable();
            $table->timestamps();

            $table->unique(['customer_id', 'active_marker']);
            $table->index(['customer_id', 'fact_key', 'grantee_domain_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_fact_shares');
    }
};
