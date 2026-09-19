<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which currency the money came back in.
 *
 * Without it a repayment is a bare number, and the balance that subtracts it
 * from a debt cannot know whether 11 means eleven dollars or eleven som. A
 * shop selling in two currencies would have had a dollar wipe out a som.
 *
 * Existing rows are filled from their shop's own currency: before this column
 * there was no way to take money in anything else, so that is not a guess.
 */
return new class extends Migration {
    public function up(): void {
        Schema::table('client_payments', function (Blueprint $table) {
            $table->foreignId('currency_id')->nullable()->after('amount')
                ->constrained('currencies')->nullOnDelete();
        });

        DB::table('client_payments')
            ->whereNull('currency_id')
            ->update([
                'currency_id' => DB::raw('(select currency_id from shops where shops.id = client_payments.shop_id)'),
            ]);
    }

    public function down(): void {
        Schema::table('client_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('currency_id');
        });
    }
};
