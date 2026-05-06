<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

use Symfony\Component\Uid\Uuid;

final class CorrelationContext
{
    public function __construct(
        public readonly string $correlationId,
        public readonly string $causationId,
    ) {}

    /** Inicia un nuevo flujo generando correlation_id y causation_id frescos */
    public static function initiate(): self
    {
        $id = Uuid::v4()->toRfc4122();
        return new self($id, $id);
    }

    /** Crea un nuevo contexto con el mismo correlation_id pero nuevo causation_id */
    public function causedBy(string $newCausationId): self
    {
        return new self($this->correlationId, $newCausationId);
    }
}
