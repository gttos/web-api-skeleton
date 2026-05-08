<?php

declare(strict_types=1);

namespace App\Infrastructure\DeadLetter;

final class DeadLetterEntry
{
    public function __construct(
        public readonly string $messageId,
        public readonly string $eventType,
        public readonly string $consumerName,
        public readonly array $payload,
        public readonly array $metadata,
        public readonly string $errorReason,
        public readonly ?string $stackTrace,
        public readonly ?string $correlationId,
        public readonly ?string $causationId,
        public readonly string $originalTransport,
        public readonly int $attempts,
        public readonly \DateTimeImmutable $failedAt,
        public readonly ?\DateTimeImmutable $lastRetryAt,
    ) {}

    public static function fromRow(array $row): self
    {
        return new self(
            messageId: $row['message_id'],
            eventType: $row['event_type'],
            consumerName: $row['consumer_name'],
            payload: json_decode($row['payload'], true),
            metadata: json_decode($row['metadata'], true),
            errorReason: $row['error_reason'],
            stackTrace: $row['stack_trace'] ?? null,
            correlationId: $row['correlation_id'] ?? null,
            causationId: $row['causation_id'] ?? null,
            originalTransport: $row['original_transport'],
            attempts: (int) $row['attempts'],
            failedAt: new \DateTimeImmutable($row['failed_at']),
            lastRetryAt: isset($row['last_retry_at']) ? new \DateTimeImmutable($row['last_retry_at']) : null,
        );
    }
}
