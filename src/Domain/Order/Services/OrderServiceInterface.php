<?php

declare(strict_types=1);

namespace App\Domain\Order\Services;

interface OrderServiceInterface
{
    public function markPaymentReceived(string $orderId, string $paymentId, float $amount): void;
    public function markStockReserved(string $orderId, string $reservationId): void;
    public function cancelOrder(string $orderId, string $reason): void;
}
