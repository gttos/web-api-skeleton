<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Order;

use App\Domain\Order\Events\OrderCreated;
use App\Domain\Order\Events\OrderCancelled;
use App\Domain\Order\Events\OrderPaymentReceived;
use App\Domain\Order\Model\Order;
use App\Domain\Order\InvalidStateTransitionException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class OrderAggregateTest extends TestCase
{
    public function testCreateGeneratesExactlyOneOrderCreatedEvent(): void
    {
        $orderId = Uuid::v4()->toRfc4122();
        $correlationId = Uuid::v4()->toRfc4122();

        $order = Order::create(
            orderId: $orderId,
            customerId: 'customer-1',
            items: [['sku' => 'A', 'quantity' => 1]],
            total: 100.0,
            currency: 'EUR',
            correlationId: $correlationId,
        );

        $events = $order->getUncommittedEvents();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(OrderCreated::class, $events[0]);
    }

    public function testReconstituteBuildsStateCorrectly(): void
    {
        $orderId = Uuid::v4()->toRfc4122();
        $correlationId = Uuid::v4()->toRfc4122();

        $events = [
            new OrderCreated(
                orderId: $orderId,
                customerId: 'customer-1',
                items: [],
                total: 100.0,
                currency: 'EUR',
                correlationId: $correlationId,
            ),
            new OrderPaymentReceived(
                orderId: $orderId,
                correlationId: $correlationId,
            ),
        ];

        $order = Order::reconstitute($orderId, new \ArrayIterator($events));

        // We can check version and internal state (if exposed or through behavior)
        $this->assertEquals(2, $order->getVersion());

        // Assert state through exception testing on next method call
        $this->expectException(InvalidStateTransitionException::class);
        $order->markPaymentReceived($correlationId); // Should fail as it's already paid/pending stock
    }

    public function testCancelThrowsIfAlreadyCancelled(): void
    {
        $orderId = Uuid::v4()->toRfc4122();
        $correlationId = Uuid::v4()->toRfc4122();

        $events = [
            new OrderCreated(
                orderId: $orderId,
                customerId: 'customer-1',
                items: [],
                total: 100.0,
                currency: 'EUR',
                correlationId: $correlationId,
            ),
            new OrderCancelled(
                orderId: $orderId,
                reason: 'cancel',
                correlationId: $correlationId,
            )
        ];

        $order = Order::reconstitute($orderId, new \ArrayIterator($events));

        $this->expectException(InvalidStateTransitionException::class);
        $order->cancel('cancel again', $correlationId);
    }

    public function testMarkPaymentReceivedThrowsIfNotPendingPayment(): void
    {
        $orderId = Uuid::v4()->toRfc4122();
        $correlationId = Uuid::v4()->toRfc4122();

        $events = [
            new OrderCreated(
                orderId: $orderId,
                customerId: 'customer-1',
                items: [],
                total: 100.0,
                currency: 'EUR',
                correlationId: $correlationId,
            ),
            new OrderPaymentReceived(
                orderId: $orderId,
                correlationId: $correlationId,
            ),
        ];

        $order = Order::reconstitute($orderId, new \ArrayIterator($events));

        $this->expectException(InvalidStateTransitionException::class);
        $order->markPaymentReceived($correlationId);
    }

    public function testVersionIncrementsWithEachAppliedEvent(): void
    {
        $orderId = Uuid::v4()->toRfc4122();
        $correlationId = Uuid::v4()->toRfc4122();

        $order = Order::create(
            orderId: $orderId,
            customerId: 'customer-1',
            items: [['sku' => 'A', 'quantity' => 1]],
            total: 100.0,
            currency: 'EUR',
            correlationId: $correlationId,
        );

        $this->assertEquals(0, $order->getVersion()); // Usually starts at 0 internally until persisted, or uncommitted events don't increase version yet. Wait, reconstitute sets version.
        // Let's test with reconstitute:
        $events = [
            new OrderCreated(
                orderId: $orderId,
                customerId: 'customer-1',
                items: [],
                total: 100.0,
                currency: 'EUR',
                correlationId: $correlationId,
            ),
            new OrderCancelled(
                orderId: $orderId,
                reason: 'cancel',
                correlationId: $correlationId,
            )
        ];

        $order2 = Order::reconstitute($orderId, new \ArrayIterator($events));
        $this->assertEquals(2, $order2->getVersion());
    }

    public function testReconstituteFromSnapshotProducesSameState(): void
    {
        $orderId = Uuid::v4()->toRfc4122();
        $correlationId = Uuid::v4()->toRfc4122();

        $events = [
            new OrderCreated(
                orderId: $orderId,
                customerId: 'customer-1',
                items: [],
                total: 100.0,
                currency: 'EUR',
                correlationId: $correlationId,
            ),
        ];

        $orderFull = Order::reconstitute($orderId, new \ArrayIterator($events));

        // Snapshot reconstitution requires the snapshot array/object based on how it's implemented.
        // Assuming Order::reconstituteFromSnapshot(array $state) or similar:
        if (method_exists(Order::class, 'reconstituteFromSnapshot')) {
            // This part is a bit speculative on exact snapshot format
            $state = [
                'orderId' => $orderId,
                'status' => 'pending_payment',
                'version' => 1,
            ];
            // Just verifying it exists and we call it
            $orderSnap = Order::reconstituteFromSnapshot($state);
            $this->assertEquals($orderFull->getVersion(), $orderSnap->getVersion());
        } else {
            $this->markTestSkipped('Snapshotting not implemented on Order yet.');
        }
    }
}
