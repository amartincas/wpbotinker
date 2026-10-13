<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Deterministic capability of the product domain to run before
            // answering ("{domain}.{name}"). Checked against the domain by
            // DomainRegistry::assertProductConfiguration().
            $table->string('capability_key')->nullable()->after('required_facts');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('capability_key');
        });
    }
};
