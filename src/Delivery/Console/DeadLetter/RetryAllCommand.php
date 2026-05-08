<?php

declare(strict_types=1);

namespace App\Delivery\Console\DeadLetter;

use App\Infrastructure\DeadLetter\DeadLetterStoreInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'dead-letter:retry-all', description: 'Reenvía todos los mensajes del Dead Letter Store')]
final class RetryAllCommand extends Command
{
    public function __construct(
        private readonly DeadLetterStoreInterface $store,
        private readonly RetryCommand $retryCommand,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $entries = $this->store->findAll();

        if (empty($entries)) {
            $output->writeln('<info>No hay mensajes para reintentar.</info>');
            return Command::SUCCESS;
        }

        $output->writeln(sprintf('Reintentando %d mensajes...', count($entries)));

        foreach ($entries as $entry) {
            $output->write("  {$entry->messageId}: ");
            // Delegar al RetryCommand individual
            $result = $this->retryCommand->run(
                new \Symfony\Component\Console\Input\ArrayInput(['message-id' => $entry->messageId]),
                $output
            );
            if ($result !== Command::SUCCESS) {
                $output->writeln('<error>FAILED</error>');
            }
        }

        return Command::SUCCESS;
    }
}
