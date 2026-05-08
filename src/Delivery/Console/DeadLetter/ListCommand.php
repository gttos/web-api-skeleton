<?php

declare(strict_types=1);

namespace App\Delivery\Console\DeadLetter;

use App\Infrastructure\DeadLetter\DeadLetterStoreInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'dead-letter:list', description: 'Lista los mensajes en el Dead Letter Store')]
final class ListCommand extends Command
{
    public function __construct(private readonly DeadLetterStoreInterface $store)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('consumer', null, InputOption::VALUE_OPTIONAL, 'Filtrar por consumer_name')
            ->addOption('event-type', null, InputOption::VALUE_OPTIONAL, 'Filtrar por event_type');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $entries = $this->store->findAll(
            $input->getOption('consumer'),
            $input->getOption('event-type')
        );

        if (empty($entries)) {
            $output->writeln('<info>No hay mensajes en el Dead Letter Store.</info>');
            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['message_id', 'event_type', 'consumer_name', 'error_reason', 'correlation_id', 'attempts', 'failed_at']);

        foreach ($entries as $entry) {
            $table->addRow([
                substr($entry->messageId, 0, 8) . '...',
                $entry->eventType,
                $entry->consumerName,
                substr($entry->errorReason, 0, 50),
                $entry->correlationId ? substr($entry->correlationId, 0, 8) . '...' : '-',
                $entry->attempts,
                $entry->failedAt->format('Y-m-d H:i:s'),
            ]);
        }

        $table->render();
        $output->writeln(sprintf('<comment>Total: %d mensajes</comment>', count($entries)));

        return Command::SUCCESS;
    }
}
