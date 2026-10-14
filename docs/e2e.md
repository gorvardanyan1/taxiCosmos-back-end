# End-to-end tests (Cypress)

Cypress drives the real app in a browser (admin pages) and over HTTP (`/api/v1`), against a server
running in its own **`e2e` environment**. Every task with a page, an admin flow or a route ships a
spec in `cypress/e2e/<task-id>-<name>.cy.ts` (see `.claude/commands/task.md`, section 7b).

## The e2e environment

`.env.e2e` (not committed; start from `.env.e2e.example`) points everything away from the dev data:

| | dev | e2e |
| --- | --- | --- |
| PostgreSQL | `taxikosmos` | `taxikosmos_e2e` (create it once: `createdb -O taxikosmos taxikosmos_e2e`) |
| Redis | DB 0 / cache DB 1 | DB 4 / cache DB 5 |
| Realtime streams | `taxikosmos:events:*` | `e2e:events:*` |
| MongoDB | `taxikosmos` | `taxikosmos_e2e` |
| Sessions | redis | database |
| Queue | redis | sync |
| Mail / SMS | log | log, to `storage/logs/e2e.log` |
| Maps (`MAPS_PROVIDER`) | `google` | `fake` (keyless, deterministic; no call leaves the machine) |
| OTP | random | fixed `123456` for `+37491770001…39` |
| Language | per admin / browser | same; admin fixtures default to English (`locale` option) |
| Debug pages | on | off (the template error pages render) |

Copy `APP_KEY` and `BLIND_INDEX_KEY` from your `.env`.

## Running

```bash
npm run build                                   # the server serves the built assets
APP_ENV=e2e php artisan serve --host=127.0.0.1 --port=8001   # in its own terminal
npm run e2e                                     # every spec, headless (Electron)
npm run e2e -- --spec cypress/e2e/p4-t1-*.cy.ts # one task
npm run e2e:open                                # interactive runner
npm run e2e:typecheck                           # TypeScript check of the specs
```

Each spec resets the e2e database in its `before` hook (`cy.resetDb()`), so specs never depend on
each other's data. The `e2e` npm scripts unset `ELECTRON_RUN_AS_NODE`, which editor terminals (VS Code)
set and which stops Cypress's Electron from starting ("bad option: --no-sandbox").

## Helpers

Specs reach the database only through three guarded Artisan commands (`tests/E2e`), registered only
when `APP_ENV` is `e2e` or `testing`, and refusing to run unless the database name ends in `_e2e` or
`_test`:

| Cypress | Artisan | Does |
| --- | --- | --- |
| `cy.resetDb()` | `e2e:reset` | `migrate:fresh --seed`, empty cache, realtime streams, e2e files and `e2e.log` |
| `cy.make(fixture, attrs)` | `e2e:make` | a named fixture from `tests/E2e/E2eFixtures.php` (admins, riders, drivers with documents, zones, drivers (with vehicles, bank account, trips), fare rules and fees, reset links, session ageing, status changes, `dispatch_trip_event` (real trip and support events), `sent_pushes` (the pushes the log driver wrote, by token), …) through the real models |
| `cy.sql(query, bindings)` | `e2e:sql` | one read-only `SELECT` (READ ONLY transaction) to assert what reached the database |

Specs that need a different `.env` (the IP allowlist in `p9-t3-rate-limits-security.cy.ts`) start an extra app
server with `cy.task('e2e:server:start', { port, env })` (ports 8002-8009 only, always `APP_ENV=e2e`) and stop it with
`cy.task('e2e:server:stop')`; Cypress also stops any left over when the run ends.

`two_factor` on the `admin` fixture gives the account a confirmed authenticator setup without spending a code; `two_factor_code`
(`{email, steps}`) returns the code the app would show (use steps 1, then 0 for a second sign-in), `two_factor_recovery_codes` the unused recovery codes.
The e2e environment does not force two-factor for `super_admin` and `finance` (the default is off in `testing` and `e2e`, so fixtures sign in with a password
alone); the P3-T2 spec starts a second server with `ADMIN_2FA_REQUIRED_ROLES=super_admin,finance` for the rule itself.

Other commands: `cy.loginAs(email)` (real login form, cached per spec with `cy.session`),
`cy.api(method, path, body, token)` (JSON, never fails on status), `cy.adminRequest(method, path, body)`
(same-session Inertia request with the CSRF token, for direct-URL permission checks) and
`cy.pageProps(path)` (the Inertia props the server renders: errors, flash, shared props).
Every test fails if the app logs a console error.

The fixture admins sign in with the password `Cypress-Pass!2026x` (`E2eFixtures::PASSWORD`).

## What a solid spec checks

One `it` per acceptance criterion plus the unhappy paths; outcomes after a reload and in the database
(and the audit log); permissions both in the UI and by direct request (403); validation messages next to
the field with nothing saved; one effect for a double-click; no fixed waits, `.only` or `.skip`. The full
list is in `.claude/commands/task.md` (7b) and is audited by `/review-task`.
