<?php

declare(strict_types=1);

namespace App\Delivery\Console\DeadLetter;

use App\Infrastructure\DeadLetter\DeadLetterStoreInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(name: 'dead-letter:retry', description: 'Reenvía un mensaje del Dead Letter Store al transport original')]
final class RetryCommand extends Command
{
    public function __construct(
        private readonly DeadLetterStoreInterface $store,
        private readonly MessageBusInterface $eventBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('message-id', InputArgument::REQUIRED, 'UUID del mensaje a reintentar');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $messageId = $input->getArgument('message-id');
        $entry = $this->store->findById($messageId);

        if ($entry === null) {
            $output->writeln('<error>Mensaje no encontrado.</error>');
            return Command::FAILURE;
        }

        $this->store->incrementAttempts($messageId);

        // Reconstruir el mensaje y reenviarlo
        // En una implementación real, se deserializaría el mensaje original
        // Aquí usamos OutboxIntegrationMessage como proxy genérico
        $message = new \App\Infrastructure\Outbox\OutboxIntegrationMessage(
            messageId: $entry->messageId,
            eventType: $entry->eventType,
            payload: $entry->payload,
            metadata: $entry->metadata,
            correlationId: $entry->correlationId ?? '',
            causationId: $entry->causationId ?? '',
        );

        try {
            $this->eventBus->dispatch($message);
            $this->store->remove($messageId);
            $output->writeln("<info>Mensaje {$messageId} reenviado exitosamente.</info>");
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln("<error>Error al reenviar: {$e->getMessage()}</error>");
            return Command::FAILURE;
        }
    }
}
