<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\Payment\CommandHandlers\HandleOrderCreatedHandler;
use App\Domain\Order\Events\OrderCreatedIntegration;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

class IdempotencyTest extends KernelTestCase
{
    private Connection $connection;
    private HandleOrderCreatedHandler $handler;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->handler = self::getContainer()->get(HandleOrderCreatedHandler::class);
    }

    public function testIdempotency(): void
    {
        $messageId = Uuid::v4()->toRfc4122();
        $correlationId = Uuid::v4()->toRfc4122();
        $orderId = Uuid::v4()->toRfc4122();

        $event = new OrderCreatedIntegration(
            messageId: $messageId,
            correlationId: $correlationId,
            causationId: $correlationId,
            occurredAt: (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            orderId: $orderId,
            customerId: 'customer-1',
            items: [],
            total: 100.0
        );

        // 1. Enviar el mismo OrderCreatedIntegration al Payment handler dos veces
        ($this->handler)($event);
        ($this->handler)($event);

        // 2. Verificar que solo existe un registro en payment_ctx.payments
        $payments = $this->connection->fetchAllAssociative(
            'SELECT * FROM payment_ctx.payments WHERE order_id = ?',
            [$orderId]
        );
        $this->assertCount(1, $payments, 'Should only process payment once');

        // 3. Verificar que solo existe un registro en payment_ctx.processed_messages
        $processed = $this->connection->fetchAllAssociative(
            'SELECT * FROM payment_ctx.processed_messages WHERE message_id = ? AND consumer_name = ?',
            [$messageId, HandleOrderCreatedHandler::consumerName()]
        );
        $this->assertCount(1, $processed, 'Should only be marked as processed once');

        // 4. Verificar que el segundo procesamiento no lanza excepción (implícito porque no falló arriba)
        $this->assertTrue(true);
    }
}
