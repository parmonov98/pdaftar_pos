<?php

declare(strict_types=1);

namespace Pos\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Pos\Exceptions\BusinessException;
use Pos\Exceptions\InsufficientStockException;
use Pos\Models\PosOperation;
use Pos\Models\PosTerminal;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Exactly-once semantics for till writes.
 *
 * The contract every POS write goes through:
 *   1. Claim the operation id (INSERT ... status=pending). The unique index on
 *      (terminal, client_operation_id) is the lock — no application-level
 *      mutex, no cache, nothing that can be lost on a deploy.
 *   2. Run the handler.
 *   3. Record what happened, response included.
 *
 * A retry that arrives after step 3 gets the stored response back and changes
 * nothing. A retry that arrives DURING step 2 loses the insert race and is told
 * to come back — better a till retrying in 5 seconds than two identical sales.
 *
 * Failures come in two kinds and are NOT treated alike:
 *
 *   `failed` — a validation or business refusal. Every handler validates before
 *      it writes, so nothing happened; a retry under the same id is allowed and
 *      is how a sale rejected during a transient outage gets recovered once the
 *      cause is fixed.
 *
 *   `error` — anything else. The write may have succeeded and the code AFTER it
 *      thrown, which is not a hypothetical: the first end-to-end test of this
 *      API committed a debt and its stock movements, then died formatting the
 *      response, and an optimistic retry rang the same sale up twice. Since we
 *      cannot prove the database is untouched, the id is sealed and the client
 *      is told to check /sync/status rather than replay blindly.
 *
 * Wrapping the handler in one outer transaction would remove the distinction,
 * but cannot be done here: StoreDebtUseCase dispatches queue jobs after its own
 * commit and this app runs with `after_commit => false`, so an outer transaction
 * would let a worker read rows that are not committed yet.
 */
