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
- The admin UI hides actions the current user lacks (P13-T2), but the server is the only
  enforcement point.
- **Self-service pages need no permission**: `GET /admin/account` (My Account) only requires
  admin access, because every admin manages their own profile and security.

## Admin page → permission (P13-T1 route map)

| Route | Permission |
| --- | --- |
| `GET /admin` | `dashboard.view` |
| `GET /admin/live-map` | `live_map.view` |
| `GET /admin/riders`, `/admin/riders/{id}` | `riders.view` |
| `GET /admin/drivers`, `/admin/drivers/{id}` | `drivers.view` |
| `GET /admin/trips`, `/admin/trips/{id}` | `trips.view` |
| `GET /admin/support-tickets` | `support.manage` |
| `GET /admin/ratings` | `ratings.view` |
| `GET /admin/transactions` | `payments.view` |
| `GET /admin/payouts` | `payouts.view` |
| `GET /admin/chargebacks` | `chargebacks.manage` |
| `GET /admin/driver-balances` | `driver_balances.view` |
| `GET /admin/reports` | `reports.view` |
| `GET /admin/zones` | `zones.manage` |
| `GET /admin/surge`, `/admin/commission-rules` | `fares.manage` |
| `GET /admin/settings/users` | `admins.manage` |
| `GET /admin/settings/{gateways,currencies,platform,maps}` | `settings.manage` |
| `GET /admin/activity-logs` | `activity_log.view` |
| `GET /admin/account` | admin access only (self-service) |

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
| `riders.suspend` | ✓ | ✓ | ✓ | — | — | Suspend / reactivate a rider (reason required) |
| `drivers.view` | ✓ | ✓ | ✓ | ✓ | ✓ | View driver list, profiles, vehicles and documents |
| `drivers.suspend` | ✓ | ✓ | ✓ | — | — | Suspend / reactivate a driver (reason required) |
| `drivers.verify` | ✓ | ✓ | ✓ | — | — | Approve / reject driver applications and documents |
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
