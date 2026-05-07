<?php

declare(strict_types=1);

namespace App\Application\Order;

use App\Domain\Order\Services\OrderRepositoryInterface;
use App\Domain\Order\Services\OrderServiceInterface;
use App\Infrastructure\Messaging\CorrelationContext;
use Symfony\Component\Uid\Uuid;

final class OrderService implements OrderServiceInterface
{
    public function __construct(private readonly OrderRepositoryInterface $repository) {}

    public function markPaymentReceived(string $orderId, string $paymentId, float $amount): void
    {
        $context = CorrelationContext::initiate();
        $order = $this->repository->findById($orderId);
        $order->markPaymentReceived($paymentId, $amount);
        $this->repository->save($order, $context);
    }

    public function markStockReserved(string $orderId, string $reservationId): void
    {
        $context = CorrelationContext::initiate();
        $order = $this->repository->findById($orderId);
        $order->markStockReserved($reservationId);
        $this->repository->save($order, $context);
    }

    public function cancelOrder(string $orderId, string $reason): void
    {
        $context = CorrelationContext::initiate();
        $order = $this->repository->findById($orderId);
        $order->cancel($reason);
        $this->repository->save($order, $context);
    }
}
