<?php

declare(strict_types=1);

namespace App\Domain\Order\Model;

use App\Domain\Exceptions\InvalidStateTransitionException;
use App\Domain\Order\Events\OrderCancelled;
use App\Domain\Order\Events\OrderConfirmed;
use App\Domain\Order\Events\OrderCreated;
use App\Domain\Order\Events\OrderItemAdded;
use App\Domain\Order\Events\OrderPaymentReceived;
use App\Domain\Order\Events\OrderStockReserved;
use App\Infrastructure\EventStore\EventStream;
use App\Infrastructure\EventStore\Snapshot;

final class Order
{
    private string $orderId;
    private string $customerId;
    private OrderStatus $status;
    /** @var OrderItem[] */
    private array $items = [];
    private float $total = 0.0;
    private string $currency = 'EUR';
    private int $version = 0;
    /** @var object[] */
    private array $uncommittedEvents = [];

    private function __construct() {}

    // =========================================================
    // Factory methods
    // =========================================================

    /** @param OrderItem[] $items */
    public static function create(string $orderId, string $customerId, array $items, string $currency = 'EUR'): self
    {
        $order = new self();
        $total = array_sum(array_map(fn(OrderItem $i) => $i->lineTotal(), $items));
        $order->recordAndApply(new OrderCreated($orderId, $customerId, $items, $total, $currency));
        return $order;
    }

    public static function reconstitute(EventStream $stream): self
    {
        $order = new self();
        foreach ($stream as $storedEvent) {
            $order->applyStoredEvent($storedEvent);
        }
        return $order;
    }

    public static function reconstituteFromSnapshot(Snapshot $snapshot, EventStream $remainingEvents): self
    {
        $order = new self();
        $order->restoreFromSnapshot($snapshot);
        foreach ($remainingEvents as $storedEvent) {
            $order->applyStoredEvent($storedEvent);
        }
        return $order;
    }

    // =========================================================
    // Business methods
    // =========================================================

    public function addItem(OrderItem $item): void
    {
        $this->guardNotCancelled('addItem');
        $this->recordAndApply(new OrderItemAdded($this->orderId, $item, $this->total + $item->lineTotal()));
    }

    public function markPaymentReceived(string $paymentId, float $amount): void
    {
        if ($this->status !== OrderStatus::PendingPayment) {
            throw new InvalidStateTransitionException($this->orderId, $this->status->value, 'markPaymentReceived');
        }
        $this->recordAndApply(new OrderPaymentReceived($this->orderId, $paymentId, $amount));
    }

    public function markStockReserved(string $reservationId): void
    {
        if ($this->status !== OrderStatus::PendingStock) {
            throw new InvalidStateTransitionException($this->orderId, $this->status->value, 'markStockReserved');
        }
        $this->recordAndApply(new OrderStockReserved($this->orderId, $reservationId));
    }

    public function cancel(string $reason): void
    {
        if (in_array($this->status, [OrderStatus::Confirmed, OrderStatus::Cancelled], true)) {
            throw new InvalidStateTransitionException($this->orderId, $this->status->value, 'cancel');
        }
        $this->recordAndApply(new OrderCancelled($this->orderId, $reason, new \DateTimeImmutable()));
    }

    public function confirm(): void
    {
        if ($this->status !== OrderStatus::PendingStock) {
            throw new InvalidStateTransitionException($this->orderId, $this->status->value, 'confirm');
        }
        $this->recordAndApply(new OrderConfirmed($this->orderId, new \DateTimeImmutable()));
    }

    // =========================================================
    // Getters (read-only)
    // =========================================================

    public function id(): string { return $this->orderId; }
    public function customerId(): string { return $this->customerId; }
    public function status(): OrderStatus { return $this->status; }
    public function total(): float { return $this->total; }
    public function currency(): string { return $this->currency; }
    public function version(): int { return $this->version; }
    /** @return OrderItem[] */
    public function items(): array { return $this->items; }
    /** @return object[] */
    public function uncommittedEvents(): array { return $this->uncommittedEvents; }

    public function clearUncommittedEvents(): void
    {
        $this->uncommittedEvents = [];
    }

    // =========================================================
    // Snapshot support
    // =========================================================

    public function toSnapshot(): Snapshot
    {
        return new Snapshot(
            aggregateId: $this->orderId,
            aggregateType: 'Order',
            version: $this->version,
            state: [
                'order_id'    => $this->orderId,
                'customer_id' => $this->customerId,
                'status'      => $this->status->value,
                'items'       => array_map(fn(OrderItem $i) => $i->toArray(), $this->items),
                'total'       => $this->total,
                'currency'    => $this->currency,
            ],
            createdAt: new \DateTimeImmutable(),
        );
    }

