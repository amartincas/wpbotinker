<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Fact keys (declared by the product domain) the customer must
            // provide before the product answers. Checked against the domain
            // by DomainRegistry::assertProductConfiguration().
            $table->json('required_facts')->nullable()->after('aliases');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('required_facts');
        });
    }
};
