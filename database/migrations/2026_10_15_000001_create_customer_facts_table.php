<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only versions of what we know about a customer.
        // identity_key = "person:{fact}" or "domain:{domain}:{fact}".
        Schema::create('customer_facts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('scope');                         // person | domain
            $table->string('owner_domain_key')->nullable();  // domain facts only
            $table->string('fact_key');
            $table->string('identity_key');
            $table->unsignedInteger('version');
            $table->string('status');                        // current | superseded
            $table->text('value');                           // encrypted
            $table->string('source');                        // customer_answer | operator
            $table->string('collected_by_domain')->nullable();
            $table->string('source_wamid')->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('collected_at')->useCurrent(); // explicit default: no implicit ON UPDATE on MySQL/MariaDB
            $table->foreignId('superseded_by_id')->nullable()->constrained('customer_facts')->nullOnDelete();
            // identity_key while current, NULL otherwise (kept by the model):
            // the unique index allows a single current version per identity.
            // A plain column rather than a generated one, for portability.
            $table->string('current_marker')->nullable();
            $table->timestamps();

            $table->unique(['customer_id', 'identity_key', 'version']);
            $table->unique(['customer_id', 'current_marker']);
            $table->index(['store_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_facts');
    }
};
