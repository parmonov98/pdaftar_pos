<?php

declare(strict_types=1);

namespace Pos\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Membership: this user may open a till in this shop.
 *
 * A model rather than a bare pivot because PosTerminalService queries it
 * directly — the same shape it used against pDaftar's user_shops, so the
 * permission check kept its logic and changed only its table.
 */
class UserShop extends Model {
    use SoftDeletes;

    protected $table = 'user_shop';

    public const ROLE_OWNER = 'owner';
    public const ROLE_SELLER = 'seller';

    protected $fillable = ['user_id', 'shop_id', 'role'];

    public function user(): BelongsTo {
        return $this->belongsTo(User::class);
    }

    public function shop(): BelongsTo {
        return $this->belongsTo(Shop::class);
    }

    public function isOwner(): bool {
        return $this->role === self::ROLE_OWNER;
    }
}
