<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A price, per unit, per currency, per kind.
 *
 * One product does not have "a price". A shop that sells cola by the bottle
 * and by the box, in so'm and in dollars, cash and on credit, has eight
 * prices for one product — and none of them is derivable from the others,
 * because a box is cheaper per bottle and credit costs more than cash.
 *
 * `products.price` stays as the base unit's cash price in the shop's own
 * currency: it is what a one-unit, one-currency shop needs and what every
 * list screen shows. Rows here override it for everything else.
 */
return new class extends Migration {
    public function up(): void {
        Schema::create('product_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('product_unit_id')->constrained('product_units')->cascadeOnDelete();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();

            // sale | credit. Credit is dearer in practice, and a shop that
            // charges the same can simply not have the row.
            $table->string('price_type', 16)->default('sale');

            $table->decimal('amount', 20, 6);

            $table->unsignedBigInteger('pdaftar_product_price_id')->nullable();

            $table->timestamps();

            $table->unique(
                ['product_id', 'product_unit_id', 'currency_id', 'price_type'],
                'product_prices_tuple_unique',
            );
            $table->index(['shop_id', 'product_id']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('product_prices');
    }
};
