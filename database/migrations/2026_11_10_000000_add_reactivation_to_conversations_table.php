<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            // The episode of silence a reactivation was claimed for: the
            // last_session_at it started from. Claiming is not sending; a sent
            // reactivation is its whatsapp_messages row with a wamid.
            $table->timestamp('reactivation_episode_at')->nullable();

            // The customer asked not to be written to again: no automatic
            // reactivation from then on. Nothing else depends on it.
            $table->timestamp('reactivation_opted_out_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['reactivation_episode_at', 'reactivation_opted_out_at']);
        });
    }
};
