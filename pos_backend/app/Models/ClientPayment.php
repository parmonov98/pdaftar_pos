<?php

declare(strict_types=1);

namespace Pos\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Money handed back against a debt. One row per act of paying. */
class ClientPayment extends Model {
    protected $fillable = [
        'shop_id', 'client_id', 'sale_id', 'amount', 'currency_id',
        'payment_type', 'note', 'user_id', 'pos_terminal_id', 'occurred_at',
    ];

    protected function casts(): array {
        return ['amount' => 'decimal:6', 'occurred_at' => 'datetime'];
    }

    public function client(): BelongsTo {
        return $this->belongsTo(Client::class);
    }

    public function sale(): BelongsTo {
        return $this->belongsTo(Sale::class);
    }

    public function user(): BelongsTo {
        return $this->belongsTo(User::class);
    }
}
