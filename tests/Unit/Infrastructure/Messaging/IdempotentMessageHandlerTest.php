<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Messaging;

use App\Infrastructure\Messaging\IdempotentMessageHandler;
use App\Infrastructure\Messaging\ProcessedMessageStore;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class IdempotentMessageHandlerTest extends TestCase
{
    public function testHandleExecutesOnceForSameMessage(): void
    {
        $store = $this->createMock(ProcessedMessageStore::class);
        $logger = new NullLogger();

        $handler = new class($store, $logger) extends IdempotentMessageHandler {
            public int $callCount = 0;

            protected function getConsumerName(): string
            {
                return 'test_consumer';
            }

            protected function doHandle(object $message): void
            {
                $this->callCount++;
            }
        };

        $message = new class {
            public function getMessageId(): string
            {
                return 'msg-123';
            }
        };

        // First call: store returns false (not processed), so we should process and store
        $store->expects($this->exactly(2))
            ->method('hasBeenProcessed')
            ->willReturnOnConsecutiveCalls(false, true);

        $store->expects($this->once())
            ->method('markAsProcessed');

        // Execute first time
        $handler->handle($message);

        // Execute second time (idempotent, store says true)
        $handler->handle($message);

        // Assert our logic was only run once
        $this->assertEquals(1, $handler->callCount);
    }
}
