<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Column order and types follow CONTRACTS.md section 5.
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('title', 512);
            $table->unsignedBigInteger('price_minor');
            $table->char('currency', 3);
            $table->text('image_url');

            // Unique on the FULL column, not a prefix. On MySQL 8.4 (DYNAMIC row
            // format) the InnoDB index-key limit is 3072 bytes; 768 utf8mb4
            // characters is exactly that. A prefix index would let two products
            // whose URLs share a long prefix collide and updateOrCreate would
            // overwrite one with the other — silent data loss.
            $table->string('source_url', 768)->unique();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
