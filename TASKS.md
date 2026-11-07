# Escrow marketplace — implementation tasks

Senior backend challenge: a G2G-style in-game item exchange. A seller lists a fixed quantity. Several buyers place orders at the same time through a JSON API. The system locks the buyer’s funds in escrow, then settles with the seller (platform fee deducted) or returns the funds. No UI.

Stack already in this repo: Laravel 13, PHP 8.3, PHPUnit 12, SQLite in-memory for the default test suite. Do not add a `balance` column to `users`.

## Money rules

- Store every amount as an integer in minor units (`amount_minor`). No floats.
- One journal is one business event. Its entries are immutable. Sum of debits equals sum of credits, and both are greater than zero.
- Each user has two ledger accounts per currency: `available` and `escrow`. The platform has a `revenue` account. Balances are the sum of posted entries, never a writable number on the user.
- Lock ledger account rows with `lockForUpdate()` before reading a balance that a write depends on.
- Platform fee is basis points in config (default `500` = 5%), applied only on settlement. `fee = intdiv(gross * fee_bps, 10000)`. Seller receives `gross - fee`.

## Order states

Allowed transitions only:

```
Pending ──> Escrow_Locked ──> Delivered ──> Completed
                │
                └──> Disputed ──> Refunded
                          └──> Cancelled
Escrow_Locked ──> Cancelled
Pending ──> Cancelled
```

`Completed` settles: debit buyer escrow, credit seller available (net), credit platform revenue (fee).
`Refunded` and `Cancelled` after funds were locked: debit buyer escrow, credit buyer available, restore listing quantity.
Any other transition is rejected before a write.

Lock order inside every transaction, always: listing row, then ledger accounts by primary key ascending. On deadlock (`40001` / MySQL `1213`), retry the whole transaction up to 3 times.

## API (JSON only)

Authenticate with Sanctum. Identify the actor from the token.

| Method | Path | Actor | Effect |
| --- | --- | --- | --- |
| POST | `/api/listings` | seller | Create listing (`quantity`, `unit_price_minor`, `currency`) |
| GET | `/api/listings/{listing}` | any auth user | Read listing, including `quantity_available` |
| POST | `/api/orders` | buyer | Body: `listing_id`, `quantity`, `idempotency_key`. Locks stock and posts escrow journal. Status becomes `escrow_locked`. Same key returns the original order and does not move money twice. |
| POST | `/api/orders/{order}/deliver` | seller | `escrow_locked` → `delivered` |
| POST | `/api/orders/{order}/complete` | buyer | `delivered` → `completed` and posts settlement |
| POST | `/api/orders/{order}/dispute` | buyer | `escrow_locked` → `disputed` |
| POST | `/api/orders/{order}/refund` | seller | `disputed` → `refunded` and posts refund |
| POST | `/api/orders/{order}/cancel` | buyer | `pending` or `escrow_locked` → `cancelled` |
| GET | `/api/orders/{order}` | buyer or seller | Order plus status |
| GET | `/api/wallet` | current user | Derived `available` and `escrow` balances |

Purchase is one database transaction: lock listing, reject when `quantity_available < quantity`, lock the buyer’s accounts, reject when available funds are short, decrement quantity, insert the order, post the journal. A failure at any step rolls all of it back.

Put use cases in `app/Domain` actions. Controllers validate input, build a DTO, call one action, and return an API resource.

## Tasks

### 1. Schema

- Migration for `listings`: seller, title, `unit_price_minor`, currency, `quantity_available` (unsigned), status.
- Migration for `orders`: buyer, seller, listing, quantity, snapshotted unit price, `gross_minor`, `fee_minor`, `net_minor`, currency, status, unique `(buyer_id, idempotency_key)`.
- Migration for `ledger_accounts`: owner (user or platform), `code` (`available`, `escrow`, `revenue`), currency. Unique on owner + code + currency.
- Migration for `ledger_journals`: type (`escrow_lock`, `settlement`, `refund`), reference to the order, unique idempotency key.
- Migration for `ledger_entries`: journal, account, `debit` or `credit`, `amount_minor`. No `updated_at`. Model `boot` rejects update and delete.
- `config/escrow.php` for currency default and `fee_bps`.
- Factories for users, listings, and funded wallets (funding goes through a balanced opening journal, not a balance column).

