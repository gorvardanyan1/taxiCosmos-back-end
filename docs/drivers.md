# Admin driver management (P4-T2)

The Drivers list, the driver detail page with its six tabs, and what an admin may change about a driver.
Documents and their review are in [security.md](security.md) / P2-T3; the data model is P2-T2.

## The list: `GET /admin/drivers` (`drivers.view`)

A real, paginated list (10 / 25 / 50 per page, newest first). URL contract, like every admin list; unknown filters, sorts and
malformed values are **400**:

| Parameter | Meaning |
| --- | --- |
| `filter[search]` | name or email contains; driver code (`D-0042`, `42`); an **exact phone** (any way of writing it, through the blind index); an **exact licence number** (case and spaces ignored, through `license_number_hash`) |
| `filter[verification_status]` | `pending` / `approved` / `rejected` / `expired` |
| `filter[availability]` | `online` / `offline` / `on_trip` |
| `filter[zone]` | the driver's home zone id |
| `filter[vehicle_class]` | the driver has a (non-removed) vehicle of that class |
| `filter[license_number]` | exact licence number |
| `filter[documents_expiring]` | a document expires within N days (1-365, the last day included; already expired documents are not "expiring") |
| `sort` | `name`, `trips_count`, `rating` (prefix `-` for descending; unrated drivers always last) |

Each row carries the completed-trip count and the earnings as sub-selects, so the page issues the same queries however many drivers it shows.

## The detail page: `GET /admin/drivers/{id}` and `/vehicles`

Tabs (`?tab=`): profile, documents, vehicles, earnings, trip-history, bank-accounts. `GET /admin/drivers/{id}/vehicles` is the same page on the Vehicles tab.
The licence number is never sent to the browser (only its last four digits); the page's edit form starts with it empty ("leave empty to keep it").

- **Trip history** is the driver's latest 50 trips from the trips table (route, fare, status).
- **Earnings** is the driver's share of completed trips (`trips.driver_earnings_amount`, the split taken when the trip was completed) in the currency of the
  driver's home zone (the base currency without one): this week (7 days), this month (30 days), all time, and the latest 50 as ledger entries.
  **Until the earnings ledger exists (P11-T2) there are no payouts, cash settlements or adjustments, so the balance is simply the total earned**, the page says so
  (`ledger_pending`), and "Add adjustment" / "Record cash settlement" stay disabled. When P11-T2 lands, `DriverEarningsOverview` is the one class to replace.

## Changes

| Route | Permission | What |
| --- | --- | --- |
| `PATCH /admin/drivers/{id}` | `drivers.edit` | name, email, phone, licence number, licence expiry, home zone; only the fields sent change; **reason required**; nothing changed = no write and no audit entry; duplicates (phone, email, licence) are refused |
| `POST /admin/drivers/{id}/suspend` | `drivers.suspend` | **reason required**; sets the account `suspended` (keeps the reason), signs the driver out of every device, takes them offline, publishes `user.suspended` after commit. **Refused (409, "This driver is on a trip. Force-cancel or reassign the trip first.") while the driver has a trip that is matched, arrived or in progress.** Also refused for your own account, an admin account, and a driver who is not active |
| `POST /admin/drivers/{id}/reactivate` | `drivers.suspend` | **reason required**; only a suspended driver; the driver stays offline and goes online again themselves |
| `POST /admin/drivers/{id}/vehicles` | `drivers.edit` | add a vehicle (active; primary when the driver has none; the plate is stored in capitals without spaces and belongs to one vehicle) |
| `PATCH /admin/drivers/{id}/vehicles/{vehicleId}` | `drivers.edit` | change the fields sent (including `status`); a vehicle that stops being active stops being primary |
| `POST /admin/drivers/{id}/vehicles/{vehicleId}/set-primary` | `drivers.edit` | only an active vehicle that is not already primary (409 otherwise); under the driver's row lock |
| `POST /admin/drivers/{id}/bank-accounts/{accountId}/reveal` | `drivers.reveal_bank_account` | see below |

Every write is one transaction under the driver's row lock (the lock matching and availability use) and writes its audit entry
(`driver.updated`, `driver.suspended`, `driver.reactivated`, `driver.vehicle.added|updated|primary_set`; the licence number, phone and email show as "changed", never as values).
Suspension is a property of the account (`users.status`), exactly like a rider's: a person who is both a driver and a rider is suspended as a whole.

## Bank accounts and the reveal

The page and every API shape carry only `account_last4`. Finance (and super admin) can **reveal** the full number: the page asks the server
(`POST .../reveal`), the server writes an activity-log entry **first** (who, which account, which driver, the last four digits - never the number), then answers
`{"account_number": "..."}` with `Cache-Control: no-store`; the page shows it for 30 seconds and hides it again. One entry per reveal, 10 reveals per minute per admin
(`bank-account-reveal` limiter). `admin` does not hold the permission.

## Deleted accounts

A driver whose account was deleted keeps their documents, trips and earnings for review, but the page shows no personal data and offers no Edit, Suspend or Reactivate. The server refuses those three requests with a 409 ("This driver's account was deleted. It can no longer be changed.") and writes nothing, not even an audit entry. Vehicles, documents and the bank-account reveal are not affected by this rule.
