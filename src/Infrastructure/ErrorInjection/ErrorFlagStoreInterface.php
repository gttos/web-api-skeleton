<?php

declare(strict_types=1);

namespace App\Infrastructure\ErrorInjection;

interface ErrorFlagStoreInterface
{
    public function setFlag(string $correlationId, string $errorType, array $config = []): void;
    public function getFlag(string $correlationId): ?ErrorFlag;
    public function clearFlag(string $correlationId): void;
    /** Incrementa el contador de intentos fallidos para TEMP_FAILURE */
    public function incrementFailureCount(string $correlationId): int;
    public function getFailureCount(string $correlationId): int;
}
