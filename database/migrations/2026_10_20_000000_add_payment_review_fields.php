<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_receipts', function (Blueprint $table) {
            // When the store admin was alerted about this receipt: an alert
            // is sent once per receipt, retries included.
            $table->timestamp('alerted_at')->nullable()->after('file_deleted_at');
        });

        Schema::table('whatsapp_templates', function (Blueprint $table) {
            // Meta-approved template used when the 24-hour window is closed:
            // 'payment_alert' (to the store admin), 'payment_result' (to the
            // customer). Same idea as WpBotTrainer's event_key.
            $table->string('event_key')->nullable()->after('is_lead_alert');
            $table->index(['store_id', 'event_key']);
        });
    }

    public function down(): void
    {
        Schema::table('payment_receipts', function (Blueprint $table) {
            $table->dropColumn('alerted_at');
        });

        Schema::table('whatsapp_templates', function (Blueprint $table) {
            $table->dropIndex(['store_id', 'event_key']);
            $table->dropColumn('event_key');
        });
    }
};
