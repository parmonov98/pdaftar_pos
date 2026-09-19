<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The units one product can be sold in, and what each is worth in base units.
 *
 * "1 karobka = 12 dona" is a ratio, so it is stored as one — numerator over
 * denominator, not a decimal. A box of three sold by the piece is 1/3 per
 * piece, and 0.333333 does not add back up to a whole box: sell three and the
 * ledger is short by a rounding error that nobody can explain a month later.
 *
 * Same shape as pDaftar's, because the import has to land somewhere and a
 * different shape here would mean converting on the way in — which is where
 * the arithmetic would go wrong.
 */
return new class extends Migration {
    public function up(): void {
        Schema::create('product_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();

            // How many base units one of THIS unit is worth.
            $table->bigInteger('base_units_numerator')->default(1);
            $table->bigInteger('base_units_denominator')->default(1);

            // The base unit itself: numerator == denominator == 1. Exactly one
            // per product, enforced by the application.
            $table->boolean('is_base')->default(false);

            $table->boolean('is_active')->default(true);

            $table->unsignedBigInteger('pdaftar_product_unit_id')->nullable();

            $table->timestamps();

            $table->unique(['product_id', 'unit_id']);
            $table->index(['shop_id', 'product_id']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('product_units');
    }
};
