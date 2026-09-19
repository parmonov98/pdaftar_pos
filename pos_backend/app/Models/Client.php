<?php

declare(strict_types=1);

namespace Pos\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Someone the shop sells to on credit.
 */
class Client extends Model {
    use SoftDeletes;

    protected $fillable = ['shop_id', 'name', 'phone_number', 'note', 'pdaftar_client_id'];

    public function shop(): BelongsTo {
        return $this->belongsTo(Shop::class);
    }

    public function sales(): HasMany {
        return $this->hasMany(Sale::class);
    }

    public function payments(): HasMany {
        return $this->hasMany(ClientPayment::class);
    }

    /**
     * What this client owes.
     *
     * Computed from the two ledgers every time rather than stored. A cached
     * balance and the rows behind it drift apart eventually, and the way that
     * is discovered is a customer being asked for money they already paid.
     *
     * Cancelled sales are excluded: the goods came back, so the debt did too.
     */
    public function balance(): float {
        $owed = (float) $this->sales()
            ->where('status', Sale::STATUS_COMPLETED)
            ->selectRaw('coalesce(sum(total - paid_amount), 0) as owed')
            ->value('owed');

        $paid = (float) $this->payments()->sum('amount');

        return round($owed - $paid, 6);
    }

    public function scopeMatching(Builder $query, string $term): Builder {
        $term = trim($term);

        return $query->where(function (Builder $q) use ($term) {
            $q->where('name', 'like', '%'.$term.'%')
                ->orWhere('phone_number', 'like', '%'.$term.'%');
        });
    }
}
