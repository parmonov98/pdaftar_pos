<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The shop's own currency, and the defaults a till reads from it.
 *
 * Added after `currencies` exists rather than in the original shops migration,
 * because the foreign key needs the table it points at to be there first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->foreignId('currency_id')->nullable()->after('address')
                ->constrained('currencies')->nullOnDelete();

            $table->foreignId('default_unit_id')->nullable()->after('currency_id')
                ->constrained('units')->nullOnDelete();

            // Selling below zero is allowed by default: refusing a sale that
            // physically happened loses the record without un-selling anything.
            $table->boolean('allow_negative_stock')->default(true)->after('default_unit_id');

            // Shop-wide "tell me when anything runs low". A product's own
            // threshold wins where it has one.
            $table->decimal('low_stock_threshold', 20, 6)->nullable()->after('allow_negative_stock');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropConstrainedForeignId('currency_id');
            $table->dropConstrainedForeignId('default_unit_id');
            $table->dropColumn(['allow_negative_stock', 'low_stock_threshold']);
        });
    }
};
