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
     * What this client owes, per currency.
     *
     * Computed from the two ledgers every time rather than stored. A cached
     * balance and the rows behind it drift apart eventually, and the way that
     * is discovered is a customer being asked for money they already paid.
     *
     * Cancelled sales are excluded: the goods came back, so the debt did too.
     *
     * Per currency and not as one number, because adding them is not
     * arithmetic — eleven dollars and twelve thousand som is not twelve
     * thousand and eleven of anything. Summed together, a dollar handed over
     * would cancel a som of debt and the shop would be told it had been paid.
     *
     * Keyed by currency id, and only currencies the client has actually
     * touched. A zero is kept when there was activity that netted out, so the
     * caller can tell "settled" from "never traded in this".
     *
     * @return array<int, float>
     */
    public function balances(): array {
        $totals = [];

        $owed = $this->sales()
            ->where('status', Sale::STATUS_COMPLETED)
            ->selectRaw('currency_id, coalesce(sum(total - paid_amount), 0) as owed')
            ->groupBy('currency_id')
            ->pluck('owed', 'currency_id');

        foreach ($owed as $currencyId => $amount) {
            $totals[(int) $currencyId] = (float) $amount;
        }

        $paid = $this->payments()
            ->selectRaw('currency_id, coalesce(sum(amount), 0) as paid')
            ->groupBy('currency_id')
            ->pluck('paid', 'currency_id');

        foreach ($paid as $currencyId => $amount) {
            $key = (int) $currencyId;
            $totals[$key] = round(($totals[$key] ?? 0) - (float) $amount, 6);
        }

        foreach ($totals as $key => $amount) {
            $totals[$key] = round($amount, 6);
        }

        return $totals;
    }

    /**
     * The balance in one currency. Zero when they have never traded in it,
     * which is the same thing as owing nothing in it.
     */
    public function balanceIn(?int $currencyId): float {
        return $this->balances()[(int) $currencyId] ?? 0.0;
    }

    public function scopeMatching(Builder $query, string $term): Builder {
        $term = trim($term);

        return $query->where(function (Builder $q) use ($term) {
            $q->where('name', 'like', '%'.$term.'%')
                ->orWhere('phone_number', 'like', '%'.$term.'%');
        });
    }
}
