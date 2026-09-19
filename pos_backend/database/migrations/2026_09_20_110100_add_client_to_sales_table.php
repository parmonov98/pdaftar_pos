<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who a sale was made to.
 *
 * Null for the overwhelming majority — someone paid and left. Set when the
 * shop has to remember the person, which in practice means the sale was not
 * paid in full.
 */
return new class extends Migration {
    public function up(): void {
        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->after('user_id')
                ->constrained('clients')->nullOnDelete();

            $table->index(['shop_id', 'client_id']);
        });
    }

    public function down(): void {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex(['shop_id', 'client_id']);
            $table->dropConstrainedForeignId('client_id');
        });
    }
};
