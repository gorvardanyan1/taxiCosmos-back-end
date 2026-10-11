# Riders (admin)

Riders sign up in the mobile app, so the admin side does not create them: it lists, views, edits contact details,
suspends and reactivates. A rider is a `users` row with `is_rider = true` (the same account can also be a driver).

| Route | Permission | What |
| --- | --- | --- |
| `GET /admin/riders` | `riders.view` | list: search, status, registration dates, sort, pages |
| `GET /admin/riders/{id}` | `riders.view` | detail: KPI cards and tabs Trips, Payments, Payment methods, Tickets, Ratings, Activity |
| `PATCH /admin/riders/{id}` | `riders.edit` | name, email, phone, locale; **reason required** |
| `POST /admin/riders/{id}/suspend` | `riders.suspend` | **reason required** |
| `POST /admin/riders/{id}/reactivate` | `riders.suspend` | **reason required** |

## List URL contract

`filter[search]` (name or email contains; rider code `R-00042`, `r42` or `42`; an **exact phone** in any way of writing it,
through the blind index), `filter[status]`, `filter[registered_from]` / `filter[registered_to]` (inclusive days in the
admin's timezone, years 1970-2100), `sort=registered_at|name|trips_count` (`-` for descending), `per_page` (10, 25, 50).
Unknown filters or sorts and malformed values answer 400.

## Editing

Only the fields sent change. The phone is stored in E.164 and must be a valid number nobody else uses; the email is
stored lower-case and must be unique. Name and phone cannot be blanked (the phone is the rider's login). Sending the
current values changes nothing and writes no audit entry. Contact details appear in the audit diff only as `changed`.

## Suspending

- Only an **active** rider can be suspended and only a **suspended** one reactivated (otherwise 409).
- An account with **admin access** or the admin's own account is refused (409): admins are managed under Settings → Users.
- Suspending (one transaction): sets `status = suspended` and keeps the reason on the account, **revokes every Sanctum
  token** of the rider, writes the audit entry, and, because the status changes through the model, `UserObserver`
  publishes `user.suspended` to the Redis stream **after the commit** (P8-T1) so the socket service drops their sockets.
- Reactivating clears the stored suspension reason; it publishes nothing.
- A rider who is also a driver is suspended as one account (the status belongs to the user).

Audit actions: `rider.updated`, `rider.suspended`, `rider.reactivated` (see [audit-log.md](audit-log.md)).

## Not built yet

Trips, payments, tickets, ratings and payment methods have no tables yet (P2-T4, P6, P7), so those tabs and the KPI cards
are empty or zero and sorting by trip count is a stable no-op until trips exist; the Activity tab is real (what admins did
to the rider). Export arrives with reports (P13-T9). Deletion/anonymisation is P9-T4.
