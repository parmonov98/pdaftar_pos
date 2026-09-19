<?php

declare(strict_types=1);

namespace Pos\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Something the shop sells.
 *
 * `quantity` is a cache of the stock_movements ledger — see PosStockService.
 * Read it for display; never add to it directly, or the ledger and the number
 * on screen start disagreeing and only one of them can be rebuilt.
 */
class Product extends Model {
    use SoftDeletes;

    protected $fillable = [
        'shop_id',
        'code',
        'barcode',
        'name',
        'price',
        'unit_id',
        'currency_id',
        'quantity',
        'low_stock_threshold',
        'image_url',
        'is_active',
        'pdaftar_product_id',
    ];

    protected function casts(): array {
        return [
            'price' => 'decimal:6',
            'quantity' => 'decimal:6',
            'low_stock_threshold' => 'decimal:6',
            'is_active' => 'boolean',
        ];
    }

    public function shop(): BelongsTo {
        return $this->belongsTo(Shop::class);
    }

    public function unit(): BelongsTo {
        return $this->belongsTo(Unit::class);
    }

    public function currency(): BelongsTo {
        return $this->belongsTo(Currency::class);
    }

    public function movements(): HasMany {
        return $this->hasMany(StockMovement::class);
    }

    /**
     * What a scanner or a search box found.
     *
     * Barcode first: it is what the hardware produces, it is exact, and it is
     * the overwhelmingly common case. Code and name only matter when someone
     * is typing.
     */
    public function scopeMatching(Builder $query, string $term): Builder {
        $term = trim($term);

        return $query->where(function (Builder $q) use ($term) {
            $q->where('barcode', $term)
                ->orWhere('code', $term)
                ->orWhere('name', 'like', '%'.$term.'%');
        });
    }

    /** True when stock is tracked at all — a service or a bulk item may not be. */
    public function isTracked(): bool {
        return $this->quantity !== null;
    }

    public function isLinkedToPdaftar(): bool {
        return $this->pdaftar_product_id !== null;
    }
}
