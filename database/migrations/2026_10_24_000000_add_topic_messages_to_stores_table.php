<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            // Knowledge mode, several products: asked when a message names no
            // topic (a greeting) instead of listing the catalog. Empty: the
            // catalog is listed, as before.
            $table->text('topic_prompt_message')->nullable()->after('welcome_message');
            // Sent when the message asks about a topic the store does not
            // have, or its sources cannot answer it. Empty: the system's
            // default replies.
            $table->text('topic_unavailable_message')->nullable()->after('topic_prompt_message');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn(['topic_prompt_message', 'topic_unavailable_message']);
        });
    }
};
