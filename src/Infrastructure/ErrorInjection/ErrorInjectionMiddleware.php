<?php

declare(strict_types=1);

namespace App\Infrastructure\ErrorInjection;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

final class ErrorInjectionMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly ErrorFlagStoreInterface $flagStore) {}

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $correlationId = $this->extractCorrelationId($envelope);

        if ($correlationId === null) {
            return $stack->next()->handle($envelope, $stack);
        }

        $flag = $this->flagStore->getFlag($correlationId);

        if ($flag === null) {
            return $stack->next()->handle($envelope, $stack);
        }

        match ($flag->type) {
            ErrorFlag::TEMP_FAILURE => $this->handleTempFailure($flag),
            ErrorFlag::PERM_FAILURE => throw new \RuntimeException(
                "Simulated permanent failure for correlation_id: {$correlationId}"
            ),
            ErrorFlag::INVALID_PAYLOAD => throw new \InvalidArgumentException(
                "Simulated invalid payload for correlation_id: {$correlationId}"
            ),
            ErrorFlag::SLOW_CONSUMER => $this->handleSlowConsumer($flag),
            default => null,
        };

        return $stack->next()->handle($envelope, $stack);
    }

    private function handleTempFailure(ErrorFlag $flag): void
    {
        $maxFailures = (int) ($flag->config['max_failures'] ?? 2);
        $count = $this->flagStore->incrementFailureCount($flag->correlationId);

        if ($count <= $maxFailures) {
            throw new \RuntimeException(
                "Simulated temporary failure #{$count} for correlation_id: {$flag->correlationId}"
            );
        }

        // Después de max_failures intentos, limpiar el flag y continuar normalmente
        $this->flagStore->clearFlag($flag->correlationId);
    }

    private function handleSlowConsumer(ErrorFlag $flag): void
    {
        $delayMs = (int) ($flag->config['delay_ms'] ?? 2000);
        usleep($delayMs * 1000);
    }

    private function extractCorrelationId(Envelope $envelope): ?string
    {
        $message = $envelope->getMessage();
        if (property_exists($message, 'correlationId')) {
            return $message->correlationId;
        }
        return null;
    }
}
