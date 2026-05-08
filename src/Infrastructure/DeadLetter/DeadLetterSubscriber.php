<?php

declare(strict_types=1);

namespace App\Infrastructure\DeadLetter;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Uid\Uuid;

final class DeadLetterSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly DeadLetterStoreInterface $deadLetterStore) {}

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerMessageFailedEvent::class => 'onMessageFailed',
        ];
    }

    public function onMessageFailed(WorkerMessageFailedEvent $event): void
    {
        // Solo capturar cuando ya no se va a reintentar (willRetry = false)
        if ($event->willRetry()) {
            return;
        }

        $envelope = $event->getEnvelope();
        $message = $envelope->getMessage();
        $throwable = $event->getThrowable();

        $messageId = $this->extractMessageId($envelope);
        $correlationId = $this->extractCorrelationId($message);
        $causationId = $this->extractCausationId($message);

        $entry = new DeadLetterEntry(
            messageId: $messageId,
            eventType: get_class($message),
            consumerName: 'messenger_worker',
            payload: $this->extractPayload($message),
            metadata: [],
            errorReason: $throwable->getMessage(),
            stackTrace: $throwable->getTraceAsString(),
            correlationId: $correlationId,
            causationId: $causationId,
            originalTransport: 'order_events',
            attempts: 1,
            failedAt: new \DateTimeImmutable(),
            lastRetryAt: null,
        );

        $this->deadLetterStore->store($entry);
    }

    private function extractMessageId(\Symfony\Component\Messenger\Envelope $envelope): string
    {
        $stamp = $envelope->last(TransportMessageIdStamp::class);
        if ($stamp) {
            return (string) $stamp->getId();
        }
        $message = $envelope->getMessage();
        if (property_exists($message, 'messageId')) {
            return $message->messageId;
        }
        return Uuid::v4()->toRfc4122();
    }

    private function extractCorrelationId(object $message): ?string
    {
        return property_exists($message, 'correlationId') ? $message->correlationId : null;
    }

    private function extractCausationId(object $message): ?string
    {
        return property_exists($message, 'causationId') ? $message->causationId : null;
    }

    private function extractPayload(object $message): array
    {
        return json_decode(json_encode($message), true) ?? [];
    }
}
