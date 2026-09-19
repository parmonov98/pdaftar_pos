<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money coming back against a debt.
 *
 * A ledger, like stock: the balance a client owes is the sum of what they
 * took on credit minus the sum of these rows. Nothing stores the balance
 * itself, because a stored balance and the rows that produced it drift, and
 * the drift is only ever discovered by asking someone to pay twice.
 */
return new class extends Migration {
    public function up(): void {
        Schema::create('client_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();

            // Against one sale, or against the running balance. Both happen:
            // "this is for the bread from Tuesday" and "here is 50,000 off
            // what I owe" are different acts and the receipt should say which.
            $table->foreignId('sale_id')->nullable()->constrained('sales')->nullOnDelete();

            $table->decimal('amount', 20, 6);
            $table->string('payment_type', 24)->nullable();
            $table->string('note', 255)->nullable();

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('pos_terminal_id')->nullable()->constrained('pos_terminals')->nullOnDelete();

            // When the money changed hands, not when the till reached a
            // network — see stock_movements.
            $table->timestamp('occurred_at')->index();

            $table->timestamps();

            $table->index(['shop_id', 'client_id']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('client_payments');
    }
};
