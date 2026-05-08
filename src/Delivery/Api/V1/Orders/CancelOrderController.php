<?php

declare(strict_types=1);

namespace App\Delivery\Api\V1\Orders;

use App\Domain\Order\Commands\CancelOrder;
use Somnambulist\Components\Commands\CommandBus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/api/v1/orders/{orderId}', methods: ['DELETE'])]
final class CancelOrderController extends AbstractController
{
    public function __construct(private readonly CommandBus $commandBus) {}

    public function __invoke(string $orderId, Request $request): JsonResponse
    {
        $data   = json_decode($request->getContent() ?: '{}', true);
        $reason = $data['reason'] ?? 'Cancelled by user';
        $correlationId = $request->headers->get('X-Correlation-Id', Uuid::v4()->toRfc4122());

        $this->commandBus->execute(new CancelOrder(
            orderId: $orderId,
            reason: $reason,
            correlationId: $correlationId,
        ));

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
