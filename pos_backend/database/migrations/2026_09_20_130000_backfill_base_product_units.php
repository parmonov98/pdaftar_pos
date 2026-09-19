<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Give every existing product the base unit it should always have had.
 *
 * Products created before multi-unit pricing have no product_units row at
 * all. Most code copes — a missing base unit falls back to 1:1 — but the
 * catalogue pull then sends `units: []`, so the till shows no unit picker and
 * a shop that adds a box to an old product gets a list with the box in it and
 * not the bottle.
 *
 * Runs once, skips anything already correct, and cannot double-insert: the
 * unique key is (product_id, unit_id).
 */
return new class extends Migration {
    public function up(): void {
        $now = now();

        DB::table('products')
            ->whereNull('deleted_at')
            ->whereNotNull('unit_id')
            ->orderBy('id')
            ->chunkById(500, function ($products) use ($now) {
                $rows = [];

                foreach ($products as $product) {
                    $exists = DB::table('product_units')
                        ->where('product_id', $product->id)
                        ->where('unit_id', $product->unit_id)
                        ->exists();

                    if ($exists) {
                        continue;
                    }

                    $rows[] = [
                        'shop_id' => $product->shop_id,
                        'product_id' => $product->id,
                        'unit_id' => $product->unit_id,
                        'base_units_numerator' => 1,
                        'base_units_denominator' => 1,
                        'is_base' => true,
                        'is_active' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($rows !== []) {
                    DB::table('product_units')->insert($rows);
                }
            });
    }

    public function down(): void {
        // Nothing to undo that can be told apart from a unit somebody added
        // on purpose. Removing every 1:1 base row would delete real data.
    }
};
