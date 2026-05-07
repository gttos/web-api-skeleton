<?php

declare(strict_types=1);

namespace App\Application\Payment\CommandHandlers;

use App\Domain\Order\Events\OrderCreatedIntegration;
use App\Domain\Order\Services\OrderServiceInterface;
use App\Domain\Payment\Events\PaymentFailed;
use App\Domain\Payment\Events\PaymentSucceeded;
use App\Infrastructure\Messaging\IdempotentMessageHandler;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

final class HandleOrderCreatedHandler extends IdempotentMessageHandler
{
    public function __construct(
        private readonly MessageBusInterface $eventBus,
        private readonly OrderServiceInterface $orderService,  // Patrón 1: service call síncrono
        \App\Infrastructure\Messaging\ProcessedMessageStore $processedStore,
        Connection $connection,
        LoggerInterface $logger,
    ) {
        parent::__construct($processedStore, $connection, $logger);
    }

    public static function consumerName(): string { return 'payment.handle_order_created'; }
    public static function schema(): string { return 'payment_ctx'; }


    public function __invoke(OrderCreatedIntegration $message): void
    {
        $this->processIdempotently($message);
    }

    protected function handle(object $message): void
    {
        /** @var OrderCreatedIntegration $message */
        $paymentId = Uuid::v4()->toRfc4122();

        // Registrar el pago en payment_ctx.payments
        $this->connection->insert('payment_ctx.payments', [
            'payment_id'     => $paymentId,
            'order_id'       => $message->orderId,
            'amount'         => $message->total,
            'status'         => 'processing',
            'correlation_id' => $message->correlationId,
        ]);

        // Simular procesamiento de pago (siempre exitoso en el happy path)
        // En Fase 07 se añadirá la inyección de errores
        $succeeded = true;

        if ($succeeded) {
            // Patrón 1: Service call síncrono al Order Context
            $this->orderService->markPaymentReceived($message->orderId, $paymentId, $message->total);

            // Actualizar estado del pago
            $this->connection->executeStatement(
                'UPDATE payment_ctx.payments SET status = ? WHERE payment_id = ?',
                ['succeeded', $paymentId]
            );

            // Emitir integration event
            $this->eventBus->dispatch(new PaymentSucceeded(
                messageId: Uuid::v4()->toRfc4122(),
                correlationId: $message->correlationId,
                causationId: $message->messageId,
                occurredAt: (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                paymentId: $paymentId,
                orderId: $message->orderId,
                amount: $message->total,
            ));
        } else {
            $this->connection->executeStatement(
                'UPDATE payment_ctx.payments SET status = ? WHERE payment_id = ?',
                ['failed', $paymentId]
            );

            $this->eventBus->dispatch(new PaymentFailed(
                messageId: Uuid::v4()->toRfc4122(),
                correlationId: $message->correlationId,
                causationId: $message->messageId,
                occurredAt: (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                paymentId: $paymentId,
                orderId: $message->orderId,
                reason: 'Payment declined',
            ));
        }
    }
}
