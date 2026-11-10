<?php

namespace App\Domain\Actions\PurchaseOrder;

use App\Domain\Exceptions\InsufficientFunds;
use App\Domain\Exceptions\InsufficientStock;
use App\Domain\Ledger\LedgerContract;
use App\Domain\Ledger\LedgerLine;
use App\Domain\Ledger\Money;
use App\Domain\OrderStatus;
use App\Domain\Support\TransactionRetry;
use App\Models\Listing;
use App\Models\Order;
use Illuminate\Database\UniqueConstraintViolationException;

final class PurchaseOrder
{
    public function __construct(
        private readonly LedgerContract $ledger,
        private readonly TransactionRetry $transactions,
    ) {}

    public function execute(PurchaseOrderData $data): Order
    {
        try {
            return $this->transactions->run(function () use ($data) {
                /** @var Listing $listing */
                $listing = Listing::whereKey($data->listingId)
                    ->lockForUpdate()
                    ->firstOrFail();

                $existing = Order::where('buyer_id', $data->buyerId)
                    ->where('idempotency_key', $data->idempotencyKey)
                    ->first();

                if ($existing) {
                    return $existing;
                }

                if ($listing->quantity_available < $data->quantity) {
                    throw InsufficientStock::make(
                        $listing->id,
                        $data->quantity,
                        $listing->quantity_available,
                    );
                }

                $available = $this->ledger->account('user', $data->buyerId, 'available', $listing->currency);
                $escrow = $this->ledger->account('user', $data->buyerId, 'escrow', $listing->currency);

                $this->ledger->lockAccounts([$available, $escrow]);

                $gross = $data->quantity * $listing->unit_price_minor;

                $balance = $this->ledger->balance($available);

                if ($balance < $gross) {
                    throw InsufficientFunds::make($listing->currency, $gross, $balance);
                }

                $listing->decrement('quantity_available', $data->quantity);

                $order = new Order([
                    'buyer_id' => $data->buyerId,
                    'seller_id' => $listing->seller_id,
                    'listing_id' => $listing->id,
                    'quantity' => $data->quantity,
                    'unit_price_minor' => $listing->unit_price_minor,
                    'gross_minor' => $gross,
                    'fee_minor' => 0,
                    'net_minor' => 0,
                    'currency' => $listing->currency,
                    'status' => OrderStatus::Pending,
                    'idempotency_key' => $data->idempotencyKey,
                ]);
                $order->transitionTo(OrderStatus::EscrowLocked);
                $order->save();

                $this->ledger->post(
                    type: 'escrow_lock',
                    lines: [
                        LedgerLine::debit($escrow, Money::of($gross, $listing->currency)),
                        LedgerLine::credit($available, Money::of($gross, $listing->currency)),
                    ],
                    idempotencyKey: "escrow_lock:{$order->id}",
                    orderId: $order->id,
                );

                return $order;
            });
        } catch (UniqueConstraintViolationException $exception) {
            if (! $this->isOrderIdempotencyConflict($exception)) {
                throw $exception;
            }

            $existing = Order::query()
                ->where('buyer_id', $data->buyerId)
                ->where('idempotency_key', $data->idempotencyKey)
                ->first();

            if ($existing instanceof Order) {
                return $existing;
            }

            throw $exception;
        }
    }

    private function isOrderIdempotencyConflict(UniqueConstraintViolationException $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'orders_buyer_id_idempotency_key_unique')
            || str_contains($message, 'orders.buyer_id, orders.idempotency_key');
    }
}
