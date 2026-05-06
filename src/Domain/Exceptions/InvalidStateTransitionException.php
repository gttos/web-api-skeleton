<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

final class InvalidStateTransitionException extends \DomainException
{
    public function __construct(string $aggregateId, string $currentStatus, string $attemptedAction)
    {
        parent::__construct(sprintf(
            'Cannot perform "%s" on order %s in status "%s"',
            $attemptedAction,
            $aggregateId,
            $currentStatus
        ));
    }
}
