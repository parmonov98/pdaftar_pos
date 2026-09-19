<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the shop sells.
 *
 * The POS's own catalogue. A product created here needs pDaftar for nothing;
 * `pdaftar_product_id` is null until an import links the two, and stays null
 * forever for a shop that never connects.
 */
return new class extends Migration {
    public function up(): void {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();

            // `code` is the human-facing reference someone reads aloud over the
            // phone; `barcode` is what the scanner reads off the packaging.
            // Two different things with two different lifetimes — pDaftar
            // learned this the hard way and split them in a later migration,
            // so they start separate here.
            $table->string('code', 64)->nullable();
            $table->string('barcode', 64)->nullable();

            $table->string('name', 191);

            // The base unit's sale price, in `currency_id`. Per-unit prices for
            // shops that sell the same product by box and by piece live in
            // product_prices; this is the one every product has.
            $table->decimal('price', 20, 6)->nullable();

            $table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->foreignId('currency_id')->nullable()->constrained('currencies')->nullOnDelete();

            // Cache of the stock_movements ledger, not the truth. NULL means
            // "never inventoried", which is NOT zero: the till shows no stock
            // figure for those rather than an out-of-stock badge on the whole
            // catalogue.
            $table->decimal('quantity', 20, 6)->nullable();

            // Opt-in "tell me when it runs low". Null = no alert.
            $table->decimal('low_stock_threshold', 20, 6)->nullable();

            // A URL, never a file. An imported product points at pDaftar's S3
            // object; a POS-native one can point anywhere or nowhere.
            $table->string('image_url', 512)->nullable();

            $table->boolean('is_active')->default(true);

            $table->unsignedBigInteger('pdaftar_product_id')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Scanning is the hot path: one index, shop-scoped, so a scan in a
            // 20 000-product catalogue is a single seek.
            $table->index(['shop_id', 'barcode']);
            $table->index(['shop_id', 'code']);
            $table->index(['shop_id', 'name']);
            // One pDaftar product maps to at most one POS product per shop, so
            // a repeated import updates rather than duplicating.
            $table->unique(['shop_id', 'pdaftar_product_id']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('products');
    }
};
