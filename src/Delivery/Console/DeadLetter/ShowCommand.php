<?php

declare(strict_types=1);

namespace App\Delivery\Console\DeadLetter;

use App\Infrastructure\DeadLetter\DeadLetterStoreInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'dead-letter:show', description: 'Muestra el detalle de un mensaje en el Dead Letter Store')]
final class ShowCommand extends Command
{
    public function __construct(private readonly DeadLetterStoreInterface $store)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('message-id', InputArgument::REQUIRED, 'UUID del mensaje');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $entry = $this->store->findById($input->getArgument('message-id'));

        if ($entry === null) {
            $output->writeln('<error>Mensaje no encontrado.</error>');
            return Command::FAILURE;
        }

        $output->writeln("<info>message_id:</info>      {$entry->messageId}");
        $output->writeln("<info>event_type:</info>      {$entry->eventType}");
        $output->writeln("<info>consumer_name:</info>   {$entry->consumerName}");
        $output->writeln("<info>correlation_id:</info>  {$entry->correlationId}");
        $output->writeln("<info>causation_id:</info>    {$entry->causationId}");
        $output->writeln("<info>attempts:</info>        {$entry->attempts}");
        $output->writeln("<info>failed_at:</info>       {$entry->failedAt->format('Y-m-d H:i:s')}");
        $output->writeln("<info>error_reason:</info>    {$entry->errorReason}");
        $output->writeln('');
        $output->writeln('<info>payload:</info>');
        $output->writeln(json_encode($entry->payload, JSON_PRETTY_PRINT));
        $output->writeln('');

        if ($entry->stackTrace) {
            $output->writeln('<info>stack_trace:</info>');
            $output->writeln($entry->stackTrace);
        }

        return Command::SUCCESS;
    }
}
