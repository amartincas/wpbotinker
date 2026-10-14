<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A store's own topic: a domain defined from the panel (label,
        // vocabulary, instructions, declared facts) instead of a PHP module.
        // Products join it through products.domain_key, like any domain.
        Schema::create('topics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('key', 50);                 // never changes once created
            $table->string('label', 100);
            $table->json('vocabulary')->nullable();
            $table->text('instructions');
            $table->json('facts')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['store_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('topics');
    }
};
