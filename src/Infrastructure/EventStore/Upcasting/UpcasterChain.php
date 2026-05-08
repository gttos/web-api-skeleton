<?php

declare(strict_types=1);

namespace App\Infrastructure\EventStore\Upcasting;

final class UpcasterChain
{
    /** @param UpcasterInterface[] $upcasters */
    public function __construct(private readonly array $upcasters = []) {}

    /**
     * Aplica la cadena de upcasters al payload del evento.
     * El Event Store original NO se modifica; esto se aplica solo en tiempo de lectura.
     */
    public function upcast(string $eventType, array $payload, int $eventVersion): array
    {
        $currentVersion = $eventVersion;
        $currentPayload = $payload;

        foreach ($this->upcasters as $upcaster) {
            if ($upcaster->canUpcast($eventType, $currentVersion)) {
                $currentPayload = $upcaster->upcast($currentPayload, $currentVersion);
                $currentVersion = $upcaster->targetVersion();
            }
        }

        return $currentPayload;
    }
}
