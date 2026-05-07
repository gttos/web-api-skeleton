<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

abstract class IdempotentMessageHandler
{
    public function __construct(
        protected readonly ProcessedMessageStore $processedStore,
        protected readonly Connection $connection,
        protected readonly LoggerInterface $logger,
    ) {}

    protected function processIdempotently(object $message): void
    {
        $messageId = $this->extractMessageId($message);
        $consumerName = static::consumerName();
        $schema = static::schema();

        if ($this->processedStore->wasProcessed($messageId, $consumerName, $schema)) {
            $this->logger->info('Duplicate message ignored', [
                'message_id'    => $messageId,
                'consumer_name' => $consumerName,
            ]);
            return;
        }

        $this->connection->transactional(function () use ($message, $messageId, $consumerName, $schema): void {
            $this->handle($message);
            $this->processedStore->markProcessed(
                messageId: $messageId,
                consumerName: $consumerName,
                schema: $schema,
                correlationId: $this->extractCorrelationId($message),
            );
        });
    }

    abstract protected function handle(object $message): void;
    abstract public static function consumerName(): string;
    abstract public static function schema(): string;

    protected function extractMessageId(object $message): string
    {
        if (method_exists($message, 'getMessageId')) {
            return $message->getMessageId();
        }
        if (property_exists($message, 'messageId')) {
            return $message->messageId;
        }
        throw new \LogicException('Message must have a messageId property or getMessageId() method');
    }

    protected function extractCorrelationId(object $message): ?string
    {
        if (property_exists($message, 'correlationId')) {
            return $message->correlationId;
        }
        return null;
    }
}
