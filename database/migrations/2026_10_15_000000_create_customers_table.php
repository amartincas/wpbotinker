<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A customer of one store (application). The same phone in two
        // stores is two customers that share nothing.
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('customer_phone');
            $table->timestamps();

            $table->unique(['store_id', 'customer_phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
