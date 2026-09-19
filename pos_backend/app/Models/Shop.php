<?php

declare(strict_types=1);

namespace Pos\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A business the POS rings up sales for.
 *
 * The POS's own, not pDaftar's. A standalone till must be able to say which
 * business it belongs to without a network call, and a shop that exists only
 * here is a perfectly valid shop.
 */
class Shop extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'phone_number',
        'address',
        'owner_id',
        'pdaftar_shop_id',
        'terminal_limit',
        'is_active',
        'currency_id',
        'default_unit_id',
        'allow_negative_stock',
        'low_stock_threshold',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'allow_negative_stock' => 'boolean',
            'terminal_limit' => 'integer',
            'low_stock_threshold' => 'decimal:6',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_shop')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function terminals(): HasMany
    {
        return $this->hasMany(PosTerminal::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function units(): HasMany
    {
        return $this->hasMany(Unit::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    /**
     * Tills currently occupying a slot.
     *
     * Counted rather than stored: a stored counter and a deleted terminal
     * disagree eventually, and the disagreement shows up as a shop that cannot
     * open a till it is entitled to.
     */
    public function activeTerminalCount(): int
    {
        return $this->terminals()->where('is_active', true)->count();
    }

    public function isLinkedToPdaftar(): bool
    {
        return $this->pdaftar_shop_id !== null;
    }
}
