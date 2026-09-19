<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A till (kassa) talking to pDaftar over the POS Integration API.
 *
 * One row per physical device, NOT per user: two cashiers sharing one till
 * are one terminal, and one cashier moving between two tills is two. That is
 * what makes the "how many kassa may this shop open?" limit meaningful and
 * what lets a sale be attributed to the machine it was rung up on.
 *
 * `provider` records WHOSE till it is. pDaftar POS is just the first client of
 * this API — AliPOS, YesPOS and anything else authenticate the same way and
 * are told apart only by this column plus their token's scopes. Deliberately
 * no privileged path for our own POS: an integration API that its author's
 * client bypasses stops being an integration API within a release or two.
 *
 * The terminal's token is a normal Sanctum personal access token belonging to
 * the pDaftar user, so every existing "who did this?" lookup keeps working.
 * We only remember WHICH token is this terminal's, so revoking the terminal
 * revokes exactly one credential and leaves the user's phone logged in.
 */
return new class extends Migration {
    public function up(): void {
        Schema::create('pos_terminals', function (Blueprint $table) {
            $table->id();

            // pDaftar's shop, by id — but NOT a foreign key. The POS runs on
            // its own server with its own database and reaches pDaftar over
            // the API, so `shops` does not exist here to point at. The id is
            // still the join: it is what every API call carries.
            //
            // What the database no longer enforces, the application must:
            // nothing here stops a row naming a shop that pDaftar deleted, so
            // the API layer is the only place that can catch it.
            $table->unsignedBigInteger('shop_id');
            // The pDaftar user this till acts as. Every write the terminal
            // makes is attributed to them, exactly as if they had done it in
            // the app — which is the whole point of the boss's "user tokeni
            // bilan amalga oshiriladi".
            $table->unsignedBigInteger('user_id')->index();

            $table->string('name', 120);

            // Client-generated, stable across reinstalls of the same till.
            // Unique per shop so a device re-registering re-uses its row
            // instead of consuming another kassa slot every launch.
            $table->string('device_id', 128);

            // pdaftar_pos | alipos | yespos | other
            $table->string('provider', 32)->default('pdaftar_pos');

            // The Sanctum token issued for this terminal. Nullable because the
            // row outlives its credential: revoking a till must not delete its
            // sales history.
            $table->unsignedBigInteger('access_token_id')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_sync_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['shop_id', 'device_id']);
            $table->index(['shop_id', 'is_active']);
            $table->index('access_token_id');
        });
    }

    public function down(): void {
        Schema::dropIfExists('pos_terminals');
    }
};
