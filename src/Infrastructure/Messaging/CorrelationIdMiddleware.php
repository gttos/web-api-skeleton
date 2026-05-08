<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Uid\Uuid;

final class CorrelationIdMiddleware implements MiddlewareInterface
{
    /** @var \Monolog\Logger */
    private readonly LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $stamp = $envelope->last(CorrelationIdStamp::class);

        if ($stamp === null) {
            // Extraer del mensaje si tiene los campos directamente
            $message = $envelope->getMessage();
            $correlationId = property_exists($message, 'correlationId')
                ? $message->correlationId
                : Uuid::v4()->toRfc4122();
            $causationId = property_exists($message, 'causationId')
                ? $message->causationId
                : Uuid::v4()->toRfc4122();

            $stamp = new CorrelationIdStamp($correlationId, $causationId);
            $envelope = $envelope->with($stamp);
        }

        // Inyectar en el contexto del logger para que todos los logs del handler incluyan estos IDs
        if (method_exists($this->logger, 'pushProcessor')) {
            $this->logger->pushProcessor(function (array $record) use ($stamp): array {
                $record['extra']['correlation_id'] = $stamp->correlationId;
                $record['extra']['causation_id']   = $stamp->causationId;
                return $record;
            });
        }

        try {
            return $stack->next()->handle($envelope, $stack);
        } finally {
            if (method_exists($this->logger, 'popProcessor')) {
                $this->logger->popProcessor();
            }
        }
    }
}
