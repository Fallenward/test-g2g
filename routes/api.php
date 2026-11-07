<?php

use App\Http\Controllers\Api\ListingController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\WalletController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/listings', [ListingController::class, 'store']);
    Route::get('/listings/{listing}', [ListingController::class, 'show']);

    Route::post('/orders', [OrderController::class, 'store']);
    Route::get('/orders/{order}', [OrderController::class, 'show']);
    Route::post('/orders/{order}/deliver', [OrderController::class, 'deliver']);
    Route::post('/orders/{order}/complete', [OrderController::class, 'complete']);
    Route::post('/orders/{order}/dispute', [OrderController::class, 'dispute']);
    Route::post('/orders/{order}/refund', [OrderController::class, 'refund']);
    Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel']);

    Route::get('/wallet', [WalletController::class, 'show']);
});
