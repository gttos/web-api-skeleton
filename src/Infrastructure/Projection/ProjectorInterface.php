<?php

declare(strict_types=1);

namespace App\Infrastructure\Projection;

use App\Infrastructure\EventStore\StoredEvent;

interface ProjectorInterface
{
    public function handle(StoredEvent $event): void;
    public function reset(): void;
    /** @return string[] Lista de eventType que este projector maneja */
    public function supportedEvents(): array;
}
