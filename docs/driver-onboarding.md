# Driver onboarding API (P4-T4)

The driver app's sign-up and document flow. Signed-in driver app only (Sanctum token with the `driver` ability, never a session); every
record is looked up through the signed-in driver, so another driver's id is a `404`. Code: `App\Services\Drivers\DriverOnboardingService`,
`App\Services\DriverDocuments\DriverDocumentUploader` (the file checks and storage).

| Endpoint | |
| --- | --- |
| `POST /api/v1/driver/application` | The person becomes a driver applicant: creates the `driver_profiles` row (`verification_status: pending`) and sets `is_driver`. `201`; applying again is `200` and changes nothing. The admin sees it in the Drivers list (pending filter) and the sidebar badge |
| `PUT /api/v1/driver/profile` | `{license_number (4–32), license_expiry (Y-m-d, after today), home_zone_id? (an active zone)}` → the application block (`verification_status`, `license.number_last4`, `license.expiry`, `home_zone_id`). A licence number another driver holds is `422` without saying who |
| `GET/POST /api/v1/driver/vehicles`, `PATCH/DELETE …/{id}` | `{make, model, year (1980–next year), color, plate_number (2–20, stored upper case without spaces), vehicle_class}`. A new vehicle is `pending_review` and never primary. A plate belongs to one vehicle on the platform (`422` otherwise); sending the same vehicle again is `200` with the one already added. At most `DRIVER_MAX_VEHICLES` (5, else `409 vehicle_limit_reached`). `DELETE` is a soft delete, refused while the vehicle is on a trip (`409 vehicle_on_trip`); its papers stop counting |
| `POST /api/v1/driver/documents` | Multipart: `type`, `file`, `vehicle_id?` (vehicle papers: one of the driver's own), `expires_at?` (today or later), `document_number?`. **jpg, png or pdf, at most 10 MB** (`DRIVER_DOCUMENT_MAX_KB`), judged by the file's **content** (MIME sniffing), never its name or the type the client claims; stored on the private disk under a random name; `201` with the document. It **replaces the driver's earlier pending or rejected document of the same type (and vehicle)**; an approved one stays until the new one is approved |
| `GET /api/v1/driver/documents` | `{data: [{id, type, vehicle_id, status, rejection_reason, expires_at, uploaded_at, reviewed_at}], required: [{type, status: missing\|pending\|rejected\|expired, reason}], all_submitted}`. `all_submitted` is true once every required type has been uploaded and none is rejected or expired: the rest is waiting for review. The file, its path and the document number are never returned |
| `GET/POST /api/v1/driver/bank-accounts`, `DELETE …/{id}` | `{account_holder, bank_name, account_number (8–34 letters and digits, spaces allowed), swift?}`. Stored encrypted; only `account_last4` comes back. The first account is the default; the same number again is `200` with the one already added; at most `DRIVER_MAX_BANK_ACCOUNTS` (5, else `409 bank_account_limit_reached`). Removing the default makes the newest remaining one the default. An admin verifies an account before money goes to it (P6-T8) |
| `GET /api/v1/me` | Carries `driver.verification_status` and, under `driver.documents`, the required documents not yet approved with where each stands (missing, pending, rejected with the reviewer's reason, expired) |

Without an application every call except `POST /application` is `409` with `code: driver_application_required`. A suspended account is stopped by the
`mobile` gate (`403`), like every other call.

## Changes to something already approved

Every change to an approved item sends exactly that item back to review, and nothing else:

* a changed licence number or expiry → the driver's approved `license` document(s) go back to pending, and the verification status is recomputed (a changed home zone alone does nothing);
* a changed approved vehicle → that vehicle goes back to `pending_review` (and stops being the primary one), and its own approved papers go back to pending;
* the driver's other documents and vehicles keep their status.

Retries are safe without a key: applying, adding the same vehicle or payout account, and uploading a document again each leave one effect.

## Audit

Each change is logged with the driver as actor: `driver.application.submitted`, `driver.self.profile_updated` (the licence number only as "changed"), `driver.self.vehicle_added`, `…vehicle_updated`, `…vehicle_removed`, `driver.self.document_uploaded` (type only), `driver.self.bank_account_added` / `…removed` (last four only).

## Server requirements

The 10 MB limit is checked by the app, but PHP must let the upload through first: set `upload_max_filesize` and `post_max_size` to at least 12M in `php.ini` (the CLI default is 2M, which stops a larger upload before the app sees it). The files live on `DRIVER_DOCUMENTS_DISK` (private; S3-compatible in production) and are only served through signed, expiring links to admins. There is no antivirus engine in the stack: files are checked by content type and size only.

Rows of a replaced document, or of a removed vehicle, are kept (soft deleted) for the audit trail; their files are deleted.
