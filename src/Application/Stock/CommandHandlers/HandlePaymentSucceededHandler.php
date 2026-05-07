<?php

declare(strict_types=1);

namespace App\Application\Stock\CommandHandlers;

use App\Domain\Order\Services\OrderServiceInterface;
use App\Domain\Payment\Events\PaymentSucceeded;
use App\Domain\Stock\Events\StockReservationFailed;
use App\Domain\Stock\Events\StockReserved;
use App\Infrastructure\Messaging\IdempotentMessageHandler;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

final class HandlePaymentSucceededHandler extends IdempotentMessageHandler
{
    public function __construct(
        private readonly MessageBusInterface $eventBus,
        private readonly OrderServiceInterface $orderService,
        \App\Infrastructure\Messaging\ProcessedMessageStore $processedStore,
        Connection $connection,
        LoggerInterface $logger,
    ) {
        parent::__construct($processedStore, $connection, $logger);
    }

    public static function consumerName(): string { return 'stock.handle_payment_succeeded'; }
    public static function schema(): string { return 'stock_ctx'; }

    public function __invoke(PaymentSucceeded $message): void
    {
        $this->processIdempotently($message);
    }

    protected function handle(object $message): void
    {
        /** @var PaymentSucceeded $message */
        $reservationId = Uuid::v4()->toRfc4122();

        $this->connection->insert('stock_ctx.stock_reservations', [
            'reservation_id' => $reservationId,
            'order_id'       => $message->orderId,
            'items'          => json_encode([]),  // En producción vendría del Order
            'status'         => 'reserved',
            'correlation_id' => $message->correlationId,
        ]);

        // Notificar al Order Context (síncrono)
        $this->orderService->markStockReserved($message->orderId, $reservationId);

        // Emitir integration event
        $this->eventBus->dispatch(new StockReserved(
            messageId: Uuid::v4()->toRfc4122(),
            correlationId: $message->correlationId,
            causationId: $message->messageId,
            occurredAt: (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            reservationId: $reservationId,
            orderId: $message->orderId,
            items: [],
        ));
    }
}
