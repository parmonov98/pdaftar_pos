<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The POS's own users.
 *
 * Until now the POS had none: config/auth.php pointed at pDaftar's
 * App\Models\User and the till logged in against pDaftar's mobile API. That
 * only worked while the two shared a database and an origin, and they no
 * longer share either.
 *
 * `pdaftar_user_id` is the seam for the integration that comes later. It is
 * nullable, and that nullability is the whole point: a POS account either
 * stands alone or is linked to a pDaftar account, and nothing about the
 * standalone case needs pDaftar to exist or be reachable.
 */
return new class extends Migration {
    public function up(): void {
        Schema::create('users', function (Blueprint $table) {
            $table->id();

            $table->string('name', 120);

            // The login handle. Stored normalised to +998XXXXXXXXX by the
            // application so that "+998 90 123 45 67" and "998901234567"
            // cannot become two accounts for one person.
            $table->string('phone_number', 20)->unique();

            $table->string('password');

            // Null for an account that has never been linked to pDaftar. When
            // set, it is unique: one pDaftar account maps to at most one POS
            // account, so a second link attempt is a conflict to report rather
            // than a duplicate to create.
            $table->unsignedBigInteger('pdaftar_user_id')->nullable()->unique();
            $table->timestamp('pdaftar_linked_at')->nullable();

            // A cashier who left. Kept rather than deleted so the sales they
            // rang up still name someone.
            $table->boolean('is_active')->default(true);

            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void {
        Schema::dropIfExists('users');
    }
};
