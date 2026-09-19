<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * People the shop sells to on credit.
 *
 * A cash customer needs no row here — most sales have no client at all. This
 * table exists for the ones the shop has to remember: who owes what.
 */
return new class extends Migration {
    public function up(): void {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();

            $table->string('name', 191);

            // The phone is how a debt gets chased, so it matters more here
            // than anywhere else in the catalogue. Still optional: a regular
            // whose number nobody has is better recorded than not recorded.
            $table->string('phone_number', 20)->nullable();
            $table->string('note', 255)->nullable();

            $table->unsignedBigInteger('pdaftar_client_id')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['shop_id', 'name']);
            $table->index(['shop_id', 'phone_number']);
            $table->unique(['shop_id', 'pdaftar_client_id']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('clients');
    }
};
