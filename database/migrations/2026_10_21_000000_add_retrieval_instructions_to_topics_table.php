<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('topics', function (Blueprint $table) {
            // How this topic relates the customer's data to the section
            // titles of its knowledge sources (KnowledgePlanner). Optional;
            // untrusted text, never executed.
            $table->text('retrieval_instructions')->nullable()->after('instructions');
        });
    }

    public function down(): void
    {
        Schema::table('topics', function (Blueprint $table) {
            $table->dropColumn('retrieval_instructions');
        });
    }
};
