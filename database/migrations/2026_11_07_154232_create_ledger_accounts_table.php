<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
 Schema::create('ledger_accounts', function (Blueprint $table) {
    $table->id();
    $table->foreignId('owner_id')->nullable()->constrained('users')->restrictOnDelete();
    $table->string('owner_type')->default('user');
    $table->string('code');
    $table->char('currency', 3);
    $table->timestamps();

    $table->unique(['owner_type', 'owner_id', 'code', 'currency']);
    $table->index(['code', 'currency']);
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ledger_accounts');
    }
};
