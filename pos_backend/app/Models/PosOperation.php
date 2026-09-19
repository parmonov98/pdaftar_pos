<?php

declare(strict_types=1);

namespace Pos\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One applied (or failed) POS write, keyed by the till's own operation id.
 * See the create migration for why this table exists at all.
 *
 * @property int $id
 * @property int $pos_terminal_id
 * @property int $shop_id
 * @property string $client_operation_id
 * @property string $type
 * @property string $status
 * @property string $request_hash
 * @property string|null $entity_type
 * @property int|null $entity_id
 * @property array|null $response
 * @property string|null $error
 * @property Carbon|null $occurred_at
 */
class PosOperation extends Model {
    public const STATUS_APPLIED = 'applied';

    /**
     * Rejected before anything was written — a validation or business refusal.
     * Safe to retry under the same operation id.
     */
    public const STATUS_FAILED = 'failed';

    /**
     * Blew up in a way we cannot prove left the database untouched. NOT
     * auto-retryable: the write may well have gone through and only the code
     * after it failed, so replaying would duplicate it. Needs a human, or a
     * fresh operation id once the client has checked /sync/status.
     */
    public const STATUS_ERROR = 'error';

    protected $fillable = [
        'pos_terminal_id',
        'shop_id',
        'client_operation_id',
        'type',
        'status',
        'request_hash',
        'entity_type',
        'entity_id',
        'response',
        'error',
        'occurred_at',
    ];

    protected $casts = [
        'response' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function terminal(): BelongsTo {
        return $this->belongsTo(PosTerminal::class, 'pos_terminal_id');
    }

    public function isApplied(): bool {
        return $this->status === self::STATUS_APPLIED;
    }
}
