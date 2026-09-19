<?php

declare(strict_types=1);

namespace Pos\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** One signed change in stock. Append-only; the balance is their sum. */
class StockMovement extends Model
{
    public const TYPE_SALE = 'sale';

    public const TYPE_RETURN = 'return';

    public const TYPE_PURCHASE = 'purchase';

    public const TYPE_ADJUSTMENT = 'adjustment';

    public const TYPE_WRITE_OFF = 'write_off';

    public const TYPE_OPENING = 'opening';

    protected $fillable = [
        'shop_id', 'product_id', 'type', 'quantity',
        'occurred_at', 'source_type', 'source_id', 'note', 'user_id',
    ];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:6', 'occurred_at' => 'datetime'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }
}
