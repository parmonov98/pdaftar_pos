<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every change in stock, as a delta. The ledger, not a counter.
 *
 * `products.quantity` is a cache recomputed from these rows. That is what
 * makes a cancelled sale a matter of removing rows rather than remembering to
 * add quantities back by hand on every cancel path — and it is what makes two
 * tills that were offline all day safe to sync in either order: deltas
 * commute, so -3 then -2 lands on the same balance as -2 then -3.
 */
return new class extends Migration {
    public function up(): void {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();

            // sale | return | purchase | adjustment | write_off | opening
            $table->string('type', 16);

            // Signed: negative takes stock out. Never an absolute balance —
            // an absolute value cannot be replayed or reordered.
            $table->decimal('quantity', 20, 6);

            /*
             * When the cashier did it, NOT when the server heard about it.
             *
             * These differ by however long the till was offline, and that gap
             * is the only thing that can answer "why is today's report short?".
             * It also decides what an absolute operation means: a stocktake
             * counted at 10:00 and synced at 18:00 must be measured against
             * the 10:00 balance, or it silently erases every sale another till
             * made in between.
             */
            $table->timestamp('occurred_at')->index();

            // What caused it — a sale, a delivery, a manual correction.
            $table->string('source_type', 64)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();

            $table->string('note', 255)->nullable();

            // Who. Captured here rather than derived from the terminal, which
            // changes hands when the next cashier signs in.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['shop_id', 'product_id']);
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('stock_movements');
    }
};
