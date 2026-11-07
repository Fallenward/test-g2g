<?php

namespace App\Domain\Actions\PurchaseOrder;

final class PurchaseOrderData
{
    public function __construct(
        public readonly int $buyerId,
        public readonly int $listingId,
        public readonly int $quantity,
        public readonly string $idempotencyKey,
    ) {}
}
