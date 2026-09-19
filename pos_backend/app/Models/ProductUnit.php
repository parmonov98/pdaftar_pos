<?php

declare(strict_types=1);

namespace Pos\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One way a product can be sold: a unit, and what it is worth in base units.
 *
 * The ratio is kept as two integers rather than a decimal. 1/3 of a box is
 * exact; 0.333333 is not, and three of them do not add back up to a box.
 */
class ProductUnit extends Model {
    protected $fillable = [
        'shop_id', 'product_id', 'unit_id',
        'base_units_numerator', 'base_units_denominator',
        'is_base', 'is_active', 'pdaftar_product_unit_id',
    ];

    protected function casts(): array {
        return [
            'base_units_numerator' => 'integer',
            'base_units_denominator' => 'integer',
            'is_base' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function product(): BelongsTo {
        return $this->belongsTo(Product::class);
    }

    public function unit(): BelongsTo {
        return $this->belongsTo(Unit::class);
    }

    public function prices(): HasMany {
        return $this->hasMany(ProductPrice::class);
    }

    /** How many base units `$quantity` of this unit comes to. */
    public function toBaseUnits(float $quantity): float {
        return round($quantity * $this->base_units_numerator / max(1, $this->base_units_denominator), 6);
    }

    public function label(): string {
        return $this->unit?->label() ?? '';
    }
}
