<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which unit a line was sold in, and what it was worth at the time.
 *
 * The conversion is copied onto the line, not looked up from product_units
 * when someone reads the sale back. A shop that redefines "karobka" from
 * twelve to six — a supplier changed the packaging — must not thereby change
 * what last month's sales meant. Without the snapshot, every historical box
 * silently becomes half a box.
 *
 * `base_quantity` is what the stock ledger moved, kept so a report never has
 * to redo the arithmetic and never has to guess at rounding.
 */
return new class extends Migration {
    public function up(): void {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->foreignId('product_unit_id')->nullable()->after('unit_id')
                ->constrained('product_units')->nullOnDelete();

            $table->bigInteger('conversion_numerator')->default(1)->after('unit_name');
            $table->bigInteger('conversion_denominator')->default(1)->after('conversion_numerator');

            // quantity × numerator ÷ denominator, in the product's base unit.
            $table->decimal('base_quantity', 20, 6)->nullable()->after('quantity');

            $table->foreignId('currency_id')->nullable()->after('price')
                ->constrained('currencies')->nullOnDelete();
        });
    }

    public function down(): void {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_unit_id');
            $table->dropConstrainedForeignId('currency_id');
            $table->dropColumn(['conversion_numerator', 'conversion_denominator', 'base_quantity']);
        });
    }
};
