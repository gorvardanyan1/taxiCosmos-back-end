# Payment core schema (P6-T1)

The tables every payment feature is built on (P6-T2 onwards). **Rider money only**: driver payouts are not rows in `transactions`; they live in the `payouts` table (P6-T8) and the driver earnings ledger (P11-T2), so rider money and driver money are separate ledgers that reconcile through trips.

## Rules for every table

* **Money is an integer in minor units next to its own currency column** (`amount` + `currency`; the wallet's currency is on `wallets`). No float, decimal or numeric column exists in these tables; `amount` columns are `bigint` with `CHECK (amount > 0)`, currencies are `CHECK (currency ~ '^[A-Z]{3}$')`.
* **No raw card number or CVC can be stored.** A card is typed into Stripe Elements / Authorize.Net Accept.js inside the apps; only the gateway's tokens, brand, last four digits and expiry reach us (PCI SAQ-A). `payment_methods.last4` is `char(4)` with a digits-only check (a 16-digit number does not fit), the free-text columns are length-capped, and `transactions.metadata` (jsonb) is the only open column: never put card data in it. `PaymentSchemaTest` scans the columns for card-like names and shapes.
* History is never cascaded away: foreign keys to users, trips and cards are `RESTRICT`; a removed card is a soft delete.

## `transactions`

`id, trip_id?, user_id, payment_method_id?, parent_transaction_id?, gateway[stripe|authorize_net|manual|wallet], gateway_reference?, idempotency_key, type[authorization|capture|charge|void|refund], status[pending|requires_action|processing|succeeded|failed|cancelled], amount, currency, failure_code?, failure_message?, metadata?, created_by?, created_at, updated_at`.

* **`UNIQUE (gateway, idempotency_key)`**, key required and non-empty: a second attempt with the same key on the same gateway fails in the database (`23505`), not only in application code. The same key on another gateway is a different attempt.
* A `capture`, `void` or `refund` must have a `parent_transaction_id` (the authorization or charge it acts on); a row cannot be its own parent.
* A `manual` entry names the admin who recorded it (`created_by`).

## `payment_methods`

`id, user_id, gateway[stripe|authorize_net], gateway_customer_id?, gateway_payment_method_id, brand?, last4, exp_month, exp_year, is_default, timestamps, deleted_at`. One live default card per rider and one live row per gateway token (partial unique indexes). The gateway ids are hidden from serialisation. `trips.payment_method_id` is now a real foreign key (`ON DELETE SET NULL`).

## `wallets` and `wallet_transactions` (the ledger)

`wallets(user_id, currency, balance)`, unique per user and currency; `balance >= 0`. `wallet_transactions(wallet_id, type[credit|debit], amount, reason, reference_type?, reference_id?, created_by?, created_at)`.

* **`wallet_transactions` is append-only.** Rows are only ever inserted, by `App\Services\Payments\WalletLedger`. The model refuses `update`/`delete`, a database trigger refuses them for any code (`UPDATE`, `DELETE`, bulk delete), and `WalletLedgerTest` scans the application code for any write other than an insert. A mistake is corrected with a new, opposite entry.
* **`wallets.balance` is a cached total.** `WalletLedger::credit/debit` insert the ledger row and move the balance in the same database transaction, under the wallet's row lock (`SELECT … FOR UPDATE`), so two movements cannot overdraw or drift. A debit larger than the balance is refused (`409`).
* **A movement with a cause is idempotent.** Giving a `reference_type` and `reference_id` (a refund transaction, a trip) makes the same cause post once: `UNIQUE (wallet_id, type, reference_type, reference_id)`, and a repeat returns the first entry and moves nothing, so a retried refund cannot pay out twice.

## Nightly reconciliation

`php artisan wallets:reconcile` (scheduled daily at 02:20, `withoutOverlapping`, `onOneServer`) recalculates every wallet's total from its ledger (credits minus debits, one grouped query) and compares it with the cached balance. Each mismatch is logged at **critical** level (`wallet_id, user_id, currency, cached, ledger, difference`) so it reaches the on-call alert, and the command exits with a failure code. It **never edits** a balance or the ledger: a mismatch means a bug or tampering and a person decides. Wiring the critical log to Slack or email is an operations setting; the in-app alert arrives with P13-T6.

## `processed_webhook_events`

`gateway, event_id, received_at`, `UNIQUE (gateway, event_id)`. `ProcessedWebhookEvent::firstTime($gateway, $eventId)` records a delivery and returns false for a repeat, so a webhook handler discards a delivery it has already applied (the unique key decides when two arrive together).

## What is not here yet

The gateways and their webhooks (P6-T2 to P6-T4), manual payments (P6-T5), refunds (P6-T7), wallet use at checkout and the Transactions admin page's real data (it still shows fixtures) come with their own tasks and build on these tables.
