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
        $this->dropOwnerKeyUniqueIfPresent();
        $this->collapseDuplicateAccounts();

        Schema::table('ledger_accounts', function (Blueprint $table) {
            $table->string('owner_key')->nullable(false)->change();
        });

        Schema::table('ledger_accounts', function (Blueprint $table) {
            $table->unique('owner_key');
        });

        $this->installTriggers();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->dropTriggers();

        $this->dropOwnerKeyUniqueIfPresent();

        Schema::table('ledger_accounts', function (Blueprint $table) {
            $table->string('owner_key')->nullable()->change();
        });

        Schema::table('ledger_accounts', function (Blueprint $table) {
            $table->unique('owner_key');
        });
    }

    private function collapseDuplicateAccounts(): void
    {
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
    }

    private function dropOwnerKeyUniqueIfPresent(): void
    {
        $hasUnique = collect(Schema::getIndexes('ledger_accounts'))
            ->contains(fn (array $index): bool => ($index['unique'] ?? false)
                && ($index['columns'] ?? []) === ['owner_key']);

        if (! $hasUnique) {
            return;
        }

        Schema::table('ledger_accounts', function (Blueprint $table) {
            $table->dropUnique(['owner_key']);
        });
    }

    private function installTriggers(): void
    {
        $this->dropTriggers();

        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->installMysqlTriggers();

            return;
        }

        if ($driver === 'pgsql') {
            $this->installPostgresTriggers();

            return;
        }

        $this->installSqliteTriggers();
    }

    private function installMysqlTriggers(): void
    {
        DB::unprepared("
            CREATE TRIGGER ledger_accounts_owner_key_on_insert
            BEFORE INSERT ON ledger_accounts
            FOR EACH ROW
            SET NEW.owner_key = CONCAT(NEW.owner_type, ':', IFNULL(NEW.owner_id, 0), ':', NEW.code, ':', UPPER(NEW.currency))
        ");

        DB::unprepared("
            CREATE TRIGGER ledger_accounts_owner_key_on_update
            BEFORE UPDATE ON ledger_accounts
            FOR EACH ROW
            SET NEW.owner_key = CONCAT(NEW.owner_type, ':', IFNULL(NEW.owner_id, 0), ':', NEW.code, ':', UPPER(NEW.currency))
        ");

        DB::unprepared("
            CREATE TRIGGER ledger_entries_reject_invalid_insert
            BEFORE INSERT ON ledger_entries
            FOR EACH ROW
            BEGIN
                IF NEW.amount_minor <= 0 OR NEW.type NOT IN ('debit', 'credit') THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid ledger entry.';
                END IF;
            END
        ");

        DB::unprepared("
            CREATE TRIGGER ledger_entries_no_update
            BEFORE UPDATE ON ledger_entries
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ledger entries are immutable.';
            END
        ");

        DB::unprepared("
            CREATE TRIGGER ledger_entries_no_delete
            BEFORE DELETE ON ledger_entries
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ledger entries are immutable.';
            END
        ");
    }

    private function installSqliteTriggers(): void
    {
        DB::unprepared("
            CREATE TRIGGER ledger_accounts_owner_key_on_insert
            BEFORE INSERT ON ledger_accounts
            FOR EACH ROW
            WHEN NEW.owner_key IS NULL
                OR NEW.owner_key != (
                    NEW.owner_type || ':' || IFNULL(NEW.owner_id, 0) || ':' || NEW.code || ':' || UPPER(NEW.currency)
                )
            BEGIN
                SELECT RAISE(ABORT, 'ledger account owner_key does not match its identity');
            END
        ");

        DB::unprepared("
            CREATE TRIGGER ledger_accounts_owner_key_on_update
            BEFORE UPDATE ON ledger_accounts
            FOR EACH ROW
            WHEN NEW.owner_key IS NULL
                OR NEW.owner_key != (
                    NEW.owner_type || ':' || IFNULL(NEW.owner_id, 0) || ':' || NEW.code || ':' || UPPER(NEW.currency)
                )
            BEGIN
                SELECT RAISE(ABORT, 'ledger account owner_key does not match its identity');
            END
        ");

        DB::unprepared("
            CREATE TRIGGER ledger_entries_reject_invalid_insert
            BEFORE INSERT ON ledger_entries
            FOR EACH ROW
            WHEN NEW.amount_minor <= 0 OR NEW.type NOT IN ('debit', 'credit')
            BEGIN
                SELECT RAISE(ABORT, 'Invalid ledger entry.');
            END
        ");

        DB::unprepared("
            CREATE TRIGGER ledger_entries_no_update
            BEFORE UPDATE ON ledger_entries
            BEGIN
                SELECT RAISE(ABORT, 'Ledger entries are immutable.');
            END
        ");

        DB::unprepared("
            CREATE TRIGGER ledger_entries_no_delete
            BEFORE DELETE ON ledger_entries
            BEGIN
                SELECT RAISE(ABORT, 'Ledger entries are immutable.');
            END
        ");
    }

    private function installPostgresTriggers(): void
    {
        DB::unprepared("
            CREATE OR REPLACE FUNCTION ledger_accounts_set_owner_key()
            RETURNS trigger AS $$
            BEGIN
                NEW.owner_key := NEW.owner_type || ':' || COALESCE(NEW.owner_id::text, '0') || ':' || NEW.code || ':' || UPPER(NEW.currency);
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        ");

        DB::unprepared('
            CREATE TRIGGER ledger_accounts_owner_key_on_insert
            BEFORE INSERT ON ledger_accounts
            FOR EACH ROW EXECUTE FUNCTION ledger_accounts_set_owner_key()
        ');

        DB::unprepared('
            CREATE TRIGGER ledger_accounts_owner_key_on_update
            BEFORE UPDATE ON ledger_accounts
            FOR EACH ROW EXECUTE FUNCTION ledger_accounts_set_owner_key()
        ');

        DB::unprepared("
            CREATE OR REPLACE FUNCTION ledger_entries_reject_invalid_insert()
            RETURNS trigger AS $$
            BEGIN
                IF NEW.amount_minor <= 0 OR NEW.type NOT IN ('debit', 'credit') THEN
                    RAISE EXCEPTION 'Invalid ledger entry.';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        ");

        DB::unprepared('
            CREATE TRIGGER ledger_entries_reject_invalid_insert
            BEFORE INSERT ON ledger_entries
            FOR EACH ROW EXECUTE FUNCTION ledger_entries_reject_invalid_insert()
        ');

        DB::unprepared("
            CREATE OR REPLACE FUNCTION ledger_entries_reject_mutation()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Ledger entries are immutable.';
            END;
            $$ LANGUAGE plpgsql
        ");

        DB::unprepared('
            CREATE TRIGGER ledger_entries_no_update
            BEFORE UPDATE ON ledger_entries
            FOR EACH ROW EXECUTE FUNCTION ledger_entries_reject_mutation()
        ');

        DB::unprepared('
            CREATE TRIGGER ledger_entries_no_delete
            BEFORE DELETE ON ledger_entries
            FOR EACH ROW EXECUTE FUNCTION ledger_entries_reject_mutation()
        ');
    }

    private function dropTriggers(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS ledger_accounts_owner_key_on_insert ON ledger_accounts');
            DB::unprepared('DROP TRIGGER IF EXISTS ledger_accounts_owner_key_on_update ON ledger_accounts');
            DB::unprepared('DROP TRIGGER IF EXISTS ledger_entries_reject_invalid_insert ON ledger_entries');
            DB::unprepared('DROP TRIGGER IF EXISTS ledger_entries_no_update ON ledger_entries');
            DB::unprepared('DROP TRIGGER IF EXISTS ledger_entries_no_delete ON ledger_entries');
            DB::unprepared('DROP FUNCTION IF EXISTS ledger_accounts_set_owner_key()');
            DB::unprepared('DROP FUNCTION IF EXISTS ledger_entries_reject_invalid_insert()');
            DB::unprepared('DROP FUNCTION IF EXISTS ledger_entries_reject_mutation()');

            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS ledger_accounts_owner_key_on_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS ledger_accounts_owner_key_on_update');
        DB::unprepared('DROP TRIGGER IF EXISTS ledger_entries_reject_invalid_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS ledger_entries_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS ledger_entries_no_delete');
    }
};
