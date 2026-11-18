<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Optional: the opening goes out as its text, then this audio
            // (a private file on the 'local' disk) and then the follow-up
            // text. Off for every product until an administrator turns it on.
            $table->boolean('opening_audio_enabled')->default(false)->after('opening_triggers');
            $table->string('opening_audio_path')->nullable()->after('opening_audio_enabled');
            $table->text('opening_audio_followup')->nullable()->after('opening_audio_path');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['opening_audio_enabled', 'opening_audio_path', 'opening_audio_followup']);
        });
    }
};
