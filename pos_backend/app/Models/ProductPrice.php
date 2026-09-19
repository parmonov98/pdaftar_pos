<?php

declare(strict_types=1);

namespace Pos\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** What one product_unit costs, in one currency, for one kind of sale. */
class ProductPrice extends Model {
    public const TYPE_SALE = 'sale';

    public const TYPE_CREDIT = 'credit';

    protected $fillable = [
        'shop_id', 'product_id', 'product_unit_id', 'currency_id',
        'price_type', 'amount', 'pdaftar_product_price_id',
    ];

    protected function casts(): array {
        return ['amount' => 'decimal:6'];
    }

    public function product(): BelongsTo {
        return $this->belongsTo(Product::class);
    }

    public function productUnit(): BelongsTo {
        return $this->belongsTo(ProductUnit::class);
    }

    public function currency(): BelongsTo {
        return $this->belongsTo(Currency::class);
    }
}
