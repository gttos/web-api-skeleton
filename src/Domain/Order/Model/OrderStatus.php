<?php

declare(strict_types=1);

namespace App\Domain\Order\Model;

enum OrderStatus: string
{
    case Draft          = 'draft';
    case PendingPayment = 'pending_payment';
    case PendingStock   = 'pending_stock';
    case Confirmed      = 'confirmed';
    case Cancelled      = 'cancelled';
}
