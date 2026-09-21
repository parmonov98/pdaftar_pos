<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which sales were one basket.
 *
 * A customer buying a dollar-priced item and a so'm-priced one is one visit,
 * but it cannot be one `sales` row: a sale has one currency all the way down
 * to the debt it leaves behind, and a row holding both would have to carry a
 * total that is not money. So the till writes one sale per currency and
 * stamps them with a shared id — the basket stays a basket for the history
 * screen and for cancelling, while every invariant underneath stays intact.
 *
 * Nullable, and null is the norm: a single-currency sale has no group, which
 * is every sale written before this and nearly every one after.
 */
return new class extends Migration {
    public function up(): void {
        Schema::table('sales', function (Blueprint $table) {
            // Minted by the till, at the moment of the cashier's action, like
            // the operation id beside it — so a retry of a half-sent basket
            // re-uses the same group rather than splitting it in two.
            $table->string('sale_group_id', 64)->nullable()->after('pos_terminal_id');

            // The history screen looks sales up by it, per shop.
            $table->index(['shop_id', 'sale_group_id']);
        });
    }

    public function down(): void {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex(['shop_id', 'sale_group_id']);
            $table->dropColumn('sale_group_id');
        });
    }
};