### 2. Ledger

- `Money` value object: currency + minor units, add/subtract, reject negatives and mixed currencies.
- `Ledger` service: open or fetch accounts, post a journal from a list of lines, assert the journal balances, return derived balances.
- Domain exceptions: `InsufficientFunds`, `UnbalancedJournal`, `AccountNotFound`.
- Unit tests: balanced post, unbalanced post rejected, derived available and escrow, entry mutation rejected.

### 3. State machine

- `OrderStatus` enum.
- `OrderStateMachine::assertCanTransition(from, to)` with the map above.
- Transition happens only inside the action that also posts money when money must move. A rejected transition leaves orders, stock, and the ledger unchanged.

### 4. Purchase

- `PurchaseOrder` action with a DTO (`buyerId`, `listingId`, `quantity`, `idempotencyKey`).
- Transaction, `lockForUpdate` on the listing, stock check, account locks in id order, funds check, quantity decrement, order row, escrow journal (debit available, credit escrow).
- Idempotency: if this buyer already has that key, return the existing order and post nothing.
- Deadlock retry around the transaction.
- Exceptions: `InsufficientStock`, `InsufficientFunds`. Both abort the transaction.

### 5. Lifecycle

- `MarkDelivered`, `CompleteOrder`, `OpenDispute`, `RefundOrder`, `CancelOrder`.
- `CompleteOrder` computes the fee with integer division, posts the three-line settlement (two lines when the fee is zero), and stores `fee_minor` / `net_minor` on the order.
- `RefundOrder` and a cancel that already locked funds post a two-line reversal and restore `quantity_available` under the same listing lock.
- Each action checks the actor (buyer vs seller) and the state machine before writing.

### 6. HTTP

- `composer require laravel/sanctum` and the personal-access-token migration.
- Register `routes/api.php` from `bootstrap/app.php`.
- Form Requests for listing and purchase payloads (`quantity` min 1, price min 1, currency size 3, idempotency key required).
- API resources for listing, order, and wallet.
- Controllers stay thin. Map domain exceptions to 409 (stock, funds, illegal transition) and 403 (wrong actor). Validation stays 422.
- Feature coverage for each route’s success status and JSON shape.

### 7. Tests the review scores on

Run the default suite with `php artisan test` on SQLite memory.

- Concurrent last unit: listing quantity `1`, several buyers each able to pay. Exactly one order reaches `escrow_locked`, quantity ends at `0`, exactly one escrow journal exists, and no balance is negative. SQLite serializes writers, so this test must hit MySQL (or PostgreSQL) with one PHP process per buyer sharing that database. Spawn the processes with Symfony Process. Skip the test when `RACE_DB_CONNECTION` is not set, and document the MySQL command in the README. Default `php artisan test` still passes without MySQL.
- Atomic rollback: force the journal post to throw after the quantity decrement. Assert the listing quantity, order count, and journal count are unchanged.
- Illegal transition: calling complete on `escrow_locked` changes nothing.
- Settlement and refund: assert every journal still balances and the derived wallet totals match the scenario.

### 8. README

Replace the skeleton README with:

- The business flow in a few paragraphs.
- Why there is no balance column and how a journal is proved balanced.
- Lock order and deadlock retry.
- Fee formula and worked numeric example.
- How to run `php artisan test`.
- How to run the MySQL race test, including the env vars and a note that Windows uses separate PHP processes because `pcntl` is unavailable.

## Done when

- `php artisan test` passes.
- A purchase of the last unit cannot oversell under concurrent MySQL processes.
- A failed step inside purchase, settlement, or refund leaves stock, orders, and the ledger as they were.
- Controllers contain no balance math and no stock checks.
