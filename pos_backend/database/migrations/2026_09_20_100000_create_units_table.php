<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Units of sale — dona, kg, litr, karobka.
 *
 * Per shop, not global: one shop's "karobka" is twelve bottles and another's
 * is six, and a shared row would make one of them wrong.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();

            $table->string('name', 48);
            $table->string('short_name', 16)->nullable();

            // The unit a new product gets when nobody picks one. Exactly one
            // per shop; enforced in the application, because a partial unique
            // index is not portable across the databases this runs on.
            $table->boolean('is_default')->default(false);

            $table->unsignedBigInteger('pdaftar_unit_id')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['shop_id', 'name']);
            $table->unique(['shop_id', 'pdaftar_unit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};
