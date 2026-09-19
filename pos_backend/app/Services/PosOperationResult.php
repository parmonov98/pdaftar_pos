<?php

declare(strict_types=1);

namespace Pos\Services;

/**
 * The outcome of one POS write, in the shape both entry points return:
 * a single REST call and one element of a /sync/push batch.
 */
final class PosOperationResult {
    private function __construct(
        public readonly string $clientOperationId,
        public readonly string $type,
        public readonly string $status,
        public readonly array $data,
        public readonly bool $replayed,
        public readonly ?string $error = null,
        public readonly int $httpStatus = 200,
    ) {}

    public static function applied(
        string $clientOperationId,
        string $type,
        array $data,
        bool $replayed = false,
    ): self {
        return new self(
            $clientOperationId,
            $type,
            'applied',
            $data,
            $replayed,
            httpStatus: $replayed ? 200 : 201,
        );
    }

    public static function failed(
        string $clientOperationId,
        string $type,
        string $error,
        int $httpStatus = 422,
        array $data = [],
    ): self {
        return new self($clientOperationId, $type, 'failed', $data, false, $error, $httpStatus);
    }

    public function toArray(): array {
        return [
            'client_operation_id' => $this->clientOperationId,
            'type' => $this->type,
            'status' => $this->status,
            // True means "we had already done this, nothing new happened".
            // The till uses it to drop the operation from its outbox without
            // treating the second response as a second sale.
            'replayed' => $this->replayed,
            'data' => $this->data,
            'error' => $this->error,
        ];
    }
}
