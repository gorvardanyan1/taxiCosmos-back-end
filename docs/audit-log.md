# Audit log (activity log)

Every admin write and every admin sign-in event is recorded with
[spatie/laravel-activitylog](https://spatie.be/docs/laravel-activitylog) through **one service**,
`App\Services\Audit\AuditLogger`. Never call `activity()` directly for admin actions.

## What an entry holds

| Column / key | Content |
| --- | --- |
| `log_name` | `admin` (admin actions) or `auth` (sign-in events) |
| `description` | the **action**, dotted: `driver.document.rejected`, `auth.login`, `activity_log.exported` |
| `event` | last segment of the action (`rejected`) |
| `causer_*` | the admin who did it (empty for system actions and failed sign-ins for unknown emails) |
| `subject_*` | the record acted on (driver, document, ...) |
| `attribute_changes` | `old` (before) and `attributes` (after), already redacted |
| `properties.reason` | the reason the admin gave (absent when none) |
| `properties.ip`, `user_agent` | where the request came from |
| `properties.actor_name`, `actor_role` | the actor **as they were then** (a renamed or deleted admin does not change history) |
| `properties.target_label` | human reference of the record (`D-0035 / Insurance certificate`), never personal data |
| `properties.context` | extra facts (ids, types), never secrets |

## Sensitive values

`AuditRedactor` builds the diff: encrypted personal data, credentials, secrets, passwords and tokens
(`PiiColumns::sensitiveNamePattern()` plus password/token/otp/recovery) are **never stored as values**.
A changed sensitive field shows `"changed"` on both sides; an unchanged one is left out. Nested arrays
are handled too. Failed sign-ins store the email that was typed, never the password.

## Adding an audit entry for a new admin action

1. Call `AuditLogger::record($actor, 'domain.thing.verb', $target, $reason, $old, $new, $context, reasonRequired: true|false)`
   inside the same transaction as the change.
2. If the target model is new, add a case (and a `describe()` line) to `AuditTargetType`.
3. Add the route to `AdminWritesAreAuditedTest::registry()`: that test fails for any admin
   POST/PUT/PATCH/DELETE route that is not listed and exercised.
4. Actions the task marks "reason required" pass `reasonRequired: true` (an empty reason throws) and validate
   the reason in their Form Request.

## What is logged today

| Action | Where |
| --- | --- |
| `driver.document.approved` / `driver.document.rejected` | `DriverDocumentReviewer` |
| `driver.verification.changed` | `DriverVerificationSync` (when a review changes the driver's status) |
| `auth.login`, `auth.logout` (incl. idle timeout), `auth.login_failed` | `LogAuthActivity` |
| `activity_log.exported` | `ActivityLogController::export` (filters used) |
| `zone.created`, `zone.updated`, `zone.deactivated`, `zone.activated` | `ZoneService` (see [zones.md](zones.md)) |
| `rider.updated`, `rider.suspended`, `rider.reactivated` | `RiderService` (see [riders.md](riders.md)); a reason is required |

Refunds, manual payments, fare adjustments, suspensions, role and settings changes, 2FA reset and PII reveal
are logged by their own tasks through the same service.

## Activity Log page and export

`GET /admin/activity-logs` (permission `activity_log.view`): filters `search`, `actor` (admin id), `action`
(exact or a prefix such as `driver.document`), `target_type`, `target_id`, `from` / `to` (dates, inclusive,
in the admin's timezone), `sort=occurred_at|-occurred_at`, `per_page`. Unknown filters and malformed values
return 400. `?entry=<id>` opens an entry's before/after diff.

`GET /admin/activity-logs/export` returns the **current filter** as CSV (all rows, not one page), capped at
`AUDIT_EXPORT_MAX_ROWS` (default 50 000). Cells starting with `=`, `+`, `-`, `@` are prefixed with `'` so
spreadsheets do not run them as formulas. Every export is itself logged (with the filters) and appears in the
next export.

## Immutable, with retention

- There are **no routes** that write to the log, and `ActivityLogEntry` refuses `update`, `save` on an existing
  row, `delete`, `forceDelete`, `touch`, `destroy`; its query builder refuses `update`, `delete`, `increment`,
  `upsert`. `config('activitylog.activity_model')` points to it, so the logger uses it.
- Retention: `ACTIVITYLOG_RETENTION_DAYS` (default **730**, two years). `activitylog:clean --force` runs daily at
  03:15 (`withoutOverlapping`, `onOneServer`) and removes entries older than that. `--days=` can only lengthen the
  period, never shorten it below the configured retention.

Tests: `tests/Feature/Audit/*`, `tests/Unit/Audit/AuditRedactorTest.php`.
