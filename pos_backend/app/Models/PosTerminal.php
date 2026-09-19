<?php

declare(strict_types=1);

namespace Pos\Models;

use Pos\Models\PersonalAccessToken;
use Pos\Models\Shop;
use Pos\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Pos\Constants\PosProvider;

/**
 * A registered till. See the create migration for why this is per-device.
 *
 * @property int $id
 * @property int $shop_id
 * @property int $user_id
 * @property string $name
 * @property string $device_id
 * @property PosProvider $provider
 * @property int|null $access_token_id
 * @property bool $is_active
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $last_sync_at
 * @property-read Shop $shop
 * @property-read User $user
 */
class PosTerminal extends Model {
    use SoftDeletes;

    protected $fillable = [
        'shop_id',
        'user_id',
        'name',
        'device_id',
        'provider',
        'access_token_id',
        'is_active',
        'last_seen_at',
        'last_sync_at',
    ];

    protected $casts = [
        'provider' => PosProvider::class,
        'is_active' => 'boolean',
        'last_seen_at' => 'datetime',
        'last_sync_at' => 'datetime',
    ];

    public function shop(): BelongsTo {
        return $this->belongsTo(Shop::class);
    }

    public function user(): BelongsTo {
        return $this->belongsTo(User::class);
    }

    public function operations(): HasMany {
        return $this->hasMany(PosOperation::class);
    }

    /**
     * The Sanctum token this till authenticates with, if it still has one.
     */
    public function accessToken(): BelongsTo {
        return $this->belongsTo(PersonalAccessToken::class, 'access_token_id');
    }
}
