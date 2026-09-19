<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A sale rung up at a till.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();

            // Which machine, and which person. Both, because they answer
            // different questions: "which counter was this rung up at" and
            // "who rang it up". The terminal's own user_id changes when the
            // next cashier signs in, so attribution is captured here at write
            // time and never derived later.
            $table->foreignId('pos_terminal_id')->nullable()->constrained('pos_terminals')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('currency_id')->nullable()->constrained('currencies')->nullOnDelete();

            $table->decimal('subtotal', 20, 6)->default(0);
            $table->decimal('discount_amount', 20, 6)->default(0);
            $table->decimal('total', 20, 6)->default(0);
            $table->decimal('paid_amount', 20, 6)->default(0);

            // cash | card | transfer | null (nothing paid yet)
            $table->string('payment_type', 24)->nullable();

            $table->string('note', 255)->nullable();

            // completed | cancelled. A cancelled sale keeps its row and its
            // items; the stock is returned by reversing its movements, so the
            // receipt a customer is holding still resolves to something.
            $table->string('status', 16)->default('completed');
            $table->timestamp('cancelled_at')->nullable();

            // When the cashier took the money — see stock_movements.
            $table->timestamp('occurred_at')->index();

            $table->timestamps();

            $table->index(['shop_id', 'occurred_at']);
            $table->index(['shop_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales');
    }
};
