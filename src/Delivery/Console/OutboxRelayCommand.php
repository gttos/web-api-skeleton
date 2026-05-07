<?php

declare(strict_types=1);

namespace App\Delivery\Console;

use App\Infrastructure\Outbox\OutboxRelay;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'outbox:relay', description: 'Publica mensajes pendientes del outbox en RabbitMQ')]
final class OutboxRelayCommand extends Command
{
    public function __construct(private readonly OutboxRelay $relay)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('once', null, InputOption::VALUE_NONE, 'Ejecutar una sola vez y salir (sin bucle)')
            ->addOption('batch-size', null, InputOption::VALUE_OPTIONAL, 'Mensajes por ciclo', 100)
            ->addOption('sleep', null, InputOption::VALUE_OPTIONAL, 'Segundos entre ciclos', 1);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $once      = $input->getOption('once');
        $batchSize = (int) $input->getOption('batch-size');
        $sleep     = (int) $input->getOption('sleep');

        do {
            $published = $this->relay->execute($batchSize);

            if ($published > 0) {
                $output->writeln(sprintf('[%s] Published %d messages', date('H:i:s'), $published));
            }

            if (!$once) {
                sleep($sleep);
            }
        } while (!$once);

        return Command::SUCCESS;
    }
}
