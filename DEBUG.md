# Escrow debug notes

The default test suite was already green (63 tests). The bugs were on paths that suite does not cover. Each one is listed with the layer it lived in and how serious it was. The last section checks the project against the senior-backend evaluation brief (`ارزیابی.pdf`).

Severity scale: **High** can lose or split money, or can hide the concurrency test the review scores. **Medium** breaks a required contract without losing money, because the transaction already rolled back. **Low** is a wrong setting that the framework default happens to cover.

## Issues

| Issue | Layer | Severity | Result |
| --- | --- | --- | --- |
| `B_PORT` instead of `DB_PORT` | Configuration | Low | Fixed in `.env` and `.env.example` |
| `purge()` called on the connection | Tests | High | Fixed; race database moved to `escrow_race` |
| Platform accounts with a null owner were not unique | Persistence (ledger) | High | Fixed with `owner_key` |
| Same idempotency key under concurrency returned 500 | Domain (purchase action) | Medium | Fixed; original order is returned after rollback |

### Configuration — Low

`.env` and `.env.example` defined `B_PORT`, so Laravel never read `DB_PORT`. The MySQL connection still used the framework default of port 3306, which matched this machine, so the app could connect. Any other port would have failed with no obvious cause. Both files now set `DB_PORT=3306`.

### Tests — High

The race test is what the evaluation uses to prove the last unit cannot be oversold. If the race database did not exist, the test created it and then called `purge()` on the connection object. That method is not on the connection. The error was swallowed and the test skipped, so a reviewer would see a pass that never ran the concurrency case.

`DB::purge()` is called on the database manager now. The race database is `escrow_race`, not the application database `escrow_test`, so `migrate:fresh` inside the test does not wipe the app data.

### Persistence (ledger) — High

Platform accounts (`revenue`, and the opening offset) use a null `owner_id`. A unique index on MySQL and SQLite allows many `NULL`s, so two rows could exist for the same platform account, code, and currency. Fees and opening journals could be split across those rows, and a later balance read would see only one of them.

`ledger_accounts.owner_key` stores `owner_type`, owner id (`0` when the owner is null), code, and currency. The unique index is on that column. The migration was applied on the local MySQL database. A second insert of the same platform account is rejected.

### Domain (purchase action) — Medium

Purchase holds the listing lock, so two requests for the same listing are ordered. Two requests for different listings that reuse the same buyer idempotency key do not share that lock. Both could pass the "does this key exist?" read and one insert would hit the unique key. The database rolled the failed transaction back, so stock and money were not doubled, but the client got a 500 instead of the original order.

The unique-constraint error is caught after the transaction has rolled back. If that buyer already has the key, the original order is returned and stock is not decremented again.

## Evaluation brief

The brief asks for an escrow purchase flow with no UI, a double-entry ledger, no writable balance on the user, pessimistic locking and deadlock handling, a state machine, action-layer architecture, a concurrency test, an atomic-rollback test, a README that explains those decisions, and a green `php artisan test`.

| Requirement | Where it lives | Status |
| --- | --- | --- |
| No `balance` column on `users`. Available and escrow are separate accounts | `users` migration, `ledger_accounts` (`available`, `escrow`, `revenue`) | Met |
| Immutable debit/credit lines. A journal balances before it is stored. No float fee | `Ledger`, `LedgerEntry`, `Money`, `CompleteOrder` (`intdiv`) | Met |
| Last unit cannot be oversold. Stock is checked after `lockForUpdate`, not with an unlocked `if` | `PurchaseOrder`, `PurchaseRaceMysqlTest` | Met |
| Deadlock `40001` / MySQL `1213` retries the whole transaction up to 3 times | `TransactionRetry` | Met |
| Illegal status changes stop before a write | `OrderStateMachine` and the lifecycle actions | Met |
| Use cases are actions with a DTO and a state machine. Controllers do not contain stock or balance math | `app/Domain`, `app/Http/Controllers/Api` | Met |
| Concurrent requests against the last unit, on MySQL, one PHP process per buyer | `tests/Feature/PurchaseRaceMysqlTest.php` | Met |
| A failed step during purchase rolls stock, orders, and the journal back | `tests/Feature/PurchaseAtomicRollbackTest.php` | Met |
| README explains the ledger and the locking | `README.md` | Met |
| `php artisan test` passes without MySQL. The race test skips when no race database is reachable | `phpunit.xml` (SQLite memory) and the race test | Met |

No UI was added. The HTTP surface is the JSON API under `/api` with Sanctum.

After the fixes, the default suite and the MySQL race test both passed.
