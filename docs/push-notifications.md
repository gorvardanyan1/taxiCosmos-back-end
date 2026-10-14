# Push notifications (P8-T4)

The rider and driver apps receive pushes through the Expo Push Service. The backend never calls Expo from a request:
a domain event is turned into a queued job (`SendPushNotification`) that sends after the database commit.

## Registering a phone

| Call | Does |
| --- | --- |
| `POST /api/v1/devices` `{push_token, platform: ios\|android, locale?, app_version?}` | Registers the phone for the signed-in app (rider or driver, taken from the token's ability). `201` when new, `200` when the token was already known (it is moved to the current user). The response never contains the token. |
| `DELETE /api/v1/devices/{id}` | Deregisters one of the caller's own devices (`204`, repeating it is fine; someone else's device is `404`). |

`push_token` must look like `ExponentPushToken[...]` (or `ExpoPushToken[...]`). Both calls are rate limited by `throttle:devices`
(see `rate-limits.md`).

A registration belongs to the sign-in it was made on (the install id sent at sign-in). It ends automatically when:
that session logs out, `logout-all` runs, another account signs in on the same install, the account is deleted, or Expo reports the
token invalid (`DeviceNotRegistered`). A suspended account keeps its devices only so the suspension notice can be delivered.

## What is sent

| `data.type` | Trigger | Apps | Urgent |
| --- | --- | --- | --- |
| `trip_offer` | `TripOffer` realtime event | driver | yes (high priority, `trip_offers` channel, time-sensitive, ttl `PUSH_OFFER_TTL`) |
| `trip_matched`, `trip_arrived`, `trip_completed`, `no_driver_found` | `TripStatusChanged` | rider | no |
| `document_approved`, `document_rejected` | `DriverDocumentReviewed` (admin review) | driver | no |
| `account_suspended` | `UserSuspended` | the suspended user's devices | no |
| `support_reply` | `SupportTicketUpdated` with change `reply` | requester | no |
| `payout_paid` | not wired yet: call `PushNotifier::notify($user, PushType::PayoutPaid, [...])` from the payout task (P6-T8) | driver | no |

Texts are in the user's language (account language, then the phone's, then the default) and carry no names, addresses, money or
rejection reasons. `data` holds only `type` and ids, so the app fetches details over the API.

## Delivery and receipts

* `PUSH_DRIVER`: `expo` or `log`. Production has no default and must choose; elsewhere the default is `log`, which writes the push
  (token fingerprint only) to the log. Optional `EXPO_ACCESS_TOKEN` for an enhanced-security Expo project.
* The job retries 4 times (10 s, 1 min, 5 min). Needs a queue worker (Horizon).
* Expo reports undeliverable tokens in *receipts*. `push:check-receipts` (scheduled every five minutes, `onOneServer`) reads the
  tickets older than `PUSH_RECEIPT_DELAY_MINUTES` (15), revokes devices reported `DeviceNotRegistered`, and drops tickets older
  than `PUSH_TICKET_RETENTION_HOURS` (24).
* Tokens are never written to logs or responses.

Tests: `tests/Feature/Push/*` (Expo HTTP faked), `cypress/e2e/p8-t4-push-notifications.cy.ts` (real server, log driver).
