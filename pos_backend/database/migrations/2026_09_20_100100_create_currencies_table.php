<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Currencies a till can price in.
 *
 * Not per shop — "UZS" means the same everywhere — but seeded per install so
 * an offline POS never waits on a central list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->string('code', 8)->unique();
            $table->string('name', 48);
            $table->string('sign', 8)->nullable();
            $table->unsignedBigInteger('pdaftar_currency_id')->nullable()->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('currencies');
    }
};
