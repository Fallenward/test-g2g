<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('buyer_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('seller_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('listing_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_price_minor'); // snapshot
            $table->unsignedBigInteger('gross_minor');
            $table->unsignedBigInteger('fee_minor')->default(0);
            $table->unsignedBigInteger('net_minor')->default(0);
            $table->char('currency', 3);
            $table->string('status');
            $table->string('idempotency_key');
            $table->timestamps();

            // Critical: unique per buyer to prevent duplicate purchases
            $table->unique(['buyer_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
