# Card payments (P6-T2)

The gateway contract, the card trip flow, saved cards and outstanding balances. The tables are in `docs/payments-schema.md` (P6-T1).

## The gateway boundary

`App\Contracts\PaymentGatewayInterface` is the only way to a card gateway:

| Method | Does |
| --- | --- |
| `authorize(amount, currency, paymentMethod, idempotencyKey, meta)` | hold money on a card |
| `capture(authorizationId, amount, idempotencyKey)` | take up to the held amount |
| `void(authorizationId, idempotencyKey)` | release a hold |
| `charge(amount, currency, paymentMethod, idempotencyKey, meta)` | take money at once |
| `refund(transactionId, amount, idempotencyKey)` | give back part or all of a capture or charge |
| `getStatus(transactionId)` | what the gateway says now |
| `createCustomer(user)`, `attachPaymentMethod(customer, token)`, `detachPaymentMethod(paymentMethod)` | saved cards |

* `idempotencyKey` is a **required string** on every money method (a test fails if it becomes optional or nullable). Stripe (P6-T3) sends it as its own `Idempotency-Key` header; Authorize.Net (P6-T4) has none and dedupes on our row.
* Every money method returns `App\Data\PaymentResult` (`status`, `gatewayReference`, `rawResponse`, `failureCode`, `failureMessage`). A declined card is a **failed result**, not an exception. `PaymentGatewayException` means the outcome is unknown (timeout, unreachable, or no gateway installed: 503 `payment_gateway_unavailable`).
* `meta` carries ids for the gateway dashboard (`user_id`, `trip_id`, `purpose`). Never card data, names or phone numbers. A gateway class trims `rawResponse` to what is safe to keep before returning it; it is stored in `transactions.metadata.gateway_response`.
* Only `App\Services\Payments` uses the interface or `PaymentGateways`; SDK classes (`Stripe\…`, `net\authorize\…`) appear only under `app/Services/Payments/Gateways/`. `PaymentGatewayBoundaryTest` scans `app/` for both.

`PaymentGateways::for(gateway)` returns the class for a row's gateway (a capture, void or refund always goes back to the gateway of the original); `default()` is `PAYMENT_GATEWAY`, used for new cards.

| Setting | Default | Meaning |
| --- | --- | --- |
| `PAYMENT_GATEWAY` | `stripe` | gateway new cards are saved with (`stripe`, `authorize_net`) |
| `PAYMENT_DRIVER` | `fake` in local/testing/e2e, else `live` | `fake` = no-network gateway (refused elsewhere); `live` = the real classes from P6-T3/P6-T4. Until one exists, card endpoints answer 503 and the apps stay cash-only |
| `PAYMENT_HOLD_BUFFER_PERCENT` | `20` | hold = estimate × (100 + buffer) / 100, rounded up |
| `STRIPE_HOLD_LIFETIME_HOURS` / `AUTHORIZE_NET_HOLD_LIFETIME_HOURS` | `168` / `720` | how long the gateway keeps a hold |
| `PAYMENT_HOLD_RENEW_MARGIN_HOURS` | `24` | renew a hold this long before it lapses |
| `PAYMENT_MAX_CARDS` | `10` | live saved cards per rider |
| `PAYMENT_LOCK_SECONDS` / `PAYMENT_LOCK_WAIT_SECONDS` | `60` / `10` | per-key attempt lock, and how long a second attempt waits before `409 payment_in_progress` |

## Idempotency: `PaymentProcessor`

`App\Services\Payments\PaymentProcessor` moves every card amount, one `transactions` row per attempt:

1. The caller generates the key **once per logical attempt** (per HTTP request from the app, or derived from the trip: `trip:{id}:hold`, `trip:{id}:capture`, `trip:{id}:extra`, `trip:{id}:charge`, `trip:{id}:void`) and reuses it on every retry.
2. Under a lock per (gateway, key), the row is looked up by `(gateway, idempotency_key)`:
   * found and finished → returned as it is, **no gateway call** (charging twice with one key creates no second row and no second charge);
   * found but `processing` (the earlier answer was lost) → the gateway is called again **with the same key**, which dedupes it;
   * found but for a different payment (amount, currency, card, rider, trip, type or parent) → `409 idempotency_key_reused`.
3. A new attempt is inserted as `processing` and **committed before the gateway is called**. Do not call the processor inside your own DB transaction.
4. The gateway's answer updates the row (status, reference, failure, raw response). A timeout leaves it `processing` for the retry.

