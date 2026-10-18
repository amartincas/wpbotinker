<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_sources', function (Blueprint $table) {
            // 'knowledge': what the topic knows (answers, samples), as before.
            // 'commercial': the product's (or topic's) sales resources, only
            // ever used by CommercialAdvisor.
            $table->string('purpose', 20)->default('knowledge')->after('product_id');
            // Commercial resources: 'reference' (the AI may word it) or
            // 'verbatim' (sent exactly as written, e.g. a testimonial).
            $table->string('delivery', 20)->default('reference')->after('purpose');
            $table->index(['store_id', 'domain_key', 'purpose']);
        });

        Schema::table('products', function (Blueprint $table) {
            // How this product is sold: guides which commercial resources fit
            // the customer's message and how the reply is worded. Optional.
            $table->text('commercial_instructions')->nullable()->after('activation_message');
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_sources', function (Blueprint $table) {
            $table->dropIndex(['store_id', 'domain_key', 'purpose']);
            $table->dropColumn(['purpose', 'delivery']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('commercial_instructions');
        });
    }
};
