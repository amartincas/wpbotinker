<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description');
            $table->decimal('price', 10, 2);
            $table->integer('stock')->default(0);
            $table->timestamps();

            // SQLite (used by the test suite) has no FULLTEXT support. MySQL/
            // MariaDB keep creating the index exactly as before.
            if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                $table->fullText(['name', 'description']);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
