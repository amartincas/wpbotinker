<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            // Domain a 'knowledge' turn belonged to, so the LLM only gets the
            // history of the domain being talked about. Null for the sales
            // flow, operator messages and clarification questions.
            $table->string('domain_key')->nullable()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->dropColumn('domain_key');
        });
    }
};
