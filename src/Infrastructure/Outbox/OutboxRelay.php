<?php

declare(strict_types=1);

namespace App\Infrastructure\Outbox;

use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

final class OutboxRelay
{
    public function __construct(
        private readonly OutboxStoreInterface $outboxStore,
        private readonly MessageBusInterface $eventBus,
        private readonly LoggerInterface $logger,
    ) {}

    public function execute(int $batchSize = 100): int
    {
        $pending = $this->outboxStore->fetchPending($batchSize);
        $published = 0;

        foreach ($pending as $message) {
            try {
                // Crear un mensaje genérico que Messenger pueda enrutar al transport order_events
                $integrationMessage = new OutboxIntegrationMessage(
                    messageId: $message->id,
                    eventType: $message->eventType,
                    payload: $message->payload,
                    metadata: $message->metadata,
                    correlationId: $message->correlationId,
                    causationId: $message->causationId,
                );

                $envelope = new Envelope($integrationMessage, [
                    new TransportNamesStamp(['order_events']),
                ]);

                $this->eventBus->dispatch($envelope);
                $this->outboxStore->markPublished($message->id);
                $published++;

                $this->logger->debug('Outbox message published', [
                    'message_id'     => $message->id,
                    'event_type'     => $message->eventType,
                    'correlation_id' => $message->correlationId,
                ]);
            } catch (\Throwable $e) {
                $this->logger->warning('Outbox relay failed for message', [
                    'message_id' => $message->id,
                    'event_type' => $message->eventType,
                    'error'      => $e->getMessage(),
                ]);
                // No lanzar excepción: el mensaje se reintentará en el siguiente ciclo
            }
        }

        return $published;
    }
}
