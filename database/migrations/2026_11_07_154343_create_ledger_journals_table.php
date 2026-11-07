<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_journals', function (Blueprint $table) {
            $table->id();
            $table->string('type'); // escrow_lock, settlement, refund
            $table->foreignId('order_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('idempotency_key')->unique();
            $table->timestamps();

            $table->index(['type', 'order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_journals');
    }
};
