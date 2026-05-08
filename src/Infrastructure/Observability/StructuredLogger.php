<?php

declare(strict_types=1);

namespace App\Infrastructure\Observability;

use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;

final class StructuredLogger implements LoggerInterface
{
    use LoggerTrait;

    private array $defaultContext = [];

    public function __construct(private readonly LoggerInterface $inner) {}

    public function withContext(array $context): self
    {
        $clone = clone $this;
        $clone->defaultContext = array_merge($this->defaultContext, $context);
        return $clone;
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->inner->log($level, $message, array_merge($this->defaultContext, $context));
    }
}
