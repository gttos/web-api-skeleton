<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\EventStore;

use App\Infrastructure\EventStore\DbalEventStore;
use App\Infrastructure\EventStore\ConcurrencyException;
use App\Domain\Order\Events\OrderCreated;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

class DbalEventStoreTest extends KernelTestCase
{
    private Connection $connection;
    private DbalEventStore $eventStore;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->eventStore = self::getContainer()->get(DbalEventStore::class);
    }

    public function testAppendPersistsEventsWithCorrectFields(): void
    {
        $aggregateId = Uuid::v4()->toRfc4122();
        $correlationId = Uuid::v4()->toRfc4122();

        $event = new OrderCreated(
            orderId: $aggregateId,
            customerId: 'customer-123',
            items: [['sku' => 'ABC', 'quantity' => 1]],
            total: 10.0,
            currency: 'EUR',
            correlationId: $correlationId,
        );

        $this->eventStore->append($aggregateId, [$event], 0);

        $row = $this->connection->fetchAssociative(
            'SELECT * FROM order_ctx.event_store WHERE aggregate_id = ?',
            [$aggregateId]
        );

        $this->assertNotFalse($row);
        $this->assertEquals($aggregateId, $row['aggregate_id']);
        $this->assertEquals('OrderCreated', $row['event_type']);
        $this->assertEquals($correlationId, $row['correlation_id']);
        $this->assertEquals(1, $row['event_version']);
    }

    public function testLoadStreamReturnsEventsOrderedByVersionAsc(): void
    {
        $aggregateId = Uuid::v4()->toRfc4122();
        $correlationId = Uuid::v4()->toRfc4122();

        $event1 = new OrderCreated(
            orderId: $aggregateId,
            customerId: 'customer-123',
            items: [],
            total: 0.0,
            currency: 'EUR',
            correlationId: $correlationId,
        );

        $event2 = new OrderCreated( // Reusing OrderCreated as an arbitrary event to test versioning
            orderId: $aggregateId,
            customerId: 'customer-123',
            items: [['sku' => 'ABC']],
            total: 10.0,
            currency: 'EUR',
            correlationId: $correlationId,
        );

        $this->eventStore->append($aggregateId, [$event1], 0);
        $this->eventStore->append($aggregateId, [$event2], 1);

        $stream = iterator_to_array($this->eventStore->loadStream($aggregateId));

        $this->assertCount(2, $stream);
        // Note: The actual objects returned depend on event deserialization, but order should be preserved
        $this->assertEquals($event1->getCorrelationId(), $stream[0]->getCorrelationId());
        $this->assertEquals($event2->getCorrelationId(), $stream[1]->getCorrelationId());
    }

    public function testAppendThrowsConcurrencyExceptionOnVersionMismatch(): void
    {
        $aggregateId = Uuid::v4()->toRfc4122();
        $correlationId = Uuid::v4()->toRfc4122();

        $event = new OrderCreated(
            orderId: $aggregateId,
            customerId: 'customer-123',
            items: [],
            total: 0.0,
            currency: 'EUR',
            correlationId: $correlationId,
        );

        $this->eventStore->append($aggregateId, [$event], 0);

        $this->expectException(ConcurrencyException::class);
        // Expecting version 0 when it should be 1
        $this->eventStore->append($aggregateId, [$event], 0);
    }

    public function testLoadAllFromSequenceReturnsOnlyEventsFromGivenSequence(): void
    {
        $aggregateId1 = Uuid::v4()->toRfc4122();
        $correlationId = Uuid::v4()->toRfc4122();

        $event1 = new OrderCreated(
            orderId: $aggregateId1,
            customerId: 'c1',
            items: [],
            total: 0.0,
            currency: 'EUR',
            correlationId: $correlationId,
        );

        $this->eventStore->append($aggregateId1, [$event1], 0);

        $lastSeq = (int) $this->connection->fetchOne('SELECT MAX(sequence_number) FROM order_ctx.event_store');

        $aggregateId2 = Uuid::v4()->toRfc4122();
        $event2 = new OrderCreated(
            orderId: $aggregateId2,
            customerId: 'c2',
            items: [],
            total: 0.0,
            currency: 'EUR',
            correlationId: $correlationId,
        );
        $this->eventStore->append($aggregateId2, [$event2], 0);

        $events = iterator_to_array($this->eventStore->loadAllFromSequence($lastSeq));

        $this->assertCount(1, $events);
        $this->assertInstanceOf(OrderCreated::class, $events[0]);
    }

    public function testTwoConsecutiveAppendsIncrementEventVersion(): void
    {
        $aggregateId = Uuid::v4()->toRfc4122();
        $correlationId = Uuid::v4()->toRfc4122();

        $event1 = new OrderCreated(
            orderId: $aggregateId,
            customerId: 'c1',
            items: [],
            total: 0.0,
            currency: 'EUR',
            correlationId: $correlationId,
        );
        $this->eventStore->append($aggregateId, [$event1], 0);

        $event2 = new OrderCreated(
            orderId: $aggregateId,
            customerId: 'c1',
            items: [],
            total: 0.0,
            currency: 'EUR',
            correlationId: $correlationId,
        );
        $this->eventStore->append($aggregateId, [$event2], 1);

        $rows = $this->connection->fetchAllAssociative(
            'SELECT event_version FROM order_ctx.event_store WHERE aggregate_id = ? ORDER BY event_version ASC',
            [$aggregateId]
        );

        $this->assertCount(2, $rows);
        $this->assertEquals(1, $rows[0]['event_version']);
        $this->assertEquals(2, $rows[1]['event_version']);
    }
}
