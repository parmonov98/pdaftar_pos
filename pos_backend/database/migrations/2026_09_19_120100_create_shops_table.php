<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The POS's own shops.
 *
 * pos_terminals.shop_id has always pointed at a shop; what changes is whose.
 * A standalone POS has to be able to answer "which business is this till in?"
 * without asking pDaftar, so the shop is created here at registration.
 *
 * `pdaftar_shop_id` mirrors the user table's link column. A linked shop keeps
 * pDaftar's id beside its own; an unlinked one simply has none.
 */
return new class extends Migration {
    public function up(): void {
        Schema::create('shops', function (Blueprint $table) {
            $table->id();

            $table->string('name', 160);
            $table->string('phone_number', 20)->nullable();
            $table->string('address', 255)->nullable();

            // Who registered it. The owner is also a member (see user_shop),
            // so permission checks have one path to follow rather than two.
            $table->unsignedBigInteger('owner_id')->index();

            $table->unsignedBigInteger('pdaftar_shop_id')->nullable()->unique();

            // How many tills this shop may open at once. Enforced at terminal
            // registration; a standalone POS has no tariff to read it from, so
            // it is a plain column with a sane default.
            $table->unsignedSmallInteger('terminal_limit')->default(2);

            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void {
        Schema::dropIfExists('shops');
    }
};
