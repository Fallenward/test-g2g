# Escrow debug notes, v2

The first pass left two critical database holes open. A nullable `owner_key` does not make a platform account unique, and the ledger's immutability lived only in an Eloquent model event. Status changes and the purchase idempotency catch also sat outside the objects that are supposed to own those rules.

`php artisan test` after this pass: 68 tests passed.

## 1. Platform account identity was still not unique

Severity: critical. Layer: database.

`ledger_accounts` has a unique index on `(owner_type, owner_id, code, currency)`. Platform accounts (`revenue`, `opening`) store `owner_id` as `NULL`. In MySQL and SQLite a unique index treats each `NULL` as distinct, so two rows with the same platform code and currency were legal.

The follow-up column `owner_key` was also nullable, and its unique index has the same hole. Two inserts with `owner_key IS NULL` both succeeded on `escrow_test` before this fix. Fees and opening balances could be split across those rows. `Ledger::balance()` then reads only one of them.

`LedgerAccount::creating` hid this from the test suite, because `Model::create()` filled `owner_key` before insert. `tests/Unit/Ledger/LedgerTest.php` only covered that path. `DB::table()->insert()` never ran the hook.

Fix, in `database/migrations/2026_11_10_113528_enforce_ledger_invariants.php`:

- Duplicate logical accounts are merged, and their entries are repointed, before the new constraint is added.
- `owner_key` is `NOT NULL` and unique. A second `platform / revenue / EUR` insert now fails with duplicate key `platform:0:revenue:EUR`.
- MySQL and PostgreSQL overwrite `owner_key` in `BEFORE INSERT` and `BEFORE UPDATE` triggers, using `0` when `owner_id` is null. A raw insert cannot pick a different key.
- SQLite cannot assign `NEW` columns from a trigger, so the trigger aborts when `owner_key` is missing or does not match that same string. `LedgerAccount` sets the key in a `saving` hook so the application path still inserts.

`Ledger::account()` reloads the row when a concurrent insert hits that unique key, instead of failing the purchase.

## 2. Journal lines were not protected by the database

Severity: critical. Layer: database.

`lockAccounts()` locks `ledger_accounts`. The balance is the sum of `ledger_entries`. An insert into `ledger_entries` does not wait on the account-row lock, so the lock only serializes writers that remember to take it. `Ledger::balance()` also used two separate `SUM` queries.

`Ledger::post()` inserted the journal and then each line as its own statement, and it did not open a transaction. A failure on the second line, outside `TransactionRetry`, left a one-sided journal.

Immutability was a `updating` / `deleting` hook on `LedgerEntry`. Those events do not run for `LedgerEntry::query()->update()` or for SQL. `UPDATE ledger_entries` succeeded. The table had no check that `amount_minor` is positive or that `type` is `debit` or `credit`. A per-row check cannot prove that a journal balances, because the first line is inserted before the second. That rule stays in `Ledger::assertBalanced()`, and the write has to be atomic so a partial journal cannot commit.

Fix:

- `Ledger::post()` locks every account on the journal, in primary-key order, inside `DB::transaction()`, then inserts the journal and its lines. Callers that already hold those locks (purchase, settlement, refund) keep the same lock order.
- `Ledger::balance()` is one aggregate: debits and credits from a single `SUM`.
- Triggers reject updates and deletes of `ledger_entries` (`Ledger entries are immutable.`) and reject a non-positive amount or an unknown type on insert.

The model hooks are still there. `entry->update()` still throws `RuntimeException` before SQL. The trigger is what stops a query-builder or SQL write.

## 3. Design patterns

### State machine was not the gate for status

`OrderStateMachine` was a static map. `PurchaseOrder` wrote `escrow_locked` on create and never asked the map. Lifecycle actions assigned `$order->status` themselves. Nothing stopped `Order::create(['status' => 'completed'])`.

`Order::transitionTo()` is now the only status change the actions use. A `saving` hook runs the same rule, including for a new row, which starts from `pending`. `pending -> completed` is rejected before the insert. Purchase builds the order as `pending`, then transitions to `escrow_locked`, which is the allowed edge.

### Transaction retry was a static call

`TransactionRetry::run()` hid the dependency. The actions now take `TransactionRetry` in the constructor and call `$this->transactions->run()`. The container wires it. Deadlock handling is unchanged: SQLSTATE `40001`, MySQL `1213`, SQLite `database is locked`, and PostgreSQL `deadlock detected`, up to three attempts.

### Every unique-key error was treated as a repeated purchase

`PurchaseOrder` caught `UniqueConstraintViolationException` and, if that buyer already had the idempotency key, returned the old order. The same catch also covered `owner_key` and `ledger_journals.idempotency_key`.

The catch now matches only the orders unique key (`orders_buyer_id_idempotency_key_unique` on MySQL, `orders.buyer_id, orders.idempotency_key` on SQLite). Any other unique violation is rethrown. Account races are handled inside `Ledger::account()`.

## Tests that cover this pass

- `test_database_rejects_a_second_platform_account_with_the_same_identity` inserts the platform row with the query builder, not the model.
- `test_query_builder_cannot_update_a_ledger_entry` uses `LedgerEntry::query()->update()`, which skips model events.
- `test_database_rejects_a_non_positive_ledger_entry` inserts `amount_minor = 0`.
- `test_new_order_cannot_be_saved_as_completed` saves a new order straight into `completed`.
