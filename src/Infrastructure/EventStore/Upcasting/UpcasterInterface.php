<?php

declare(strict_types=1);

namespace App\Infrastructure\EventStore\Upcasting;

interface UpcasterInterface
{
    /** Retorna true si este upcaster puede transformar el evento dado */
    public function canUpcast(string $eventType, int $fromVersion): bool;

    /** Transforma el payload de la versión fromVersion a targetVersion() */
    public function upcast(array $payload, int $fromVersion): array;

    /** Versión de destino después de aplicar este upcaster */
    public function targetVersion(): int;
}