class PosIdempotencyService
{
    /**
     * @param  callable(): array{data: array, entity?: ?Model}  $handler
     */
    public function run(
        PosTerminal $terminal,
        string $clientOperationId,
        string $type,
        array $payload,
        ?Carbon $occurredAt,
        callable $handler,
    ): PosOperationResult {
        $hash = $this->hash($payload);

        $existing = $this->find($terminal, $clientOperationId);

        if ($existing !== null) {
            $verdict = $this->verdict($existing, $type, $hash);

            if ($verdict !== null) {
                return $verdict;
            }

            // A previous attempt failed. Re-open the same row rather than
            // inserting a second one, so the audit trail stays one row per
            // cashier action instead of one per network hiccup.
            $existing->update(['status' => 'pending', 'error' => null, 'type' => $type]);
            $operation = $existing;
        } else {
            try {
                $operation = PosOperation::create([
                    'pos_terminal_id' => $terminal->id,
                    'shop_id' => $terminal->shop_id,
                    'client_operation_id' => $clientOperationId,
                    'type' => $type,
                    'status' => 'pending',
                    'request_hash' => $hash,
                    'occurred_at' => $occurredAt,
                ]);
            } catch (QueryException $e) {
                // Lost the insert race: another copy of this same retry is
                // mid-flight (or finished between our SELECT and our INSERT).
                $winner = $this->find($terminal, $clientOperationId);

                if ($winner === null) {
                    throw $e;
                }

                return $this->verdict($winner, $type, $hash)
                    ?? PosOperationResult::failed(
                        $clientOperationId,
                        $type,
                        'Bu amal hozir bajarilmoqda. Bir necha soniyadan keyin qayta urinib ko\'ring.',
                        httpStatus: 409,
                    );
            }
        }

        try {
            $result = $handler();
        } catch (Throwable $e) {
            $retryable = $this->isPreWriteRejection($e);

            $operation->update([
                'status' => $retryable ? PosOperation::STATUS_FAILED : PosOperation::STATUS_ERROR,
                'error' => $this->message($e),
            ]);

            Log::warning('POS operation failed', [
                'terminal_id' => $terminal->id,
                'shop_id' => $terminal->shop_id,
                'type' => $type,
                'client_operation_id' => $clientOperationId,
                'retryable' => $retryable,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            return PosOperationResult::failed(
                $clientOperationId,
                $type,
                $retryable
                    ? $this->message($e)
                    : $this->message($e).' (Amal holati nomalum — /sync/status orqali tekshiring, takroran yubormang.)',
                $this->httpStatusFor($e),
            );
        }

        /** @var Model|null $entity */
        $entity = $result['entity'] ?? null;
        $data = $result['data'] ?? [];

        $operation->update([
            'status' => PosOperation::STATUS_APPLIED,
            'entity_type' => $entity !== null ? $entity::class : null,
            'entity_id' => $entity?->getKey(),
            'response' => $data,
        ]);

        return PosOperationResult::applied($clientOperationId, $type, $data);
    }

    private function find(PosTerminal $terminal, string $clientOperationId): ?PosOperation
    {
        return PosOperation::query()
            ->where('pos_terminal_id', $terminal->id)
            ->where('client_operation_id', $clientOperationId)
            ->first();
    }

    /**
     * Decide what a repeat of a known operation id deserves.
     *
     * Returns null when the caller should go ahead and run the handler — i.e.
     * the previous attempt failed and left nothing behind.
     *
     * The body is compared, not just the id. Same id + same body is a retry.
     * Same id + DIFFERENT body is a client bug — usually an outbox reusing a
     * UUID across two cashier actions — and answering it with the first sale's
     * receipt would hide a second, real, lost sale. It is refused loudly.
     */
    private function verdict(PosOperation $operation, string $type, string $hash): ?PosOperationResult
    {
        if ($operation->request_hash !== $hash) {
            return PosOperationResult::failed(
                $operation->client_operation_id,
                $type,
                'Bu client_operation_id boshqa ma\'lumot bilan allaqachon ishlatilgan. '.
                'Har bir amal uchun yangi UUID yarating.',
                httpStatus: 409,
            );
        }

        if ($operation->status === 'pending') {
            return PosOperationResult::failed(
                $operation->client_operation_id,
                $type,
                'Bu amal hozir bajarilmoqda. Bir necha soniyadan keyin qayta urinib ko\'ring.',
                httpStatus: 409,
            );
        }

        if ($operation->isApplied()) {
            return PosOperationResult::applied(
                $operation->client_operation_id,
                $type,
                $operation->response ?? [],
                replayed: true,
            );
        }

        if ($operation->status === PosOperation::STATUS_ERROR) {
            return PosOperationResult::failed(
                $operation->client_operation_id,
                $type,
                'Bu amal xatolik bilan tugagan va holati nomalum: '.($operation->error ?? '').
                ' Takroran yubormang — /sync/status orqali tekshiring.',
                httpStatus: 409,
            );
        }

        // STATUS_FAILED — rejected before anything was written, so the caller
        // may run it again under the same id.
        return null;
    }

    /**
     * Did this throwable happen before the handler wrote anything?
     *
     * Only exception types the handlers raise deliberately, as a refusal, count.
     * Everything else — a TypeError, a QueryException, an S3 timeout — is
     * treated as unknown-state, because "probably nothing was written" is not
     * a safe basis for replaying a sale.
     */
    private function isPreWriteRejection(Throwable $e): bool
    {
        return $e instanceof ValidationException
            // Covers InsufficientStockException too, which extends it — that one
            // is raised inside StoreDebtUseCase's own transaction, so the debt
            // rolls back with it.
            || $e instanceof BusinessException;
    }

    /**
     * Hash the semantic payload, not the raw request.
     *
     * Keys are sorted recursively so a client that serialises its JSON in a
     * different order between the first send and the retry is still recognised
     * as the same operation — otherwise every retry from a re-encoding client
     * would 409.
     */
    private function hash(array $payload): string
    {
        $normalized = $this->normalize($payload);

        return hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    }

    private function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = $this->normalize($v);
        }

        // Only associative arrays are sorted; reordering a list of sale lines
        // would be a real difference, not a serialisation artefact.
        if (! array_is_list($out)) {
            ksort($out);
        }

        return $out;
    }

    private function message(Throwable $e): string
    {
        if ($e instanceof ValidationException) {
            return collect($e->errors())->flatten()->implode(' ');
        }

        return $e->getMessage() !== '' ? $e->getMessage() : 'Nomalum xatolik';
    }

    private function httpStatusFor(Throwable $e): int
    {
        return match (true) {
            $e instanceof ValidationException => 422,
            $e instanceof InsufficientStockException => 409,
            $e instanceof HttpException => $e->getStatusCode(),
            default => 422,
        };
    }
}
