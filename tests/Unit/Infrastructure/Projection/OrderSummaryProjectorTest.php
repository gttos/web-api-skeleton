<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Projection;

use App\Domain\Order\Events\OrderCreated;
use App\Domain\Order\Events\OrderCancelled;
use App\Infrastructure\Projection\OrderSummaryProjector;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

class OrderSummaryProjectorTest extends KernelTestCase
{
    private Connection $connection;
    private OrderSummaryProjector $projector;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->projector = self::getContainer()->get(OrderSummaryProjector::class);
        $this->projector->reset();
    }

    public function testHandleOrderCreatedInsertsRow(): void
    {
        $orderId = Uuid::v4()->toRfc4122();

        $event = new OrderCreated(
            orderId: $orderId,
            customerId: 'customer-123',
            items: [['sku' => 'A', 'quantity' => 1]],
            total: 100.0,
            currency: 'EUR',
            correlationId: Uuid::v4()->toRfc4122(),
        );

        $this->projector->handle($event);

        $row = $this->connection->fetchAssociative(
            'SELECT * FROM order_ctx.order_projections WHERE order_id = ?',
            [$orderId]
        );

        $this->assertNotFalse($row);
        $this->assertEquals($orderId, $row['order_id']);
        $this->assertEquals('draft', $row['status']);
        $this->assertEquals('customer-123', $row['customer_id']);
    }

    public function testHandleOrderCancelledUpdatesStatus(): void
    {
        $orderId = Uuid::v4()->toRfc4122();

        $created = new OrderCreated(
            orderId: $orderId,
            customerId: 'customer-123',
            items: [],
            total: 100.0,
            currency: 'EUR',
            correlationId: Uuid::v4()->toRfc4122(),
        );

        $this->projector->handle($created);

        $cancelled = new OrderCancelled(
            orderId: $orderId,
            reason: 'User request',
            correlationId: Uuid::v4()->toRfc4122(),
        );

        $this->projector->handle($cancelled);

        $row = $this->connection->fetchAssociative(
            'SELECT * FROM order_ctx.order_projections WHERE order_id = ?',
            [$orderId]
        );

        $this->assertNotFalse($row);
        $this->assertEquals('cancelled', $row['status']);
    }

    public function testResetEmptiesTable(): void
    {
        $orderId = Uuid::v4()->toRfc4122();
        $created = new OrderCreated(
            orderId: $orderId,
            customerId: 'customer-123',
            items: [],
            total: 100.0,
            currency: 'EUR',
            correlationId: Uuid::v4()->toRfc4122(),
        );

        $this->projector->handle($created);

        $this->projector->reset();

        $count = $this->connection->fetchOne('SELECT COUNT(*) FROM order_ctx.order_projections');
        $this->assertEquals(0, $count);
    }

    public function testIdempotencyOfProjector(): void
    {
        $orderId = Uuid::v4()->toRfc4122();
        $created = new OrderCreated(
            orderId: $orderId,
            customerId: 'customer-123',
            items: [],
            total: 100.0,
            currency: 'EUR',
            correlationId: Uuid::v4()->toRfc4122(),
        );

        // Process twice
        $this->projector->handle($created);
        $this->projector->handle($created);

        $count = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM order_ctx.order_projections WHERE order_id = ?',
            [$orderId]
        );

        $this->assertEquals(1, $count, 'Processing same event twice should not duplicate the row');
    }
}
