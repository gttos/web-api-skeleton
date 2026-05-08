<?php

declare(strict_types=1);

namespace App\Infrastructure\EventStore\Upcasting\Upcasters;

use App\Infrastructure\EventStore\Upcasting\UpcasterInterface;

final class OrderCreatedV1ToV2Upcaster implements UpcasterInterface
{
    public function canUpcast(string $eventType, int $fromVersion): bool
    {
        return $eventType === 'OrderCreated' && $fromVersion === 1;
    }

    public function upcast(array $payload, int $fromVersion): array
    {
        // Añadir campo currency con valor por defecto para eventos históricos
        if (!isset($payload['currency'])) {
            $payload['currency'] = 'EUR';
        }
        return $payload;
    }

    public function targetVersion(): int
    {
        return 2;
    }
}
