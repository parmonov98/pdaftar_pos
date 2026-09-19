<?php

declare(strict_types=1);

namespace Pos\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a sale, with the product's name copied in.
 *
 * The snapshot is the point: a receipt from last month must still read the way
 * it read then, even if the product was renamed or removed since.
 */
class SaleItem extends Model {
    protected $fillable = [
        'sale_id', 'product_id', 'name', 'code', 'barcode',
        'unit_id', 'product_unit_id', 'unit_name',
        'conversion_numerator', 'conversion_denominator',
        'quantity', 'base_quantity', 'price', 'currency_id', 'total',
    ];

    protected function casts(): array {
        return [
            'quantity' => 'decimal:6',
            'base_quantity' => 'decimal:6',
            'price' => 'decimal:6',
            'total' => 'decimal:6',
            'conversion_numerator' => 'integer',
            'conversion_denominator' => 'integer',
        ];
    }

    public function sale(): BelongsTo {
        return $this->belongsTo(Sale::class);
    }

    public function product(): BelongsTo {
        return $this->belongsTo(Product::class);
    }
}
