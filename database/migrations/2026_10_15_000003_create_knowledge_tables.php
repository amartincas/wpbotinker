<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A knowledge source of one store and domain, optionally of one
        // product. The original content is kept; replacing a source creates
        // a new version and retires the previous one.
        Schema::create('knowledge_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('domain_key');
            $table->foreignId('product_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('source_type');                   // markdown | html | text
            $table->string('origin')->nullable();            // URL or file name
            $table->string('license')->nullable();
            $table->string('language', 10)->default('es');
            $table->unsignedInteger('version');
            $table->string('content_hash', 64);
            $table->longText('original_content');
            $table->string('status');                        // active | retired
            $table->timestamp('ingested_at')->useCurrent(); // explicit default: no implicit ON UPDATE on MySQL/MariaDB
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'domain_key', 'status']);
        });

        Schema::create('knowledge_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('knowledge_source_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('domain_key');
            $table->foreignId('product_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('heading')->nullable();
            $table->text('content');
            $table->timestamps();

            $table->index(['store_id', 'domain_key', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_chunks');
        Schema::dropIfExists('knowledge_sources');
    }
};
