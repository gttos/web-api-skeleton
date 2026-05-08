<?php

declare(strict_types=1);

namespace App\Delivery\Api\V1\Orders;

use App\Domain\Order\Commands\CreateOrder;
use Somnambulist\Components\Commands\CommandBus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/api/v1/orders', methods: ['POST'])]
final class CreateOrderController extends AbstractController
{
    public function __construct(private readonly CommandBus $commandBus) {}

    public function __invoke(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (empty($data['customer_id']) || empty($data['items'])) {
            return new JsonResponse(['error' => 'customer_id and items are required'], Response::HTTP_BAD_REQUEST);
        }

        $orderId       = Uuid::v4()->toRfc4122();
        $correlationId = $request->headers->get('X-Correlation-Id', Uuid::v4()->toRfc4122());

        $this->commandBus->execute(new CreateOrder(
            orderId: $orderId,
            customerId: $data['customer_id'],
            items: $data['items'],
            correlationId: $correlationId,
            currency: $data['currency'] ?? 'EUR',
        ));

        return new JsonResponse(
            ['order_id' => $orderId, 'correlation_id' => $correlationId],
            Response::HTTP_ACCEPTED
        );
    }
}
