<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Order;

use App\Domain\Order\Model\Order;
use App\Domain\Order\Services\OrderRepositoryInterface;
use App\Infrastructure\EventStore\EventStoreInterface;
use App\Infrastructure\EventStore\SnapshotStoreInterface;
use App\Infrastructure\Messaging\CorrelationContext;
use Doctrine\ORM\EntityNotFoundException;

final class DbalOrderRepository implements OrderRepositoryInterface
{
    public function __construct(
        private readonly EventStoreInterface $eventStore,
        private readonly SnapshotStoreInterface $snapshotStore,
    ) {}

    public function findById(string $orderId): Order
    {
        $snapshot = $this->snapshotStore->load($orderId);

        if ($snapshot !== null) {
            $stream = $this->eventStore->loadStream($orderId, $snapshot->version + 1);
            return Order::reconstituteFromSnapshot($snapshot, $stream);
        }

        $stream = $this->eventStore->loadStream($orderId);

        if ($stream->isEmpty()) {
            throw new EntityNotFoundException("Order with id {$orderId} not found");
        }

        return Order::reconstitute($stream);
    }

    public function save(Order $order, CorrelationContext $context): void
    {
        $uncommitted = $order->uncommittedEvents();

        if (empty($uncommitted)) {
            return;
        }

        $expectedVersion = $order->version() - count($uncommitted);

        $this->eventStore->append(
            aggregateId: $order->id(),
            aggregateType: 'Order',
            events: $uncommitted,
            expectedVersion: $expectedVersion,
            context: $context,
        );

        $order->clearUncommittedEvents();
    }
}
