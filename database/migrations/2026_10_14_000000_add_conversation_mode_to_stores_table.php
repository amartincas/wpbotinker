<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            // 'sales' = the existing commercial flow (default, every store
            // keeps it). 'knowledge' = the domain conversation pipeline
            // (App\Platform\Conversation\ConversationPipeline).
            $table->string('conversation_mode')->default('sales')->after('system_prompt');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('conversation_mode');
        });
    }
};
