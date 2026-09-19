<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One line of a sale.
 *
 * The product's name and unit are copied in, not just referenced. A receipt
 * printed last month must still read the way it read then — renaming a product
 * or deleting it cannot be allowed to rewrite history. The foreign key stays
 * for "how much of this did we sell?", the snapshot answers "what did this
 * customer buy?".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();

            $table->string('name', 191);
            $table->string('code', 64)->nullable();
            $table->string('barcode', 64)->nullable();

            $table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->string('unit_name', 48)->nullable();

            $table->decimal('quantity', 20, 6);
            $table->decimal('price', 20, 6);
            $table->decimal('total', 20, 6);

            $table->timestamps();

            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_items');
    }
};
