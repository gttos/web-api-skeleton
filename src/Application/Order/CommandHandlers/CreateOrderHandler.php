<?php

declare(strict_types=1);

namespace App\Application\Order\CommandHandlers;

use App\Domain\Order\Commands\CreateOrder;
use App\Domain\Order\Model\Order;
use App\Domain\Order\Model\OrderItem;
use App\Domain\Order\Services\OrderRepositoryInterface;
use App\Infrastructure\Messaging\CorrelationContext;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

final class CreateOrderHandler
{
    public function __construct(
        private readonly OrderRepositoryInterface $repository,
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

        $this->repository->save($order, $context);

        $this->logger->info('Order created', [
            'order_id'       => $command->orderId,
            'customer_id'    => $command->customerId,
            'total'          => $order->total(),
            'correlation_id' => $context->correlationId,
        ]);
    }
}
