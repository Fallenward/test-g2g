<?php

namespace App\Http\Controllers\Api;

use App\Domain\Actions\Lifecycle\CancelOrder;
use App\Domain\Actions\Lifecycle\CompleteOrder;
use App\Domain\Actions\Lifecycle\MarkDelivered;
use App\Domain\Actions\Lifecycle\OpenDispute;
use App\Domain\Actions\Lifecycle\RefundOrder;
use App\Domain\Actions\PurchaseOrder\PurchaseOrder;
use App\Domain\Actions\PurchaseOrder\PurchaseOrderData;
use App\Domain\Exceptions\ForbiddenActor;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(
        private readonly PurchaseOrder $purchase,
        private readonly MarkDelivered $markDelivered,
        private readonly CompleteOrder $complete,
        private readonly OpenDispute $dispute,
        private readonly RefundOrder $refund,
        private readonly CancelOrder $cancel,
    ) {}

    public function store(StoreOrderRequest $request): JsonResponse
    {
        $order = $this->purchase->execute(new PurchaseOrderData(
            buyerId: $request->user()->id,
            listingId: (int) $request->validated('listing_id'),
            quantity: (int) $request->validated('quantity'),
            idempotencyKey: $request->validated('idempotency_key'),
        ));

        return (new OrderResource($order))->response()->setStatusCode(201);
    }

    public function show(Order $order, Request $request): OrderResource
    {
        $userId = $request->user()->id;

        if ($order->buyer_id !== $userId && $order->seller_id !== $userId) {
            abort(403, 'Not a party to this order.');
        }

        return new OrderResource($order);
    }

    public function deliver(Order $order, Request $request): OrderResource
    {
        return new OrderResource(
            $this->markDelivered->execute($order->id, $request->user()->id)
        );
    }

    public function complete(Order $order, Request $request): OrderResource
    {
        $feeBps = (int) config('escrow.fee_bps', 500);

        return new OrderResource(
            $this->complete->execute($order->id, $request->user()->id, $feeBps)
        );
    }

    public function dispute(Order $order, Request $request): OrderResource
    {
        return new OrderResource(
            $this->dispute->execute($order->id, $request->user()->id)
        );
    }

    public function refund(Order $order, Request $request): OrderResource
    {
        return new OrderResource(
            $this->refund->execute($order->id, $request->user()->id)
        );
    }

    public function cancel(Order $order, Request $request): OrderResource
    {
        return new OrderResource(
            $this->cancel->execute($order->id, $request->user()->id)
        );
    }
}
