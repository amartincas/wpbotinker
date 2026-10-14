<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Proof of payment sent by the customer for an already started
        // payment. The file lives only while the payment is under review;
        // once it is confirmed or rejected the file is deleted and only
        // these metadata remain.
        Schema::create('payment_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->string('source_wamid')->nullable();
            $table->string('file_path')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedInteger('file_size')->nullable();
            // Kept after the file is deleted, so a resent image is still
            // recognized.
            $table->string('file_hash', 64)->nullable();
            $table->text('extracted_data')->nullable();          // encrypted
            $table->timestamp('file_deleted_at')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'file_hash']);
            $table->unique(['store_id', 'source_wamid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_receipts');
    }
};
