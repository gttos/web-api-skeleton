<?php

declare(strict_types=1);

namespace App\Delivery\Console;

use App\Domain\Order\Commands\CreateOrder;
use App\Infrastructure\ErrorInjection\ErrorFlag;
use App\Infrastructure\ErrorInjection\ErrorFlagStoreInterface;
use Faker\Factory;
use Somnambulist\Components\Commands\CommandBus;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Uid\Uuid;

#[AsCommand(name: 'seed:orders', description: 'Genera pedidos de prueba en volumen')]
final class SeedOrdersCommand extends Command
{
    public function __construct(
        private readonly CommandBus $commandBus,
        private readonly ErrorFlagStoreInterface $flagStore,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('count', 'c', InputOption::VALUE_OPTIONAL, 'Número de pedidos a crear', 100)
            ->addOption('error-rate', 'r', InputOption::VALUE_OPTIONAL, 'Porcentaje de pedidos con error (0-100)', 0)
            ->addOption('with-duplicates', 'd', InputOption::VALUE_NONE, 'Reenviar ~10% de mensajes como duplicados');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $count         = (int) $input->getOption('count');
        $errorRate     = (int) $input->getOption('error-rate');
        $withDuplicates = $input->getOption('with-duplicates');
        $faker         = Factory::create();

        $output->writeln(sprintf(
            'Generando <info>%d</info> pedidos (error-rate: %d%%, duplicates: %s)...',
            $count,
            $errorRate,
            $withDuplicates ? 'sí' : 'no'
        ));

        $createdOrders = [];

        for ($i = 1; $i <= $count; $i++) {
            $orderId       = Uuid::v4()->toRfc4122();
            $correlationId = Uuid::v4()->toRfc4122();

            // Inyectar error si corresponde
            if ($errorRate > 0 && rand(1, 100) <= $errorRate) {
                $errorType = $this->randomErrorType();
                $this->flagStore->setFlag($correlationId, $errorType, $this->errorConfig($errorType));
                $output->writeln(sprintf(
                    '  [%d/%d] <comment>ERROR(%s)</comment> Order %s (correlation: %s)',
                    $i, $count, $errorType, $orderId, $correlationId
                ));
            } else {
                $output->writeln(sprintf(
                    '  [%d/%d] Order %s (correlation: %s)',
                    $i, $count, $orderId, $correlationId
                ));
            }

            $command = new CreateOrder(
                orderId: $orderId,
                customerId: Uuid::v4()->toRfc4122(),
                items: $this->randomItems($faker),
                correlationId: $correlationId,
                currency: $faker->randomElement(['EUR', 'USD', 'GBP']),
            );

            $this->commandBus->execute($command);
            $createdOrders[] = ['orderId' => $orderId, 'correlationId' => $correlationId];
        }

        // Enviar duplicados (~10%)
        if ($withDuplicates && !empty($createdOrders)) {
            $duplicateCount = max(1, (int) round(count($createdOrders) * 0.1));
            $output->writeln(sprintf('Enviando <comment>%d</comment> mensajes duplicados...', $duplicateCount));

            $selected = array_rand($createdOrders, min($duplicateCount, count($createdOrders)));
            if (!is_array($selected)) {
                $selected = [$selected];
            }

            foreach ($selected as $idx) {
                $original = $createdOrders[$idx];
                // Reenviar el mismo comando con el mismo correlationId (simula duplicado)
                $duplicate = new CreateOrder(
                    orderId: Uuid::v4()->toRfc4122(), // Nuevo orderId para no violar PK
                    customerId: Uuid::v4()->toRfc4122(),
                    items: $this->randomItems($faker),
                    correlationId: $original['correlationId'], // Mismo correlationId
                );
                $this->commandBus->execute($duplicate);
                $output->writeln(sprintf(
                    '  <comment>[DUP]</comment> Duplicate for correlation: %s',
                    $original['correlationId']
                ));
            }
        }

        $output->writeln(sprintf('<info>Done. %d pedidos creados.</info>', $count));

        return Command::SUCCESS;
    }

    private function randomItems(\Faker\Generator $faker): array
    {
        $count = rand(1, 5);
        $items = [];
        for ($i = 0; $i < $count; $i++) {
            $items[] = [
                'sku'        => strtoupper($faker->bothify('??###')),
                'name'       => $faker->words(3, true),
                'quantity'   => rand(1, 10),
                'unit_price' => round($faker->randomFloat(2, 5, 500), 2),
            ];
        }
        return $items;
    }

    private function randomErrorType(): string
    {
        return [
            ErrorFlag::TEMP_FAILURE,
            ErrorFlag::PERM_FAILURE,
            ErrorFlag::INVALID_PAYLOAD,
            ErrorFlag::SLOW_CONSUMER,
        ][array_rand([
            ErrorFlag::TEMP_FAILURE,
            ErrorFlag::PERM_FAILURE,
            ErrorFlag::INVALID_PAYLOAD,
            ErrorFlag::SLOW_CONSUMER,
        ])];
    }

    private function errorConfig(string $errorType): array
    {
        return match ($errorType) {
            ErrorFlag::TEMP_FAILURE    => ['max_failures' => rand(1, 2)],
            ErrorFlag::SLOW_CONSUMER   => ['delay_ms' => rand(1000, 5000)],
            default                    => [],
        };
    }
}
