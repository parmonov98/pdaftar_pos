<?php

declare(strict_types=1);

namespace Pos\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\HasApiTokens;

/**
 * A person who can open a till.
 *
 * Deliberately NOT pDaftar's App\Models\User. That model carries pDaftar's
 * subscriptions, tariffs, referrals and permission rows — a whole product's
 * worth of state that a standalone POS has no table for and no use for. The
 * link between the two, when it exists, is one nullable id.
 */
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'phone_number',
        'password',
        'pdaftar_user_id',
        'pdaftar_linked_at',
        'is_active',
        'last_login_at',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'pdaftar_linked_at' => 'datetime',
            'last_login_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /**
     * One phone, one account.
     *
     * "+998 90 123 45 67", "998901234567" and "+998901234567" are the same
     * person, and a till that let them become three accounts would split one
     * cashier's sales across three names. Every read and write of this column
     * goes through here.
     */
    public static function normalisePhone(string $raw): string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        return $digits === '' ? '' : '+'.$digits;
    }

    public static function findByPhone(string $raw): ?self
    {
        return static::query()
            ->where('phone_number', static::normalisePhone($raw))
            ->first();
    }

    public function checkPassword(string $plain): bool
    {
        return Hash::check($plain, $this->password);
    }

    /** Shops this user may open a till in — owner and seller alike. */
    public function shops(): BelongsToMany
    {
        return $this->belongsToMany(Shop::class, 'user_shop')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function terminals(): HasMany
    {
        return $this->hasMany(PosTerminal::class);
    }

    /**
     * Membership rows rather than the shops themselves.
     *
     * PosTerminalController queries this directly to ask whether a user may
     * open a till in a given shop, so it is a HasMany over the pivot and not
     * the BelongsToMany above.
     */
    public function userShops(): HasMany
    {
        return $this->hasMany(UserShop::class);
    }

    /** True once this account has been tied to a pDaftar account. */
    public function isLinkedToPdaftar(): bool
    {
        return $this->pdaftar_user_id !== null;
    }
}
