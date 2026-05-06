<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

final class ConcurrencyException extends \RuntimeException
{
    public function __construct(string $aggregateId, int $expectedVersion, int $actualVersion)
    {
        parent::__construct(sprintf(
            'Concurrency conflict for aggregate %s: expected version %d, actual version %d',
            $aggregateId,
            $expectedVersion,
            $actualVersion
        ));
    }
}
