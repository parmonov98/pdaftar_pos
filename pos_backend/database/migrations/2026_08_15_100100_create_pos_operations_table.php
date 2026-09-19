<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The idempotency ledger for everything a till writes.
 *
 * An offline POS retries. It retries because the phone dropped Wi-Fi
 * mid-request, because the cashier tapped Sinxronlash twice, because the app
 * restarted with a full outbox. Without a record of what was already applied,
 * every one of those turns into a duplicate sale and a double stock
 * decrement — the single most common way an offline till corrupts a ledger.
 *
 * So every write carries a client-generated `client_operation_id` (UUID minted
 * on the till, at the moment the cashier acted, NOT at send time). We store it
 * with the response we produced. A replay returns that stored response
 * verbatim and touches nothing.
 *
 * `request_hash` exists to catch the nastier case: the same UUID arriving with
 * a DIFFERENT body. That is a client bug, not a retry, and silently returning
 * the old response would hide it — we answer 409 instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_operations', function (Blueprint $table) {
            $table->id();

            // This one stays a real foreign key: pos_terminals is the POS's
            // own table, in the POS's own database. Cascade still applies.
            $table->foreignId('pos_terminal_id')->constrained('pos_terminals')->cascadeOnDelete();
            // pDaftar's shop, by id only — `shops` lives in pDaftar's database
            // on pDaftar's server. See the note in the pos_terminals migration.
            $table->unsignedBigInteger('shop_id');

            // UUID minted on the till. Unique PER TERMINAL, not globally: two
            // tills generating the same UUID is astronomically unlikely, but
            // scoping the constraint costs nothing and keeps one misbehaving
            // device from blocking writes for another.
            $table->uuid('client_operation_id');

            // sale.create | product.create | cash.income | ... — the shared
            // operation vocabulary, identical whether it arrived as a single
            // REST call or inside a /sync/push batch.
            $table->string('type', 48);

            // pending | applied | failed | error.
            //
            // `pending` is written BEFORE the handler runs, so the unique index
            // below is what serialises two concurrent copies of the same retry —
            // the loser sees the row and backs off instead of both selling.
            //
            // `failed` vs `error` is the retry decision: failed = refused before
            // any write (safe to replay under the same id), error = blew up in a
            // way that may have written (sealed; needs a human). See
            // PosIdempotencyService.
            $table->string('status', 16)->default('pending');

            $table->char('request_hash', 64);

            // What the entity became, so a replay can answer without redoing
            // the work and support can trace a receipt back to its row.
            $table->string('entity_type', 64)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();

            $table->json('response')->nullable();
            $table->text('error')->nullable();

            // When the cashier actually did this on the till. Diverges from
            // created_at by however long the device was offline, and that gap
            // is exactly what a "why is my report short today?" investigation
            // needs to see.
            $table->timestamp('occurred_at')->nullable();

            $table->timestamps();

            $table->unique(['pos_terminal_id', 'client_operation_id'], 'pos_operations_terminal_client_op_unique');
            $table->index(['shop_id', 'type']);
            $table->index(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_operations');
    }
};
