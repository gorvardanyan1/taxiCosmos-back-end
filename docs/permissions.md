# Admin roles & permissions

Admin access uses [spatie/laravel-permission](https://spatie.be/docs/laravel-permission) on the
`web` (session) guard. Mobile users (riders/drivers) never get admin roles through the API.

## Rules

- **Every `/admin` route is protected by a named permission** (`permission:payments.refund`
  middleware or a Policy calling `$user->can(...)`), never by a role check alone. Roles are only
  bundles of permissions.
- **Admin-tier access needs both** the `users.is_admin` flag **and** at least one admin role, on an
  `active` account (`User::hasAdminAccess()`). The flag alone grants nothing.
- **`super_admin` bypasses every check** through `Gate::before` in `AppServiceProvider`, as long as
  the account still has admin access (a suspended super admin loses the bypass).
- The admin UI hides what the current user lacks (P13-T2, below), but the server is the only
  enforcement point.
- **Self-service pages need no permission**: `GET /admin/account` (My Account) only requires
  admin access, because every admin manages their own profile and security.

## Two-factor authentication (P3-T2)

- The roles in `security.two_factor.required_roles` (`ADMIN_2FA_REQUIRED_ROLES`, default `super_admin,finance`; `none` turns the rule off, a
  blank value keeps the default) must have it. Until they have finished the setup (a code from the app entered) the
  `two-factor.required` middleware lets them reach nothing but `/two-factor-setup` and sign-out: pages redirect there, writes are refused with
  403. It guards every `/admin` route and `/horizon` (a super admin cannot go round it through Horizon).
- Those roles cannot switch it off (403 whatever is sent); every other admin role may turn it on and off from My Account.
- Only a super admin can reset another admin's two-factor (lost phone): own password and a reason, logged. A reset admin whose role
  requires it must set it up again on their next request.
- `AdminAccessTest` fails when an `/admin` route lacks the `two-factor.required` middleware.

## Admin page → permission (P13-T1 route map)

| Route | Permission |
| --- | --- |
| `GET /admin` | `dashboard.view` |
| `GET /admin/live-map` | `live_map.view` |
| `GET /admin/riders`, `/admin/riders/{id}` | `riders.view` |
| `PATCH /admin/riders/{id}` (contact details, reason required) | `riders.edit` |
| `POST /admin/riders/{id}/suspend`, `.../reactivate` (reason required) | `riders.suspend` |
| `GET /admin/drivers`, `/admin/drivers/{id}`, `/admin/drivers/{id}/vehicles` | `drivers.view` |
| `PATCH /admin/drivers/{id}` (reason required) | `drivers.edit` |
| `POST /admin/drivers/{id}/suspend`, `.../reactivate` (reason required) | `drivers.suspend` |
| `POST /admin/drivers/{id}/vehicles`, `PATCH /admin/drivers/{id}/vehicles/{vehicleId}`, `POST .../vehicles/{vehicleId}/set-primary` | `drivers.edit` |
| `POST /admin/drivers/{id}/bank-accounts/{accountId}/reveal` (logged, rate-limited) | `drivers.reveal_bank_account` |
| `GET /admin/drivers/{id}/documents/{docId}/file` (signed, expiring link) | `drivers.view` |
| `POST /admin/drivers/{id}/documents/{docId}/approve`, `.../reject` (reason required) | `drivers.verify` |
| `GET /admin/trips`, `/admin/trips/{id}` | `trips.view` |
| `GET /admin/support-tickets` | `support.manage` |
| `GET /admin/ratings` | `ratings.view` |
| `GET /admin/transactions` | `payments.view` |
| `GET /admin/payouts` | `payouts.view` |
| `GET /admin/chargebacks` | `chargebacks.manage` |
| `GET /admin/driver-balances` | `driver_balances.view` |
| `GET /admin/reports` | `reports.view` |
| `GET /admin/zones`, `POST /admin/zones`, `PATCH /admin/zones/{id}`, `POST /admin/zones/{id}/deactivate`, `.../activate` | `zones.manage` |
| `POST /admin/zones/{id}/fare-rules` (add a fare rule version; the Zones page shows prices and history with `zones.manage`, saving needs this too) | `fares.manage` |
| `GET /admin/surge` | `fares.manage` |
| `GET /admin/commission-rules`, `POST /admin/commission-rules` (add a version) | `fares.manage` |
| `GET /admin/settings` (redirects to the first Settings tab the admin may open: Users & Roles, else Gateways) | `admins.manage` or `settings.manage` |
| `GET /admin/settings/users` | `admins.manage` |
| `GET /admin/settings/{gateways,currencies,platform,maps}` | `settings.manage` |
| `GET /admin/activity-logs`, `/admin/activity-logs/export` (CSV) | `activity_log.view` |
| `GET /admin/socket-ticket` (a one-time ticket for the admin's socket; docs/realtime-events.md) | admin access only (self-service; identifies only the signed-in admin) |
| `GET /admin/account` | admin access only (self-service) |
| `PATCH /admin/account/locale` (choose the panel language) | admin access only (self-service; changes only the signed-in admin) |
| `GET /two-factor-setup`, `POST /two-factor-setup` (start; password), `POST /two-factor-setup/confirm` (code) | admin access only (self-service; outside `/admin` so an admin who must set it up can reach it) |
| `DELETE /admin/account/two-factor` (password), `POST /admin/account/two-factor/recovery-codes` (password) | admin access only (self-service; they change only the signed-in admin's own two-factor) |
| `POST /admin/settings/users/{id}/two-factor/reset` (own password and a reason; logged) | `admins.manage`, and the actor must be a `super_admin` (the service checks both) |

## What the admin UI shows (P13-T2)

The browser gets the admin's permissions as the shared prop `auth.permissions` (every permission for a super admin) and hides
what they do not allow: `useShared().can(permission)` and `<Can permission="...">` (a list means "any one of"). The server
answers 403 for the same requests anyway (`PermissionMatrixTest` checks every role against every `/admin` route).

| UI element | Shown with |
| --- | --- |
| Sidebar item | the permission of its page (`resources/js/lib/navigation.tsx`; Settings: `admins.manage` or `settings.manage`); a group with no item left is dropped |
| Sidebar "Queue Monitor" link | the Horizon gate `viewHorizon` (super admin, admin), shared as `navigation.queueMonitor` |
| Settings tabs | Users & Roles: `admins.manage`; Gateways, Currencies, Platform, Maps: `settings.manage` |
| Riders: Edit | `riders.edit`; Suspend / Reactivate (page and list menu): `riders.suspend` |
| Drivers: Edit | `drivers.edit`; Suspend / Reactivate (page and list menu): `drivers.suspend`; "+ Add Driver": `drivers.edit` |
| Drivers: Approve / Reject a document | `drivers.verify` |
| Drivers: add / edit a vehicle, Set as primary | `drivers.edit` |
| Drivers: Reveal account number | `drivers.reveal_bank_account` |
| Trips: Force Cancel and Reassign Driver | `trips.force_cancel` (assumption: P7-T2 defines no separate reassign permission) |
| Trips: Adjust Fare | `trips.adjust_fare` (the "Admin Actions" panel is hidden when neither is held) |
| Payments: Record Manual Payment, Confirm / Reject a manual payment | `payments.manual`; Refund: `payments.refund` |
| Payouts: Approve batch, Approve / Reject | `payouts.approve` |
| Support tickets: Issue refund | `payments.refund` (on top of `support.manage` for the page) |
| Zones & Fares: Add fare rule, new version of a rule | `fares.manage` (the page itself needs `zones.manage`) |

Actions whose endpoint and permission are not built yet (driver balance adjustments and cash settlements, ratings moderation,
gateway keys, user invitations) are still shown disabled with the "not available yet" hint; the task that builds each one adds its
permission here and gates it the same way. `tests/Feature/Admin/PermissionNavigationTest` keeps `resources/js/lib/permissions.ts`
equal to `AdminPermission` and every sidebar item equal to its route's permission.

### Sidebar badges

`App\Services\Admin\NavigationBadges` counts, cached for **60 seconds** (`admin:navigation-badges`, the same numbers for every admin):
Drivers = driver profiles with verification pending; Support Tickets = open urgent tickets (P7-T5); Payments = manual payments awaiting
confirmation (P6-T5); Payouts = pending payouts (P6-T8). The last three read 0 until their tables exist, so no badge shows.
An admin receives only the badges of pages they may open, and a zero count sends no badge.

## Source of truth

| What | Where |
| --- | --- |
| Permission names | `app/Enums/AdminPermission.php` |
| Role names + default matrix | `app/Enums/AdminRole.php` (`defaultPermissions()`) |
| Seeder | `database/seeders/RolesAndPermissionsSeeder.php` (idempotent; re-running resets every role to the matrix below) |

`php artisan db:seed --class=RolesAndPermissionsSeeder` applies the matrix on any environment.
`tests/Feature/Rbac/RolesAndPermissionsSeederTest.php` fails if this table and the enums drift apart.

## Adding a permission

1. Add a case to `AdminPermission`.
2. Add it to the roles that should get it in `AdminRole::defaultPermissions()`
   (`super_admin` gets every case automatically; `admin` gets everything except `admins.manage`).
3. Add a row to the matrix below.
4. Protect the route with `permission:<name>` and re-run the seeder on each environment.

## Role → permission matrix

| Permission | `super_admin` | `admin` | `support` | `finance` | `dispatcher` | What it allows |
| --- | :---: | :---: | :---: | :---: | :---: | --- |
| `dashboard.view` | ✓ | ✓ | ✓ | ✓ | ✓ | Admin dashboard (KPIs, recent activity) |
| `riders.view` | ✓ | ✓ | ✓ | ✓ | ✓ | View rider list, profiles, trips and payments |
| `riders.edit` | ✓ | ✓ | ✓ | — | — | Edit a rider's name, email, phone and language (reason required) |
| `riders.suspend` | ✓ | ✓ | ✓ | — | — | Suspend / reactivate a rider (reason required) |
| `drivers.view` | ✓ | ✓ | ✓ | ✓ | ✓ | View driver list, profiles, vehicles and documents |
| `drivers.edit` | ✓ | ✓ | ✓ | — | — | Edit a driver's name, email, phone, licence and home zone (reason required), and manage the driver's vehicles |
| `drivers.suspend` | ✓ | ✓ | ✓ | — | — | Suspend / reactivate a driver (reason required) |
| `drivers.verify` | ✓ | ✓ | ✓ | — | — | Approve / reject driver applications and documents |
| `drivers.reveal_bank_account` | ✓ | — | — | ✓ | — | Reveal a driver's full bank account number (every reveal is logged) |
| `trips.view` | ✓ | ✓ | ✓ | ✓ | ✓ | View trip list and trip detail |
| `trips.force_cancel` | ✓ | ✓ | — | — | ✓ | Force-cancel or reassign a live trip |
| `trips.adjust_fare` | ✓ | ✓ | — | ✓ | — | Adjust the fare of a completed trip |
| `payments.view` | ✓ | ✓ | ✓ | ✓ | — | View transactions and payment detail |
| `payments.refund` | ✓ | ✓ | — | ✓ | — | Issue refunds (idempotency key + reason required) |
| `payments.manual` | ✓ | ✓ | — | ✓ | — | Record manual/offline payments, wallet & ledger adjustments, cash settlements |
| `payouts.view` | ✓ | ✓ | — | ✓ | — | View driver payouts |
| `payouts.approve` | ✓ | ✓ | — | ✓ | — | Approve / reject driver payouts |
| `driver_balances.view` | ✓ | ✓ | — | ✓ | — | Driver balances and debt ageing |
| `chargebacks.manage` | ✓ | ✓ | — | ✓ | — | Manage bank chargebacks |
| `zones.manage` | ✓ | ✓ | — | — | — | Create/edit cities and zones |
| `fares.manage` | ✓ | ✓ | — | — | — | Manage fare rules, surge and commission rules |
| `settings.manage` | ✓ | ✓ | — | — | — | Platform settings, currencies, gateways |
| `admins.manage` | ✓ | — | — | — | — | Invite, edit and deactivate admin users and their roles |
| `activity_log.view` | ✓ | ✓ | — | — | — | View the admin activity/audit log |
| `live_map.view` | ✓ | ✓ | — | — | ✓ | Live operations map |
| `reports.view` | ✓ | ✓ | — | ✓ | — | Reports and exports |
| `support.manage` | ✓ | ✓ | ✓ | — | — | Support tickets (rider & driver complaints) |
| `ratings.view` | ✓ | ✓ | ✓ | — | — | Ratings and reviews list |

### Roles

| Role | Purpose |
| --- | --- |
| `super_admin` | Platform owner. Everything, including managing other admins. |
| `admin` | Operations lead. Everything except managing admin accounts. |
| `support` | Handles rider/driver accounts, driver verification and support tickets. No money actions. |
| `finance` | Payments, refunds, manual payments, payouts, chargebacks, fare adjustments and reports. |
| `dispatcher` | Live operations: live map, trip oversight and force-cancel. |
