<?php

declare(strict_types=1);

namespace App\Domain\Order\Model;

final class OrderItem
{
    public function __construct(
        public readonly string $sku,
        public readonly string $name,
        public readonly int $quantity,
        public readonly float $unitPrice,
    ) {}

    public function lineTotal(): float
    {
        return $this->quantity * $this->unitPrice;
    }

    public function toArray(): array
    {
        return [
            'sku'        => $this->sku,
            'name'       => $this->name,
            'quantity'   => $this->quantity,
            'unit_price' => $this->unitPrice,
            'line_total' => $this->lineTotal(),
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            sku: $data['sku'],
            name: $data['name'],
            quantity: (int) $data['quantity'],
            unitPrice: (float) $data['unit_price'],
        );
    }
}
