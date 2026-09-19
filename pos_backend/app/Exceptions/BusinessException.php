<?php

declare(strict_types=1);

namespace Pos\Exceptions;

use Exception;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * A refusal the cashier can act on: "this shop already has two tills open",
 * "you do not have access to this shop".
 *
 * Renders itself as a 400 carrying the message verbatim, which is why the
 * message must always be written for the person at the counter and never left
 * to a default. The same shape as pDaftar's App\Exceptions\Custom\
 * BusinessException, deliberately, so nothing downstream had to change — but
 * POS's own, so the terminal path does not need the sibling checkout to
 * report a plain permission error.
 */
class BusinessException extends Exception implements Renderable
{
    public function __construct(?string $message = null, int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message ?? 'Amalni bajarib bo\'lmadi', $code, $previous);
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], Response::HTTP_BAD_REQUEST);
    }
}
