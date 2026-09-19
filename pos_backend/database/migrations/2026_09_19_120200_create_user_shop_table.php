<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who may open a till in which shop.
 *
 * The same shape pDaftar's user_shops has, because the question the POS asks
 * of it is the same one PosTerminalService already asked: "does this user
 * belong to this shop?" Keeping the shape means that check keeps its logic and
 * only changes which table it reads.
 *
 * An owner gets a row here too. One membership path, not an owner special case
 * plus a member path that drift apart.
 */
return new class extends Migration {
    public function up(): void {
        Schema::create('user_shop', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();

            // owner | seller. Deliberately not a permission system yet: the
            // POS has exactly one thing to decide today, which is whether a
            // person may open a till here.
            $table->string('role', 24)->default('seller');

            $table->timestamps();
            // PosTerminalService and PosTerminalController both filter
            // membership with whereNull('deleted_at'). Removing a seller from
            // a shop must not erase which sales they rang up, so membership is
            // withdrawn rather than deleted.
            $table->softDeletes();

            // Scoped to live rows: re-adding a seller you removed last month
            // must not collide with their withdrawn membership.
            $table->unique(['user_id', 'shop_id']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('user_shop');
    }
};
