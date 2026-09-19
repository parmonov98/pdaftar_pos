<?php

declare(strict_types=1);

namespace Pos\Exceptions;

use Throwable;

/**
 * Not enough stock to sell what was rung up.
 *
 * Carries the numbers rather than only a sentence, so the till can render
 * "cola: 3 qoldi, 5 so'ralyapti" itself instead of parsing prose — and so the
 * cashier is told which line is the problem while the customer is still at the
 * counter.
 *
 * A subclass of BusinessException because it is the same kind of thing: a
 * refusal the person at the till can act on, not a fault. PosIdempotencyService
 * relies on that relationship — it treats the parent as "safe to replay under
 * the same operation id" and answers this one with 409 specifically.
 */
class InsufficientStockException extends BusinessException {
    public function __construct(
        string $message,
        public readonly string $productName,
        public readonly float $available,
        public readonly float $requested,
        public readonly ?int $productId = null,
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
