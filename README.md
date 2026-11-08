# Escrow marketplace

A seller lists a fixed quantity of an in-game item. Buyers place orders through a JSON API. The purchase locks the buyer's funds in escrow. Later the order is delivered and completed (seller is paid, platform fee deducted), disputed and refunded, or cancelled. There is no UI. The actor on every route is the Sanctum user on the token.

## Business flow

1. A seller creates a listing: `quantity`, `unit_price_minor`, and a 3-letter `currency`.
2. A buyer posts an order with `listing_id`, `quantity`, and an `idempotency_key`. One database transaction locks the listing, rejects the order when stock or available funds are short, decrements quantity, inserts the order as `escrow_locked`, and posts the escrow journal.
3. The seller marks the order `delivered`. The buyer then completes it. Completion is the only step that charges the platform fee and pays the seller.
4. The buyer can open a dispute from `escrow_locked`. The seller refunds a disputed order, or the buyer cancels a `pending` or `escrow_locked` order. A cancel or refund after funds were locked returns those funds to the buyer's available balance and puts the quantity back on the listing.

The same idempotency key for the same buyer returns the original order and does not move money or stock a second time. A unique constraint on `(buyer_id, idempotency_key)` is the backstop when two requests race. If that constraint fires, the transaction rolls back and the original order is returned.

Allowed status changes:

```
Pending ──> Escrow_Locked ──> Delivered ──> Completed
                │
                └──> Disputed ──> Refunded
                          └──> Cancelled
Escrow_Locked ──> Cancelled
Pending ──> Cancelled
```

Any other change is rejected before a write. Orders, stock, and the ledger stay as they were.

## Why there is no balance column

`users` has no `balance` column. Spendable money and money held in escrow are two ledger accounts per user and currency: `available` and `escrow`. The platform has a `revenue` account. A balance is the sum of posted entries, not a number that code increments.

A journal is one business event (`escrow_lock`, `settlement`, `refund`, or an opening fund). Its lines are immutable: the `LedgerEntry` model rejects update and delete, and the table has no `updated_at`. Before insert, the ledger sums debits and credits. They must be equal, and both must be greater than zero. A line with a mixed currency or a non-positive amount is rejected. Amounts are integers in minor units. Nothing in the money path uses floats.

Account balance is `sum(debits) - sum(credits)`. A debit increases that account and a credit decreases it. Under that rule:

- Escrow lock credits `available` and debits `escrow`. Spendable funds fall, blocked funds rise, by the same gross amount.
- Settlement credits buyer `escrow` for the gross, debits seller `available` for the net, and debits platform `revenue` for the fee. When the fee is zero the revenue line is omitted, so the journal has two lines.
- Refund or cancel credits buyer `escrow` and debits buyer `available`, which undoes the lock, and restores `quantity_available`.

## Lock order and deadlocks

A purchase locks the listing row with `lockForUpdate()`, then locks the buyer's ledger accounts by primary key ascending, then reads the available balance. The stock check and the funds check both happen after those locks, inside the same transaction. A failure at any later step, including the journal post, rolls the quantity change, the order row, and the journal back.

Lifecycle actions that move money lock the order, then the listing, then the ledger accounts in primary-key order. The state machine and the actor check (buyer versus seller) run before the write.

If MySQL reports deadlock `1213` or SQLSTATE `40001`, the whole transaction is retried up to 3 times. The same retry treats SQLite `database is locked` and PostgreSQL `deadlock detected` as a deadlock.

## Fee

The fee is basis points in `config/escrow.php`. The default is `500`, which is 5 percent. It is applied only on settlement:

```
fee = intdiv(gross * fee_bps, 10000)
net = gross - fee
```

Worked example: 2 units at 2,000 minor units is a gross of 4,000. At 500 bps the fee is `intdiv(4000 * 500, 10000) = 200`. The seller receives 3,800. The buyer's escrow goes to 0.

## API

All routes are under `/api` and require `auth:sanctum`.

| Method | Path | Actor | Effect |
| --- | --- | --- | --- |
| POST | `/api/listings` | seller | Create a listing |
| GET | `/api/listings/{listing}` | any authenticated user | Read the listing, including `quantity_available` |
| POST | `/api/orders` | buyer | Lock stock and escrow. Body: `listing_id`, `quantity`, `idempotency_key` |
| POST | `/api/orders/{order}/deliver` | seller | `escrow_locked` to `delivered` |
| POST | `/api/orders/{order}/complete` | buyer | `delivered` to `completed`, posts settlement |
| POST | `/api/orders/{order}/dispute` | buyer | `escrow_locked` to `disputed` |
| POST | `/api/orders/{order}/refund` | seller | `disputed` to `refunded`, posts the refund |
| POST | `/api/orders/{order}/cancel` | buyer | `pending` or `escrow_locked` to `cancelled` |
| GET | `/api/orders/{order}` | buyer or seller | Order and status |
| GET | `/api/wallet` | current user | Derived `available` and `escrow` |

Validation failures are 422. Not enough stock, not enough funds, and an illegal transition are 409. The wrong actor is 403.

Controllers validate input, build a DTO, call one domain action, and return an API resource. Stock checks and balance math stay in `app/Domain`.

## Tests

Default suite, SQLite in memory:

```bash
php artisan test
```

That command must pass without MySQL. It covers the ledger, the state machine, purchase, settlement, refund, cancel, atomic rollback of a purchase whose journal post throws, and the HTTP status and JSON shape of each route.

### MySQL race test

SQLite serializes writers, so it cannot prove that the last unit is sold only once. `tests/Feature/PurchaseRaceMysqlTest.php` spawns one PHP process per buyer with Symfony Process. The processes share one MySQL (or PostgreSQL) database. Exactly one order reaches `escrow_locked`, quantity ends at 0, exactly one escrow journal exists, and no wallet balance goes negative.

Windows has no `pcntl`, so the buyers are separate PHP processes rather than forks.

The test runs when `RACE_DB_CONNECTION` is set, or when `mysql_race` / `pgsql_race` in `config/database.php` can connect. If neither is available it is skipped, and `php artisan test` still passes.

```bash
# .env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=escrow_test
DB_USERNAME=root
DB_PASSWORD=
DB_RACE_DATABASE=escrow_race
```

Create the race database once if the MySQL user cannot create databases:

```sql
CREATE DATABASE escrow_race CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Then:

```bash
php artisan test --filter=PurchaseRaceMysqlTest
```

`escrow_race` is separate from the application database. The race test runs `migrate:fresh` on the race connection only.
