<?php

declare(strict_types=1);

namespace App\Domain\Order\Services;

use App\Domain\Order\Model\Order;
use App\Infrastructure\Messaging\CorrelationContext;

interface OrderRepositoryInterface
{
    public function findById(string $orderId): Order;
    public function save(Order $order, CorrelationContext $context): void;
}
