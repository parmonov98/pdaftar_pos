<?php

declare(strict_types=1);

namespace Pos\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sale extends Model {
    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'shop_id', 'pos_terminal_id', 'user_id', 'currency_id',
        'subtotal', 'discount_amount', 'total', 'paid_amount',
        'payment_type', 'note', 'status', 'cancelled_at', 'occurred_at',
    ];

    protected function casts(): array {
        return [
            'subtotal' => 'decimal:6',
            'discount_amount' => 'decimal:6',
            'total' => 'decimal:6',
            'paid_amount' => 'decimal:6',
            'occurred_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function items(): HasMany {
        return $this->hasMany(SaleItem::class);
    }

    public function shop(): BelongsTo {
        return $this->belongsTo(Shop::class);
    }

    public function terminal(): BelongsTo {
        return $this->belongsTo(PosTerminal::class, 'pos_terminal_id');
    }

    /** Who rang it up. Recorded at write time, not derived from the terminal. */
    public function user(): BelongsTo {
        return $this->belongsTo(User::class);
    }

    public function currency(): BelongsTo {
        return $this->belongsTo(Currency::class);
    }

    public function isCancelled(): bool {
        return $this->status === self::STATUS_CANCELLED;
    }

    /**
     * What is still owed.
     *
     * Kept as a calculation rather than a column: a stored balance and the
     * payments that produced it drift, and the drift is only ever noticed by
     * the customer being asked for money they already paid.
     */
    public function outstanding(): float {
        return round((float) $this->total - (float) $this->paid_amount, 6);
    }
}