A capture or void locks the hold row and refuses a hold already captured or voided (`hold_not_open`); a failed capture does not close it. A refund locks the original and refuses more than what is left (`refund_exceeds_remaining`); only a succeeded capture or charge can be refunded.

## Card trip flow: `TripCardPayments`

Cash trips never come here and make no gateway call (P11-T3).

| Step | Who calls | What happens |
| --- | --- | --- |
| `hold(trip)` | trip creation (P7-T6) | refused while the rider owes money (`409 outstanding_balance`) or has no such card (`409 no_payment_method`); authorizes estimate + buffer; trip `payment_status = authorized`; a decline is `402 card_declined` |
| `complete(trip)` | trip completion (P7-T6) | captures the final fare from the open hold; a fare above the hold captures the whole hold and **charges the difference** separately; no hold → a charge for the whole fare. All succeeded → `paid`, anything failed → `payment_failed` |
| `cancel(trip)` | trip cancellation (P7-T6) | `fare_amount` > 0 (the cancellation fee) is collected like a completion; otherwise the hold is **voided** and the trip stays `unpaid` |
| `renewExpiringHolds()` | `payments:refresh-holds`, hourly, `withoutOverlapping`, `onOneServer` | a succeeded, still-open hold on a trip that has not ended, older than lifetime − margin, gets a new hold for the same amount and the old one is voided. A failed renewal is logged (`payments.hold_renewal_failed`, trip and transaction ids only) and keeps the old hold |

Every step reuses its trip-derived keys, so running a step again (a retried job) moves no more money.

## Outstanding balance

Never stored: for each of the rider's trips in `payment_failed`, the trip's `fare_amount` minus what its succeeded captures and charges collected. While it is above zero the rider cannot start a card trip; `GET /api/v1/me` shows it in `rider.outstanding_balance` (the first currency) so the app asks the rider to update the card on the next open.

## Mobile endpoints (rider app, `ability:rider`)

Writes also use `throttle:payment` (`docs/rate-limits.md`). Errors are `{message, code}`.

| Endpoint | Answer |
| --- | --- |
| `GET /api/v1/payment-methods` | `{data: [{id, brand, last4, exp_month, exp_year, is_default, is_expired}]}`, default first then newest. Gateway ids never leave the server |
| `POST /api/v1/payment-methods` `{token, make_default?}` | `201` the saved card (`200` if already saved). `token` is what the gateway's own card form returned; a card number is rejected (422). The first card becomes the default. `422 token` when the gateway refuses it, `409 card_limit_reached`, `503 payment_gateway_unavailable` |
| `DELETE /api/v1/payment-methods/{id}` | `204`; detached at the gateway, soft-deleted here; the newest remaining card becomes default. `409 card_in_use` while it pays for a trip that has not ended. Another rider's card: `404` |
| `POST /api/v1/payment-methods/{id}/default` | `200` the card, now the only default |
| `GET /api/v1/account/outstanding` | `{data: {has_outstanding, totals: [{amount, currency}], trips: [{id, public_code, amount, currency, ended_at}]}}` |
| `POST /api/v1/account/outstanding/pay` `{payment_method_id?}` + **`Idempotency-Key` header (required, 8–64 of `A-Za-z0-9_-`)** | one charge per owed trip (key `{Idempotency-Key}:trip:{id}`) to the chosen or default card; paid trips become `paid`; answers the new summary. A retry with the same key charges nothing again (`200`). `402 card_declined` (trips charged before it stay paid), `409 nothing_outstanding`, `409 no_payment_method`, `409 idempotency_key_reused` (same key, other card), `422 payment_method_id` (not the rider's card) |

## The fake gateway (local, testing, e2e)

`PAYMENT_DRIVER=fake` keeps its state in the cache (it survives between requests) and behaves like a gateway where it matters: a repeated key returns the first answer and moves nothing, holds lapse after the gateway's lifetime, a hold is captured or voided once. Test tokens for `POST /api/v1/payment-methods`:

| Token | Card | Behaviour |
| --- | --- | --- |
| `tok_visa` | Visa 4242 | everything succeeds |
| `tok_mastercard` | Mastercard 4444 | everything succeeds |
| `tok_declined` | Visa 0002 | saves; every hold and charge is declined |
| `tok_capture_fails` | Visa 0341 | holds succeed; the capture is declined (makes an outstanding balance) |
| anything else (`tok_invalid`) | — | refused at setup (422) |
