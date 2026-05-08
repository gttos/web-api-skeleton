<?php

declare(strict_types=1);

namespace App\Infrastructure\ErrorInjection;

final class ErrorFlag
{
    // Tipos de error soportados
    public const TEMP_FAILURE         = 'TEMP_FAILURE';
    public const PERM_FAILURE         = 'PERM_FAILURE';
    public const INVALID_PAYLOAD      = 'INVALID_PAYLOAD';
    public const SLOW_CONSUMER        = 'SLOW_CONSUMER';
    public const CONCURRENCY_CONFLICT = 'CONCURRENCY_CONFLICT';

    public function __construct(
        public readonly string $type,
        public readonly string $correlationId,
        public readonly array $config = [],
        // Para TEMP_FAILURE: cuántas veces debe fallar antes de tener éxito
        // Para SLOW_CONSUMER: delay_ms
    ) {}
}
