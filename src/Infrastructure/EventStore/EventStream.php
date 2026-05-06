<?php

declare(strict_types=1);

namespace App\Infrastructure\EventStore;

final class EventStream implements \IteratorAggregate, \Countable
{
    /** @param StoredEvent[] $events */
    public function __construct(private readonly array $events) {}

    public static function empty(): self
    {
        return new self([]);
    }

    public static function fromStoredEvents(array $events): self
    {
        return new self($events);
    }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->events);
    }

    public function count(): int
    {
        return count($this->events);
    }

    public function isEmpty(): bool
    {
        return empty($this->events);
    }

    /** @return StoredEvent[] */
    public function toArray(): array
    {
        return $this->events;
    }
}
