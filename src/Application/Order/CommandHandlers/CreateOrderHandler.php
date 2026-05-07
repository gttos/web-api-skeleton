<?php

declare(strict_types=1);

namespace App\Application\Order\CommandHandlers;

use App\Domain\Order\Commands\CreateOrder;
use App\Domain\Order\Model\Order;
use App\Domain\Order\Model\OrderItem;
use App\Domain\Order\Services\OrderRepositoryInterface;
use App\Infrastructure\Messaging\CorrelationContext;
use App\Infrastructure\Outbox\OutboxMessage;
use App\Infrastructure\Outbox\OutboxStoreInterface;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

final class CreateOrderHandler
{
    public function __construct(
        private readonly OrderRepositoryInterface $repository,
        private readonly OutboxStoreInterface $outboxStore,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(CreateOrder $command): void
    {
        $context = new CorrelationContext(
            correlationId: $command->correlationId,
            causationId: Uuid::v4()->toRfc4122(),
        );

        $items = array_map(
            fn(array $item) => new OrderItem(
                sku: $item['sku'],
                name: $item['name'],
                quantity: (int) $item['quantity'],
                unitPrice: (float) $item['unit_price'],
            ),
            $command->items
        );

        $order = Order::create($command->orderId, $command->customerId, $items, $command->currency);

        // Transacción atómica: Event Store + Outbox
        $this->connection->transactional(function () use ($order, $context, $command): void {
            $this->repository->save($order, $context);

            // Almacenar Integration Event en Outbox (misma transacción)
            $this->outboxStore->store(new OutboxMessage(
                id: Uuid::v4()->toRfc4122(),
                aggregateId: $order->id(),
                eventType: 'OrderCreatedIntegration',
                payload: [
                    'order_id'    => $order->id(),
                    'customer_id' => $order->customerId(),
                    'items'       => array_map(fn($i) => $i->toArray(), $order->items()),
                    'total'       => $order->total(),
                    'currency'    => $order->currency(),
                ],
                metadata: ['source_context' => 'order'],
                correlationId: $context->correlationId,
                causationId: $context->causationId,
            ));
        });

        $this->logger->info('Order created', [
            'order_id'       => $command->orderId,
            'correlation_id' => $context->correlationId,
        ]);
    }
}
