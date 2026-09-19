<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sanctum's token store, in the POS's own database.
 *
 * It was missing entirely. `auth:sanctum` guards nearly every POS route and
 * pos_terminals.access_token_id names a row in this table, but the table lived
 * in pDaftar's database — so on a standalone POS no token could be issued and
 * none could be validated. Every authenticated route was unreachable.
 *
 * Written out here rather than published from the package: it is load-bearing
 * enough that a fresh box must not depend on someone remembering to run
 * `vendor:publish`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            // Which machine this credential belongs to. PosTerminalService
            // records it so support can answer "which till is this?" — a token
            // with no device is one nobody can trace back to a counter.
            $table->string('device_name')->nullable();
            $table->string('device_type')->nullable();
            $table->string('platform')->nullable();
            $table->text('user_agent')->nullable();
            $table->string('ip_address', 45)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