    private function restoreFromSnapshot(Snapshot $snapshot): void
    {
        $state = $snapshot->state;
        $this->orderId    = $state['order_id'];
        $this->customerId = $state['customer_id'];
        $this->status     = OrderStatus::from($state['status']);
        $this->items      = array_map(fn(array $i) => OrderItem::fromArray($i), $state['items']);
        $this->total      = (float) $state['total'];
        $this->currency   = $state['currency'];
        $this->version    = $snapshot->version;
    }

    // =========================================================
    // Event application (private)
    // =========================================================

    private function recordAndApply(object $event): void
    {
        $this->applyDomainEvent($event);
        $this->uncommittedEvents[] = $event;
    }

    private function applyStoredEvent(\App\Infrastructure\EventStore\StoredEvent $storedEvent): void
    {
        // Reconstruir el evento de dominio desde el payload del StoredEvent
        $event = $this->deserializeEvent($storedEvent->eventType, $storedEvent->payload);
        $this->applyDomainEvent($event);
    }

    private function applyDomainEvent(object $event): void
    {
        match (true) {
            $event instanceof OrderCreated         => $this->applyOrderCreated($event),
            $event instanceof OrderItemAdded       => $this->applyOrderItemAdded($event),
            $event instanceof OrderPaymentReceived => $this->applyOrderPaymentReceived($event),
            $event instanceof OrderStockReserved   => $this->applyOrderStockReserved($event),
            $event instanceof OrderConfirmed       => $this->applyOrderConfirmed($event),
            $event instanceof OrderCancelled       => $this->applyOrderCancelled($event),
            default => throw new \LogicException('Unknown event type: ' . get_class($event)),
        };
        $this->version++;
    }

    private function applyOrderCreated(OrderCreated $event): void
    {
        $this->orderId    = $event->orderId;
        $this->customerId = $event->customerId;
        $this->items      = $event->items;
        $this->total      = $event->total;
        $this->currency   = $event->currency;
        $this->status     = OrderStatus::Draft;
    }

    private function applyOrderItemAdded(OrderItemAdded $event): void
    {
        $this->items[] = $event->item;
        $this->total   = $event->newTotal;
    }

    private function applyOrderPaymentReceived(OrderPaymentReceived $event): void
    {
        $this->status = OrderStatus::PendingStock;
    }

    private function applyOrderStockReserved(OrderStockReserved $event): void
    {
        $this->status = OrderStatus::Confirmed;
    }

    private function applyOrderConfirmed(OrderConfirmed $event): void
    {
        $this->status = OrderStatus::Confirmed;
    }

    private function applyOrderCancelled(OrderCancelled $event): void
    {
        $this->status = OrderStatus::Cancelled;
    }

    private function deserializeEvent(string $eventType, array $payload): object
    {
        return match ($eventType) {
            'OrderCreated' => new OrderCreated(
                orderId: $payload['order_id'],
                customerId: $payload['customer_id'],
                items: array_map(fn(array $i) => OrderItem::fromArray($i), $payload['items']),
                total: (float) $payload['total'],
                currency: $payload['currency'] ?? 'EUR',
            ),
            'OrderItemAdded' => new OrderItemAdded(
                orderId: $payload['order_id'],
                item: OrderItem::fromArray($payload['item']),
                newTotal: (float) $payload['new_total'],
            ),
            'OrderPaymentReceived' => new OrderPaymentReceived(
                orderId: $payload['order_id'],
                paymentId: $payload['payment_id'],
                amount: (float) $payload['amount'],
            ),
            'OrderStockReserved' => new OrderStockReserved(
                orderId: $payload['order_id'],
                reservationId: $payload['reservation_id'],
            ),
            'OrderConfirmed' => new OrderConfirmed(
                orderId: $payload['order_id'],
                confirmedAt: new \DateTimeImmutable($payload['confirmed_at']),
            ),
            'OrderCancelled' => new OrderCancelled(
                orderId: $payload['order_id'],
                reason: $payload['reason'],
                cancelledAt: new \DateTimeImmutable($payload['cancelled_at']),
            ),
            default => throw new \LogicException("Cannot deserialize unknown event type: {$eventType}"),
        };
    }

    // =========================================================
    // Guards
    // =========================================================

    private function guardNotCancelled(string $action): void
    {
        if ($this->status === OrderStatus::Cancelled) {
            throw new InvalidStateTransitionException($this->orderId, $this->status->value, $action);
        }
    }
}
