<?php

declare(strict_types=1);

namespace App\Infrastructure\Projection;

use App\Infrastructure\EventStore\EventStoreInterface;
use Psr\Log\LoggerInterface;

final class ProjectionEngine
{
    /** @param ProjectorInterface[] $projectors */
    public function __construct(
        private readonly EventStoreInterface $eventStore,
        private readonly array $projectors,
        private readonly LoggerInterface $logger,
    ) {}

    public function projectEvent(\App\Infrastructure\EventStore\StoredEvent $event): void
    {
        foreach ($this->projectors as $projector) {
            if (in_array($event->eventType, $projector->supportedEvents(), true)) {
                $projector->handle($event);
            }
        }
    }

    public function rebuild(?int $fromSequence = null): void
    {
        $this->logger->info('Starting projection rebuild', ['from_sequence' => $fromSequence]);

        // Reset all projectors only if we do a full rebuild
        if ($fromSequence === null) {
            foreach ($this->projectors as $projector) {
                $projector->reset();
            }
        }

        $stream = $this->eventStore->loadAllFromSequence($fromSequence ?? 0);
        $count = 0;

        foreach ($stream as $event) {
            $this->projectEvent($event);
            $count++;

            if ($count % 1000 === 0) {
                $this->logger->info('Projection rebuild progress', [
                    'events_processed' => $count,
                    'last_sequence'    => $event->sequenceNumber,
                ]);
            }
        }

        $this->logger->info('Projection rebuild completed', ['total_events' => $count]);
    }
}
