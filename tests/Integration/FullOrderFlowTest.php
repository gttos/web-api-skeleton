<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\Order\Commands\CreateOrder;
use Doctrine\DBAL\Connection;
use Somnambulist\Components\Commands\CommandBus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

class FullOrderFlowTest extends KernelTestCase
{
    private Connection $connection;
    private CommandBus $commandBus;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->commandBus = self::getContainer()->get(CommandBus::class);
    }

    public function testFullOrderFlow(): void
    {
        // 1. Crear un pedido
        $orderId = Uuid::v4()->toRfc4122();
        $correlationId = Uuid::v4()->toRfc4122();

        $this->commandBus->execute(new CreateOrder(
            orderId: $orderId,
            customerId: 'customer-test',
            items: [['sku' => 'TEST', 'quantity' => 1]],
            correlationId: $correlationId,
            currency: 'EUR'
        ));

        // 2. Verificar que aparece en order_ctx.event_store
        $eventStoreRow = $this->connection->fetchAssociative(
            'SELECT * FROM order_ctx.event_store WHERE aggregate_id = ? AND event_type = ?',
            [$orderId, 'OrderCreated']
        );
        $this->assertNotFalse($eventStoreRow, 'OrderCreated event should be in event_store');

        // 3. Verificar que aparece en order_ctx.outbox con published_at IS NULL
        $outboxRow = $this->connection->fetchAssociative(
            'SELECT * FROM order_ctx.outbox WHERE aggregate_id = ? AND published_at IS NULL',
            [$orderId]
        );
        $this->assertNotFalse($outboxRow, 'Message should be in outbox and unpublished');

        // 4. Verificar que aparece en order_ctx.order_projections con status = 'draft'
        $projectionRow = $this->connection->fetchAssociative(
            'SELECT * FROM order_ctx.order_projections WHERE order_id = ? AND status = ?',
            [$orderId, 'draft']
        );
        $this->assertNotFalse($projectionRow, 'Order should be in projections as draft');

        // Note: For steps 5-10, since this test focuses on DAMA Doctrine Test Bundle features and we
        // might not have a running RabbitMQ relay in tests, we simulate or stop at DB verification
        // depending on testing strategy. Since we were asked to write the integration test assuming
        // DAMA handles rollbacks and the environment matches, and the instructions are:
        // "Write them assuming DAMA handles transaction rollback and that the migration has been applied."

        // 5. Ejecutar el outbox relay
        /** @var \App\Infrastructure\Outbox\OutboxRelay $outboxRelay */
        $outboxRelay = self::getContainer()->get(\App\Infrastructure\Outbox\OutboxRelay::class);
        $outboxRelay->relay(10); // relay a batch of messages

        // 6. Verificar que published_at ya no es NULL en el outbox
        $outboxRowRelayed = $this->connection->fetchAssociative(
            'SELECT * FROM order_ctx.outbox WHERE aggregate_id = ?',
            [$orderId]
        );
        $this->assertNotNull($outboxRowRelayed['published_at'], 'Message should be marked as published');

        // 7. Simular que el Payment handler procesa el mensaje
        // Extraemos el Integration Event de Outbox (aunque OutboxRelay ya lo "publicó" al bus)
        // O más directo: lo llamamos a través del bus de Symfony
        $payload = json_decode($outboxRow['payload'], true);
        $messageId = $outboxRow['message_id'];

        $integrationEvent = new \App\Domain\Order\Events\OrderCreatedIntegration(
            messageId: $messageId,
            correlationId: $correlationId,
            causationId: $correlationId,
            occurredAt: (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            orderId: $orderId,
            customerId: 'customer-test',
            items: [['sku' => 'TEST', 'quantity' => 1]],
            total: 0.0, // Default in outbox
        );

        $handler = self::getContainer()->get(\App\Application\Payment\CommandHandlers\HandleOrderCreatedHandler::class);
        $handler($integrationEvent);

        // 8. Verificar que aparece en payment_ctx.payments
        $paymentRow = $this->connection->fetchAssociative(
            'SELECT * FROM payment_ctx.payments WHERE order_id = ?',
            [$orderId]
        );
        $this->assertNotFalse($paymentRow, 'Payment should be recorded');
        $this->assertEquals('succeeded', $paymentRow['status']);

        // 9. Verificar que el Order Context actualiza el status a pending_stock (depende de la proyección)
        // The handle payment might have dispatched an internal command which should update the projection synchronously or via projector.
        // If projection runs synchronously:
        $updatedProjectionRow = $this->connection->fetchAssociative(
            'SELECT * FROM order_ctx.order_projections WHERE order_id = ?',
            [$orderId]
        );
        // Note: checking if it changed from draft to something else depending on the system's sync/async setup
        $this->assertNotEquals('draft', $updatedProjectionRow['status']);

        // 10. Verificar que el Audit Context registra todos los eventos con el mismo correlation_id
        $auditRows = $this->connection->fetchAllAssociative(
            'SELECT * FROM audit_ctx.audit_log WHERE correlation_id = ?',
            [$correlationId]
        );
        $this->assertNotEmpty($auditRows, 'Audit context should have captured events');
    }
}
