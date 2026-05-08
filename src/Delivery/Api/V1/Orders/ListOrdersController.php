<?php

declare(strict_types=1);

namespace App\Delivery\Api\V1\Orders;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/v1/orders', methods: ['GET'])]
final class ListOrdersController extends AbstractController
{
    public function __construct(private readonly Connection $connection) {}

    public function __invoke(Request $request): JsonResponse
    {
        $status  = $request->query->get('status');
        $page    = max(1, (int) $request->query->get('page', 1));
        $perPage = min(100, max(1, (int) $request->query->get('per_page', 20)));
        $offset  = ($page - 1) * $perPage;

        $sql    = 'SELECT * FROM order_ctx.order_projections WHERE 1=1';
        $params = [];

        if ($status !== null) {
            $sql .= ' AND status = ?';
            $params[] = $status;
        }

        $total = (int) $this->connection->fetchOne(
            str_replace('SELECT *', 'SELECT COUNT(*)', $sql),
            $params
        );

        $sql .= ' ORDER BY created_at DESC LIMIT ? OFFSET ?';
        $params[] = $perPage;
        $params[] = $offset;

        $rows = $this->connection->fetchAllAssociative($sql, $params);

        return new JsonResponse([
            'data' => array_map(fn(array $row) => [
                'order_id'    => $row['order_id'],
                'status'      => $row['status'],
                'customer_id' => $row['customer_id'],
                'total'       => (float) $row['total'],
                'created_at'  => $row['created_at'],
            ], $rows),
            'meta' => [
                'total'    => $total,
                'page'     => $page,
                'per_page' => $perPage,
                'pages'    => (int) ceil($total / $perPage),
            ],
        ]);
    }
}
