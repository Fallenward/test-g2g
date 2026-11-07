<?php

/**
 * Child process entry point for the MySQL race test.
 *
 * Invoked as:
 *   php tests/Support/race_purchase.php <buyerId> <listingId> <quantity> <idempotencyKey>
 *
 * Prints one line: "OK <orderId>" or "FAIL <ExceptionClass>: <message>".
 * Always exits 0.
 */

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$connection = getenv('RACE_DB_CONNECTION');
if ($connection) {
    config(['database.default' => $connection]);
    \Illuminate\Support\Facades\DB::purge();
    \Illuminate\Support\Facades\DB::reconnect();
}

[, $buyerId, $listingId, $quantity, $idempotencyKey] = $argv;

try {
    $order = app(\App\Domain\Actions\PurchaseOrder\PurchaseOrder::class)
        ->execute(new \App\Domain\Actions\PurchaseOrder\PurchaseOrderData(
            buyerId: (int) $buyerId,
            listingId: (int) $listingId,
            quantity: (int) $quantity,
            idempotencyKey: $idempotencyKey,
        ));

    fwrite(STDOUT, "OK {$order->id}\n");
} catch (\Throwable $e) {
    fwrite(STDOUT, "FAIL ".get_class($e).": ".$e->getMessage()."\n");
}

exit(0);
