<?php

declare(strict_types=1);

namespace App\Delivery\Console;

use App\Infrastructure\Projection\ProjectionEngine;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'projection:rebuild', description: 'Reconstruye las proyecciones reprocesando eventos del Event Store')]
final class ProjectionRebuildCommand extends Command
{
    public function __construct(private readonly ProjectionEngine $engine)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'from-sequence',
            null,
            InputOption::VALUE_OPTIONAL,
            'Reconstruir solo desde este número de secuencia',
            null
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $fromSequence = $input->getOption('from-sequence') !== null
            ? (int) $input->getOption('from-sequence')
            : null;

        $output->writeln(sprintf(
            'Rebuilding projections%s...',
            $fromSequence !== null ? " from sequence {$fromSequence}" : ' (full rebuild)'
        ));

        $this->engine->rebuild($fromSequence);

        $output->writeln('Done.');

        return Command::SUCCESS;
    }
}
