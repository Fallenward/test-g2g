<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreListingRequest;
use App\Http\Resources\ListingResource;
use App\Models\Listing;
use Illuminate\Http\JsonResponse;

class ListingController extends Controller
{
    public function store(StoreListingRequest $request): JsonResponse
    {
        $listing = Listing::create([
            'seller_id'          => $request->user()->id,
            'title'              => $request->validated('title'),
            'unit_price_minor'   => $request->validated('unit_price_minor'),
            'currency'           => strtoupper($request->validated('currency')),
            'quantity_available' => $request->validated('quantity'),
            'status'             => 'active',
        ]);

        return (new ListingResource($listing))->response()->setStatusCode(201);
    }

    public function show(Listing $listing): ListingResource
    {
        return new ListingResource($listing);
    }
}
