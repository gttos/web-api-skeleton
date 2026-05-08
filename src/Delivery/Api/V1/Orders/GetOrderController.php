<?php

declare(strict_types=1);

namespace App\Delivery\Api\V1\Orders;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/v1/orders/{orderId}', methods: ['GET'])]
final class GetOrderController extends AbstractController
{
    public function __construct(private readonly Connection $connection) {}

    public function __invoke(string $orderId): JsonResponse
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM order_ctx.order_projections WHERE order_id = ?',
            [$orderId]
        );

        if ($row === false) {
            return new JsonResponse(['error' => 'Order not found'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse([
            'order_id'    => $row['order_id'],
            'status'      => $row['status'],
            'customer_id' => $row['customer_id'],
            'items'       => json_decode($row['items'], true),
            'total'       => (float) $row['total'],
            'created_at'  => $row['created_at'],
            'updated_at'  => $row['updated_at'],
        ]);
    }
}
