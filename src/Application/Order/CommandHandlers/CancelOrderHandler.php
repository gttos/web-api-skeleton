<?php

declare(strict_types=1);

namespace App\Application\Order\CommandHandlers;

use App\Domain\Order\Commands\CancelOrder;
use App\Domain\Order\Services\OrderRepositoryInterface;
use App\Infrastructure\Messaging\CorrelationContext;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

final class CancelOrderHandler
{
    public function __construct(
        private readonly OrderRepositoryInterface $repository,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(CancelOrder $command): void
    {
        $context = new CorrelationContext(
            correlationId: $command->correlationId,
            causationId: Uuid::v4()->toRfc4122(),
        );

        $order = $this->repository->findById($command->orderId);
        $order->cancel($command->reason);
        $this->repository->save($order, $context);

        $this->logger->info('Order cancelled', [
            'order_id'       => $command->orderId,
            'reason'         => $command->reason,
            'correlation_id' => $context->correlationId,
        ]);
    }
}
