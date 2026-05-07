<?php

declare(strict_types=1);

namespace App\Infrastructure\Projection;

use App\Infrastructure\EventStore\StoredEvent;
use Doctrine\DBAL\Connection;

final class OrderSummaryProjector implements ProjectorInterface
{
    public function __construct(private readonly Connection $connection) {}

    public function supportedEvents(): array
    {
        return [
            'OrderCreated',
            'OrderItemAdded',
            'OrderPaymentReceived',
            'OrderStockReserved',
            'OrderConfirmed',
            'OrderCancelled',
        ];
    }

    public function handle(StoredEvent $event): void
    {
        match ($event->eventType) {
            'OrderCreated'         => $this->onOrderCreated($event),
            'OrderItemAdded'       => $this->onOrderItemAdded($event),
            'OrderPaymentReceived' => $this->onOrderPaymentReceived($event),
            'OrderStockReserved'   => $this->onOrderStockReserved($event),
            'OrderConfirmed'       => $this->onOrderConfirmed($event),
            'OrderCancelled'       => $this->onOrderCancelled($event),
            default                => null,
        };
    }

    public function reset(): void
    {
        $this->connection->executeStatement('TRUNCATE order_ctx.order_projections');
    }

    private function onOrderCreated(StoredEvent $event): void
    {
        $p = $event->payload;
        $this->connection->executeStatement(
            'INSERT INTO order_ctx.order_projections (order_id, status, customer_id, items, total, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT (order_id) DO NOTHING',
            [
                $p['order_id'],
                'draft',
                $p['customer_id'],
                json_encode($p['items']),
                $p['total'],
                $event->occurredAt->format('Y-m-d H:i:s.u P'),
                $event->occurredAt->format('Y-m-d H:i:s.u P'),
            ]
        );
    }

    private function onOrderItemAdded(StoredEvent $event): void
    {
        $p = $event->payload;
        // Actualizar total y añadir item al array JSONB
        $this->connection->executeStatement(
            'UPDATE order_ctx.order_projections
             SET total = ?, items = items || ?::jsonb, updated_at = ?
             WHERE order_id = ?',
            [
                $p['new_total'],
                json_encode([$p['item']]),
                $event->occurredAt->format('Y-m-d H:i:s.u P'),
                $p['order_id'],
            ]
        );
    }

    private function onOrderPaymentReceived(StoredEvent $event): void
    {
        $this->connection->executeStatement(
            'UPDATE order_ctx.order_projections SET status = ?, updated_at = ? WHERE order_id = ?',
            ['pending_stock', $event->occurredAt->format('Y-m-d H:i:s.u P'), $event->payload['order_id']]
        );
    }

    private function onOrderStockReserved(StoredEvent $event): void
    {
        $this->connection->executeStatement(
            'UPDATE order_ctx.order_projections SET status = ?, updated_at = ? WHERE order_id = ?',
            ['stock_reserved', $event->occurredAt->format('Y-m-d H:i:s.u P'), $event->payload['order_id']]
        );
    }

    private function onOrderConfirmed(StoredEvent $event): void
    {
        $this->connection->executeStatement(
            'UPDATE order_ctx.order_projections SET status = ?, updated_at = ? WHERE order_id = ?',
            ['confirmed', $event->occurredAt->format('Y-m-d H:i:s.u P'), $event->payload['order_id']]
        );
    }

    private function onOrderCancelled(StoredEvent $event): void
    {
        $this->connection->executeStatement(
            'UPDATE order_ctx.order_projections SET status = ?, updated_at = ? WHERE order_id = ?',
            ['cancelled', $event->occurredAt->format('Y-m-d H:i:s.u P'), $event->payload['order_id']]
        );
    }
}
