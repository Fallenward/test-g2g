<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ledger_accounts', function (Blueprint $table) {
            $table->string('owner_key')->nullable();
        });

        $kept = [];

        foreach (DB::table('ledger_accounts')->orderBy('id')->get() as $row) {
            $ownerKey = $row->owner_type.':'.($row->owner_id ?? '0').':'.$row->code.':'.strtoupper((string) $row->currency);

            if (isset($kept[$ownerKey])) {
                DB::table('ledger_entries')
                    ->where('account_id', $row->id)
                    ->update(['account_id' => $kept[$ownerKey]]);

                DB::table('ledger_accounts')->where('id', $row->id)->delete();

                continue;
            }

            $kept[$ownerKey] = $row->id;

            DB::table('ledger_accounts')->where('id', $row->id)->update([
                'owner_key' => $ownerKey,
            ]);
        }

        Schema::table('ledger_accounts', function (Blueprint $table) {
            $table->unique('owner_key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ledger_accounts', function (Blueprint $table) {
            $table->dropUnique(['owner_key']);
            $table->dropColumn('owner_key');
        });
    }
};
