<?php

namespace Tests\E2e;

use App\Enums\AdminRole;
use App\Enums\AppClient;
use App\Enums\DriverDocumentType;
use App\Enums\TripPaymentMethod;
use App\Enums\TripStatus;
use App\Enums\UserStatus;
use App\Enums\VehicleClass;
use App\Events\Realtime\SupportTicketUpdated;
use App\Events\Realtime\TripOffer;
use App\Events\Realtime\TripStatusChanged;
use App\Exceptions\PaymentException;
use App\Http\Middleware\EnforceAdminIdleTimeout;
use App\Models\CommissionRule;
use App\Models\DriverBankAccount;
use App\Models\DriverDocument;
use App\Models\DriverLocationPing;
use App\Models\DriverProfile;
use App\Models\FareFee;
use App\Models\FareRule;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Zone;
use App\Realtime\RealtimeEventType;
use App\Realtime\StreamClient;
use App\Services\Audit\AuditLogger;
use App\Services\Commission\TripCommission;
use App\Services\DriverDocuments\DriverDocumentReviewer;
use App\Services\Drivers\DriverLocationStore;
use App\Services\Drivers\StaleDriverSweeper;
use App\Services\Fares\FareCalculator;
use App\Services\MobileAuth\MobileSessionService;
use App\Services\Payments\SavedCards;
use App\Services\Payments\TripCardPayments;
use App\Services\Push\LogPushSender;
use App\Services\Trips\TripService;
use App\Support\BlindIndex;
use App\Support\E164PhoneNumber;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Fortify;
use Laravel\Sanctum\PersonalAccessToken;
use PragmaRX\Google2FA\Google2FA;

/**
 * The data Cypress specs set up or change, one named fixture each. Only what is listed in run()
 * can be called; everything goes through the real models (observers, casts, events), never raw
 * inserts, so the specs exercise the same rules as production.
 */
final class E2eFixtures
{
    public const PASSWORD = 'Cypress-Pass!2026x';

    /** Uploaded e2e files live here on the documents disk; e2e:reset removes the folder. */
    public const FILE_DIRECTORY = 'e2e';

    /**
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>|list<mixed>
     */
    public function run(string $fixture, array $a): array
    {
        return match ($fixture) {
            'admin' => $this->admin($a),
            'two_factor_code' => $this->twoFactorCode($a),
            'two_factor_recovery_codes' => $this->twoFactorRecoveryCodes($a),
            'rider' => $this->rider($a),
            'riders' => $this->riders($a),
            'system_audit_entry' => $this->systemAuditEntry($a),
            'driver_with_documents' => $this->driverWithDocuments($a),
            'driver' => $this->driver($a),
            'signed_document_url' => $this->signedDocumentUrl($a),
            'zone' => $this->zone($a),
            'fare_rule' => $this->fareRule($a),
            'fare_fee' => $this->fareFee($a),
            'commission_rule' => $this->commissionRule($a),
            'completed_trip' => $this->completedTrip($a),
            'expire_fare_quote' => $this->expireFareQuote($a),
            'password_reset_link' => $this->passwordResetLink($a),
            'age_password_reset' => $this->agePasswordReset($a),
            'age_admin_session' => $this->ageAdminSession($a),
            'set_user_status' => $this->setUserStatus($a),
            'pending_mobile_session' => $this->pendingMobileSession($a),
            'expire_otp' => $this->expireOtp($a),
            'expire_access_token' => $this->expireAccessToken($a),
            'stream' => $this->stream($a),
            'sent_pushes' => $this->sentPushes($a),
            'support_ticket' => $this->supportTicket($a),
            'activate_vehicle' => $this->activateVehicle($a),
            'approve_documents' => $this->approveDocuments($a),
            'logged_mail' => $this->loggedMail($a),
            'dispatch_trip_event' => $this->dispatchTripEvent($a),
            'assign_driver' => $this->assignDriver($a),
            'add_pings' => $this->addPings($a),
            'age_trip' => $this->ageTrip($a),
            'driver_location_state' => $this->driverLocationState($a),
            'age_driver_ping' => $this->ageDriverPing($a),
            'sweep_stale_drivers' => $this->sweepStaleDrivers(),
            'clear_rate_limits' => $this->clearRateLimits(),
            'card_trip' => $this->cardTrip($a),
            'failed_card_trip' => $this->failedCardTrip($a),
            'hold_card_trip' => $this->holdCardTrip($a),
            default => throw new InvalidArgumentException("Unknown e2e fixture \"{$fixture}\"."),
        };
    }

    /**
     * An admin who can sign in with PASSWORD. {email, role, name?, phone?, timezone?, locale?, two_factor?}
     * With two_factor: true the account has a confirmed authenticator setup (the code comes from the two_factor_code fixture).
     */
    private function admin(array $a): array
    {
        $role = AdminRole::from($a['role'] ?? AdminRole::SuperAdmin->value);
        $factory = User::factory()->admin($role);
        $user = (isset($a['phone']) ? $factory->withPhone($a['phone']) : $factory)->create([
            'name' => $a['name'] ?? 'Cypress '.Str::headline($role->value),
            'email' => $a['email'],
            'password' => self::PASSWORD,
            'timezone' => $a['timezone'] ?? 'Asia/Yerevan',
            'locale' => $a['locale'] ?? 'en',
        ]);

        if ($a['two_factor'] ?? false) {
            // Activated without entering a code, so no code is spent: the spec can use the codes of the current and the next 30 seconds.
            app(EnableTwoFactorAuthentication::class)($user);
            $user->forceFill(['two_factor_confirmed_at' => now()])->save();
        }

        return ['id' => $user->id, 'email' => $user->email, 'name' => $user->name, 'role' => $role->value, 'password' => self::PASSWORD, 'locale' => $user->locale];
    }

    /**
     * The code the account's authenticator app shows now. {email, steps?, at?}: steps = 30-second steps from now, or `at` = an
     * absolute step number (returned as `step`). Each code works once. A sign-in uses steps 1 (valid whichever side of a
     * 30-second boundary it is typed on). A second sign-in a few seconds later asks for the step *before* that one (`at` =
     * step - 1): still inside the accepted window, and a different code whatever happened to the clock in between.
     *
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>
     */
    private function twoFactorCode(array $a): array
    {
        $google = app(Google2FA::class);
        $step = isset($a['at']) ? (int) $a['at'] : $google->getTimestamp() + (int) ($a['steps'] ?? 0);

        return ['code' => $this->codeAt($this->findUser($a), $step), 'step' => $step];
    }

    /**
     * The account's unused recovery codes (the app itself shows them only once).
     *
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>
     */
    private function twoFactorRecoveryCodes(array $a): array
    {
        return ['codes' => $this->findUser($a)->recoveryCodes()];
    }

    private function codeAt(User $user, int $step): string
    {
        return app(Google2FA::class)->oathTotp(Fortify::currentEncrypter()->decrypt($user->fresh()->two_factor_secret), $step);
    }

    /**
     * A rider account. {name, email?, phone?, locale?, status?, driver?, deleted?, unnamed?}
     */
    private function rider(array $a): array
    {
        $factory = User::factory()->rider()->state(array_filter([
            'name' => $a['name'] ?? null,
            'created_at' => $a['registered_at'] ?? null,
            'email' => $a['email'] ?? null,
            'locale' => $a['locale'] ?? 'en',
            'is_driver' => $a['driver'] ?? null,
        ], fn ($value) => $value !== null));

        $user = isset($a['phone']) ? $factory->withPhone($a['phone'])->create() : $factory->create();

        if (isset($a['status'])) {
            $user->forceFill(['status' => UserStatus::from($a['status']), 'suspension_reason' => $a['status'] === 'suspended' ? 'Set up by e2e' : null])->save();
        }

        if ($a['unnamed'] ?? false) {
            $user->forceFill(['name' => null])->save();
        }

        if ($a['deleted'] ?? false) {
            $user->delete();
        }

        return ['id' => $user->id, 'code' => sprintf('R-%05d', $user->id), 'name' => $user->name, 'email' => $user->email, 'phone' => $user->phone];
    }

    /**
     * An audit entry made by the system, with no admin behind it. {action, rider_id}
     */
    private function systemAuditEntry(array $a): array
    {
        $entry = app(AuditLogger::class)->record(null, $a['action'], isset($a['rider_id']) ? User::query()->findOrFail($a['rider_id']) : null);

        return ['id' => $entry->id];
    }

    /**
     * Many riders at once: "{prefix} 01" … with phones +3749188xxxx. {count, prefix, registered_at?, phone_offset?} Call twice only with different prefixes and phone offsets.
     *
     * @return list<array<string, mixed>>
     */
    private function riders(array $a): array
    {
        $riders = [];
        for ($i = 1; $i <= (int) $a['count']; $i++) {
            $user = User::factory()->rider()->withPhone(sprintf('+3749188%04d', ((int) ($a['phone_offset'] ?? 0)) + $i))->create(array_filter([
                'name' => sprintf('%s %02d', $a['prefix'], $i),
                'email' => sprintf('%s.%02d@riders.test', Str::slug($a['prefix']), $i),
                'created_at' => $a['registered_at'] ?? null,
            ]));
            $riders[] = ['id' => $user->id, 'name' => $user->name];
        }

        return $riders;
    }

    /**
     * A driver with pending documents whose files really exist on the private disk.
     * {name, types?: list<string>, phone?}
     */
    private function driverWithDocuments(array $a): array
    {
        $user = User::factory()->driver()->state(['name' => $a['name'] ?? 'Cypress Driver'])->withPhone($a['phone'] ?? null)->create();
        $profile = DriverProfile::factory()->create(['user_id' => $user->id]);
        $types = $a['types'] ?? ['license', 'id_card', 'vehicle_registration', 'insurance'];
        $disk = Storage::disk(config('taxikosmos.documents.disk'));

        $documents = [];
        foreach ($types as $type) {
            $path = self::FILE_DIRECTORY.'/'.Str::uuid().'.pdf';
            $disk->put($path, "%PDF-1.4\n% e2e {$type}\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");

            $document = DriverDocument::factory()->ofType(DriverDocumentType::from($type))->create([
                'driver_id' => $profile->id,
                'file_path' => $path,
                'mime_type' => 'application/pdf',
                'size_bytes' => $disk->size($path),
                'expires_at' => now()->addYear()->toDateString(),
            ]);
            $documents[] = ['id' => $document->id, 'type' => $type];
        }

        return ['driver_id' => $profile->id, 'user_id' => $user->id, 'name' => $user->name, 'license_number' => $profile->license_number, 'phone' => $user->phone, 'documents' => $documents];
    }

    /**
     * A driver for the admin driver pages. {name?, phone?, email?, license_number?, license_expiry?, zone_code?, verification_status?, availability?,
     * suspended?, rating?, vehicles?: [{make, model, plate, class?, status?, primary?}], bank_account?: {bank_name, account_number},
     * completed_trips?: [earningsMinorUnits...], live_trip?: matched|arrived|in_progress, document_expires?}
     *
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>
     */
    private function driver(array $a): array
    {
        $user = User::factory()->driver()->withPhone($a['phone'] ?? null)->create([
            'name' => $a['name'] ?? 'Cypress Driver',
            'email' => $a['email'] ?? null,
            ...(isset($a['locale']) ? ['locale' => $a['locale']] : []),
            ...(($a['suspended'] ?? false) ? ['status' => UserStatus::Suspended, 'suspension_reason' => 'Suspended by fixture'] : []),
        ]);
        $zone = isset($a['zone_code']) ? Zone::query()->where('code', $a['zone_code'])->firstOrFail() : null;
        $profile = DriverProfile::factory()->create(array_filter([
            'user_id' => $user->id,
            'license_number' => $a['license_number'] ?? null,
            'license_expiry' => $a['license_expiry'] ?? null,
            'home_zone_id' => $zone?->id,
            'rating_avg' => $a['rating'] ?? null,
        ], fn ($value) => $value !== null));
        $profile->forceFill(array_filter(['verification_status' => $a['verification_status'] ?? null, 'availability' => $a['availability'] ?? null], fn ($value) => $value !== null))->save();

        ($a['deleted'] ?? false) && $user->delete();

        foreach ($a['vehicles'] ?? [] as $vehicle) {
            Vehicle::factory()->for($profile, 'driver')->create(['make' => $vehicle['make'], 'model' => $vehicle['model'], 'plate_number' => $vehicle['plate'], 'vehicle_class' => $vehicle['class'] ?? 'economy', 'status' => $vehicle['status'] ?? 'active', 'is_primary' => $vehicle['primary'] ?? false]);
        }

        if (isset($a['bank_account'])) {
            DriverBankAccount::factory()->default()->for($profile, 'driver')->create($a['bank_account']);
        }

        if (isset($a['document_expires'])) {
            DriverDocument::factory()->for($profile, 'driver')->expiring($a['document_expires'])->create();
        }

        $tripZone = $zone ?? Zone::factory()->create();
        foreach ($a['completed_trips'] ?? [] as $earnings) {
            Trip::factory()->completed()->create(['driver_id' => $profile->id, 'zone_id' => $tripZone->id, 'driver_earnings_amount' => $earnings, 'commission_amount' => 1000, 'fare_amount' => $earnings + 1000]);
        }

        if (isset($a['live_trip'])) {
            $trip = Trip::factory()->create(['driver_id' => $profile->id, 'zone_id' => $tripZone->id]);
            $trip->forceFill(['status' => $a['live_trip'], 'matched_at' => now()])->saveQuietly();
        }

        return ['driver_id' => $profile->id, 'user_id' => $user->id, 'name' => $user->name, 'phone' => $user->phone, 'code' => sprintf('D-%04d', $profile->id)];
    }

    /**
     * A signed link to a document file that expires `minutes` from now (negative = already expired).
     * {driver_id, document_id, minutes}
     */
    private function signedDocumentUrl(array $a): array
    {
        return ['path' => URL::temporarySignedRoute(
            'admin.drivers.documents.file',
            now()->addMinutes((int) $a['minutes']),
            ['driver' => $a['driver_id'], 'document' => $a['document_id']],
            absolute: false,
        )];
    }

    /**
     * A zone: a square of ±half degrees around (lat, lng). {name, code, lat?, lng?, half?, priority?, active?}
     */
    private function zone(array $a): array
    {
        $zone = Zone::factory()->around($a['lat'] ?? 40.18, $a['lng'] ?? 44.51, $a['half'] ?? 0.05)->create([
            'name' => $a['name'],
            'code' => $a['code'],
            'priority' => $a['priority'] ?? 0,
            'is_active' => $a['active'] ?? true,
        ]);

        return ['id' => $zone->id, 'name' => $zone->name, 'code' => $zone->code];
    }

    /**
     * One version of a class's prices, in force since a day ago (the factory defaults: base 500, 150/km, 20/min, minimum 800).
     * {zone_code, vehicle_class?, ...any FareRule column}
     */
    private function fareRule(array $a): array
    {
        $zone = Zone::query()->where('code', $a['zone_code'])->firstOrFail();
        $rule = FareRule::factory()->forZone($zone)->create(array_diff_key($a, ['zone_code' => 1]));

        return ['id' => $rule->id, 'zone_id' => $zone->id];
    }

    /**
     * One version of a zone's commission (the factory defaults: 20 % for every class, in force since a day ago).
     * {zone_code, ...any CommissionRule column}
     */
    private function commissionRule(array $a): array
    {
        $zone = Zone::query()->where('code', $a['zone_code'])->firstOrFail();
        $rule = CommissionRule::factory()->forZone($zone)->create(array_diff_key($a, ['zone_code' => 1]));

        return ['id' => $rule->id, 'zone_id' => $zone->id];
    }

    /**
     * A trip completed with a 1,490 AMD fare (5 km, 12 min: 500 + 750 + 240), its commission taken with the
     * rule in force now, as the trip lifecycle does. {zone_code}
     */
    private function completedTrip(array $a): array
    {
        $zone = Zone::query()->where('code', $a['zone_code'])->firstOrFail();
        $fare = (new FareCalculator)->calculate(new FareRule(['base_fare' => 500, 'per_km' => 150, 'per_minute' => 20, 'min_fare' => 0, 'waiting_free_minutes' => 3, 'waiting_per_minute' => 30, 'cancellation_fee' => 0, 'cancellation_free_seconds' => 0, 'booking_fee' => 0, 'tax_rate_bps' => 0, 'tax_inclusive' => false, 'currency' => $zone->currency]), 5000, 720);
        $trip = Trip::factory()->inProgress()->create(['zone_id' => $zone->id]);
        $trip->transitionTo(TripStatus::Completed, attributes: [
            ...app(TripCommission::class)->snapshot($zone, VehicleClass::Economy, $fare),
            'fare_amount' => $fare->total, 'fare_breakdown' => $fare->toArray(),
        ]);

        return ['id' => $trip->id];
    }

    /**
     * A fixed fee for a drawn area around a point. {lat, lng, half?, name?, amount?, applies_to?}
     */
    private function fareFee(array $a): array
    {
        $fee = FareFee::factory()->area($a['lat'], $a['lng'], $a['half'] ?? 0.005)->create(array_filter([
            'name' => $a['name'] ?? null, 'amount' => $a['amount'] ?? null, 'applies_to' => $a['applies_to'] ?? null,
        ], fn ($value) => $value !== null));

        return ['id' => $fee->id];
    }

    /**
     * Make a rider's quote expired, as if ten minutes had passed. {quote_id}
     */
    private function expireFareQuote(array $a): array
    {
        return ['expired' => DB::table('fare_quotes')->where('id', $a['quote_id'])->update(['expires_at' => now()->subSecond()])];
    }

    /**
     * What the reset email would link to (the email itself goes to the log driver). {email}
     */
    private function passwordResetLink(array $a): array
    {
        $user = User::query()->where('email', $a['email'])->firstOrFail();
        $token = Password::broker('admins')->createToken($user);

        return ['path' => '/reset-password/'.$token.'?email='.urlencode($user->email)];
    }

    /**
     * Pretend the reset link was issued `minutes` ago. {email, minutes}
     */
    private function agePasswordReset(array $a): array
    {
        $count = DB::table('password_reset_tokens')->where('email', $a['email'])->update(['created_at' => now()->subMinutes((int) $a['minutes'])]);

        return ['aged' => $count];
    }

    /**
     * Pretend the admin's last request was `minutes` ago. {email, minutes}
     */
    private function ageAdminSession(array $a): array
    {
        $user = User::query()->where('email', $a['email'])->firstOrFail();
        $sessions = DB::table('sessions')->where('user_id', $user->id)->get();

        // The payload is base64 of the serialised session (JSON or PHP, per session.serialization).
        $json = config('session.serialization') === 'json';

        foreach ($sessions as $session) {
            $raw = base64_decode($session->payload);
            $data = $json ? json_decode($raw, true, flags: JSON_THROW_ON_ERROR) : unserialize($raw, ['allowed_classes' => false]);
            $data[EnforceAdminIdleTimeout::SESSION_KEY] = now()->subMinutes((int) $a['minutes'])->getTimestamp();
            DB::table('sessions')->where('id', $session->id)->update(['payload' => base64_encode($json ? json_encode($data) : serialize($data))]);
        }

        return ['sessions' => $sessions->count()];
    }

    /**
     * Change an account's status through the model, so observers (token revocation, events) run.
     * {email|id|phone, status}
     */
    private function setUserStatus(array $a): array
    {
        $user = $this->findUser($a);

        if ($a['silent'] ?? false) {
            // Straight to the row, past the observer that ends the sessions: an account blocked while its token is still open.
            User::query()->whereKey($user->getKey())->update(['status' => UserStatus::from($a['status'])->value]);
            $user->refresh();
        } else {
            $user->forceFill(['status' => UserStatus::from($a['status'])])->save();
        }

        return ['id' => $user->id, 'status' => $user->status->value];
    }

    /**
     * A social-style account with no phone and its limited (pending-phone) mobile session.
     * {name, email, app?, device_id?}
     */
    private function pendingMobileSession(array $a): array
    {
        $user = User::factory()->rider()->create(['name' => $a['name'], 'email' => $a['email']]);
        $session = app(MobileSessionService::class)->issue($user, AppClient::from($a['app'] ?? 'rider'), $a['device_id'] ?? 'cypress-pending-device');

        return ['user_id' => $user->id, 'access_token' => $session->accessToken, 'refresh_token' => $session->refreshToken, 'needs_phone' => $session->needsPhone];
    }

    /**
     * Make the phone's live one-time code expired. {phone}
     */
    private function expireOtp(array $a): array
    {
        $count = DB::table('otp_codes')
            ->where('phone_hash', app(BlindIndex::class)->hash(E164PhoneNumber::normalize($a['phone'])))
            ->whereNull('consumed_at')
            ->update(['expires_at' => now()->subSecond()]);

        return ['expired' => $count];
    }

    /**
     * Make a Sanctum access token expired. {token}
     */
    private function expireAccessToken(array $a): array
    {
        $token = PersonalAccessToken::findToken($a['token']) ?? throw new InvalidArgumentException('Unknown token.');
        $token->forceFill(['expires_at' => now()->subMinute()])->save();

        return ['expired' => true];
    }

    /**
     * The entries of one realtime stream (newest last), payload decoded. {type}
     */
    private function stream(array $a): array
    {
        $entries = app(StreamClient::class)->raw(['XRANGE', RealtimeEventType::from($a['type'])->streamKey(), '-', '+']) ?: [];

        return array_map(function (array $entry) {
            [$id, $flat] = $entry;
            $fields = [];
            for ($i = 0; $i + 1 < count($flat); $i += 2) {
                $fields[$flat[$i]] = $flat[$i + 1];
            }

            return ['id' => $id, 'fields' => $fields];
        }, $entries);
    }

    /**
     * The pushes the log driver wrote instead of sending (PUSH_DRIVER=log), newest last, for the phone with this push token when
     * given. Read-only. {push_token?}
     */
    private function sentPushes(array $a): array
    {
        $path = (string) config('logging.channels.single.path');
        $fingerprint = isset($a['push_token']) ? LogPushSender::fingerprint($a['push_token']) : null;
        $pushes = [];

        foreach (is_file($path) ? file($path, FILE_IGNORE_NEW_LINES) : [] as $line) {
            if (preg_match('/Push notification \(not sent[^)]*\) (\{.*\})(?: \[\])? *$/', $line, $m) !== 1) {
                continue;
            }

            $context = json_decode($m[1], true);
            if ($fingerprint === null || $context['token'] === $fingerprint) {
                $pushes[] = $context;
            }
        }

        return $pushes;
    }

    /**
     * A support ticket with its first message from the requester. {requester_id, type?: rider|driver, subject?, message?, category?, priority?,
     * status?, trip_id?, with_trip?, assignee_email?}. Returns {id, code, trip_id, trip_code}.
     */
    private function supportTicket(array $a): array
    {
        $trip = ($a['with_trip'] ?? false) ? Trip::factory()->create(['rider_id' => (int) $a['requester_id']]) : null;
        $a['trip_id'] = $trip?->id ?? ($a['trip_id'] ?? null);
        $ticket = SupportTicket::factory()->create(array_filter([
            'requester_id' => (int) $a['requester_id'],
            'requester_type' => AppClient::from($a['type'] ?? 'rider'),
            'subject' => $a['subject'] ?? 'Cypress ticket',
            'category' => $a['category'] ?? 'other',
            'priority' => $a['priority'] ?? 'normal',
            'status' => $a['status'] ?? 'open',
            'trip_id' => $a['trip_id'] ?? null,
            'assigned_to' => isset($a['assignee_email']) ? User::query()->where('email', $a['assignee_email'])->value('id') : null,
            'resolved_at' => in_array($a['status'] ?? 'open', ['resolved', 'closed'], true) ? now() : null,
            'resolution' => in_array($a['status'] ?? 'open', ['resolved', 'closed'], true) ? 'Fixed.' : null,
        ], fn ($value) => $value !== null));
        SupportTicketMessage::factory()->create(['ticket_id' => $ticket->id, 'author_id' => (int) $a['requester_id'], 'body' => $a['message'] ?? 'Cypress message']);

        return ['id' => $ticket->id, 'code' => $ticket->refresh()->code, 'trip_id' => $trip?->id, 'trip_code' => $trip?->public_code];
    }

    /**
     * An admin approved this vehicle (it is active), as the driver's app cannot do. {vehicle_id, primary?}
     */
    private function activateVehicle(array $a): array
    {
        $vehicle = Vehicle::query()->findOrFail((int) $a['vehicle_id']);
        $vehicle->forceFill(['status' => 'active'])->save();
        ($a['primary'] ?? false) && $vehicle->markAsPrimary();

        return ['id' => $vehicle->id, 'status' => $vehicle->refresh()->status->value];
    }

    /**
     * An admin approved the driver's pending documents. {driver_user_id, types?: [license, ...], reviewer_email}
     */
    private function approveDocuments(array $a): array
    {
        $reviewer = User::query()->where('email', $a['reviewer_email'])->firstOrFail();
        $driver = DriverProfile::query()->where('user_id', (int) $a['driver_user_id'])->firstOrFail();
        $count = 0;

        foreach ($driver->documents()->where('status', 'pending')->when(isset($a['types']), fn ($q) => $q->whereIn('type', $a['types']))->get() as $document) {
            app(DriverDocumentReviewer::class)->approve($document, $reviewer);
            $count++;
        }

        return ['approved' => $count];
    }

    /**
     * The mails the log driver wrote instead of sending (MAIL_MAILER=log): recipient and subject, oldest first. Read-only. {subject_contains?}
     */
    private function loggedMail(array $a): array
    {
        $path = (string) config('logging.channels.single.path');
        $mails = [];
        $to = null;

        foreach (is_file($path) ? file($path, FILE_IGNORE_NEW_LINES) : [] as $line) {
            $line = rtrim($line, "\r");

            if (str_starts_with($line, 'To: ')) {
                $to = substr($line, 4);
            } elseif (str_starts_with($line, 'Subject: ') && $to !== null) {
                $subject = substr($line, 9);
                if (! isset($a['subject_contains']) || str_contains($subject, $a['subject_contains'])) {
                    $mails[] = ['to' => $to, 'subject' => $subject];
                }
                $to = null;
            }
        }

        return $mails;
    }

    /**
     * Raise a trip or support event the way the trip and support tasks will, so the pushes they trigger can be checked.
     * {kind: offer|status|reply, ...}
     */
    private function dispatchTripEvent(array $a): array
    {
        match ($a['kind']) {
            'offer' => TripOffer::dispatch((int) $a['offer_id'], (int) $a['trip_id'], (int) $a['driver_user_id'], now()->addSeconds(15), 'A', 'B', 2500, 'AMD', 'economy'),
            'status' => TripStatusChanged::dispatch((int) $a['trip_id'], 'TK-'.$a['trip_id'], 'requested', $a['status'], (int) $a['rider_user_id'], isset($a['driver_user_id']) ? (int) $a['driver_user_id'] : null),
            'reply' => SupportTicketUpdated::dispatch((int) $a['ticket_id'], 'TKT-'.$a['ticket_id'], 'open', (int) $a['requester_user_id'], 'reply'),
        };

        return ['dispatched' => $a['kind']];
    }

    /**
     * A driver accepts a trip, as dispatch (P7-T7) will do it: the same TripService::assignDriver the offer-accept endpoint will call.
     * {trip_id, driver_id}
     */
    private function assignDriver(array $a): array
    {
        $trip = app(TripService::class)->assignDriver(Trip::query()->findOrFail((int) $a['trip_id']), DriverProfile::query()->findOrFail((int) $a['driver_id']));

        return ['id' => $trip->id, 'status' => $trip->status->value, 'driver_id' => $trip->driver_id];
    }

    /**
     * Pretend the trip's steps happened `seconds` earlier (a ride cannot be waited for in a spec). {trip_id, seconds}
     */
    private function ageTrip(array $a): array
    {
        $seconds = (int) $a['seconds'];
        $aged = DB::table('trips')->where('id', (int) $a['trip_id'])->update([
            'matched_at' => DB::raw("matched_at - interval '{$seconds} seconds'"),
            'arrived_at' => DB::raw("arrived_at - interval '{$seconds} seconds'"),
            'started_at' => DB::raw("started_at - interval '{$seconds} seconds'"),
        ]);

        return ['aged' => $aged];
    }

    /**
     * Pings of a driver in MongoDB history, as the app's location calls leave them. {driver_user_id, driver_id, points: [[lat, lng, seconds_ago]]}
     */
    private function addPings(array $a): array
    {
        foreach ($a['points'] as [$lat, $lng, $secondsAgo]) {
            DriverLocationPing::query()->create([
                'driver_user_id' => (int) $a['driver_user_id'], 'driver_profile_id' => (int) $a['driver_id'],
                'location' => ['type' => 'Point', 'coordinates' => [(float) $lng, (float) $lat]], 'source' => 'rest', 'recorded_at' => now()->subSeconds((int) $secondsAgo),
            ]);
        }

        return ['added' => count($a['points'])];
    }

    /**
     * Where Redis and MongoDB say a driver is: position [lng, lat] and ping time from the geo / last-seen sets, and the history
     * pings kept in MongoDB (newest first). Read-only. {driver_user_id}
     */
    private function driverLocationState(array $a): array
    {
        $id = (string) (int) $a['driver_user_id'];
        $redis = app(StreamClient::class);
        $store = app(DriverLocationStore::class);
        $position = $redis->raw(['GEOPOS', $store->locationsKey(), $id])[0] ?? null;
        $score = $redis->raw(['ZSCORE', $store->lastSeenKey(), $id]);

        return [
            'position' => $position ? [(float) $position[0], (float) $position[1]] : null,
            'last_seen' => ($score === false || $score === null) ? null : (int) $score,
            'pings' => DriverLocationPing::query()->where('driver_user_id', (int) $id)->orderByDesc('recorded_at')->get()
                ->map(fn ($p) => ['coordinates' => array_map('floatval', (array) $p->location['coordinates']), 'heading' => $p->heading, 'speed' => $p->speed, 'accuracy' => $p->accuracy, 'source' => $p->source, 'recorded_at' => $p->recorded_at->utc()->format('Y-m-d\TH:i:s.v\Z')])->all(),
        ];
    }

    /**
     * Make a driver's last ping, and the time they went online, older: as if the app had gone quiet that long ago.
     * {driver_user_id, seconds}
     */
    private function ageDriverPing(array $a): array
    {
        $at = now()->subSeconds((int) $a['seconds']);
        app(StreamClient::class)->raw(['ZADD', app(DriverLocationStore::class)->lastSeenKey(), (string) $at->getTimestamp(), (string) (int) $a['driver_user_id']]);
        // A driver who went online moments ago is given time to send a first ping, so "quiet for N minutes" needs this too.
        DriverProfile::query()->where('user_id', (int) $a['driver_user_id'])->update(['last_online_at' => $at]);

        return ['last_seen' => $at->getTimestamp()];
    }

    /**
     * Run the scheduled sweep once (drivers:mark-stale-offline).
     */
    private function sweepStaleDrivers(): array
    {
        return app(StaleDriverSweeper::class)->sweep();
    }

    /**
     * Forget every rate-limit counter (the e2e cache holds nothing else that a test relies on).
     */
    /**
     * A card trip of the rider, paid with their saved card, in progress. {rider_id, payment_method_id}
     */
    private function cardTrip(array $a): array
    {
        $trip = Trip::factory()->inProgress()->create(['rider_id' => $a['rider_id'], 'payment_method' => TripPaymentMethod::Card, 'payment_method_id' => $a['payment_method_id']]);

        return ['id' => $trip->id, 'public_code' => $trip->public_code];
    }

    /**
     * A completed card trip whose final capture was declined, through the real card flow and the fake gateway: hold, ride,
     * capture fails, payment_failed. The rider gets a tok_capture_fails card for it. {rider_id, fare?}
     */
    private function failedCardTrip(array $a): array
    {
        $rider = User::query()->findOrFail($a['rider_id']);
        $card = app(SavedCards::class)->add($rider, 'tok_capture_fails', false)['card'];
        $trip = Trip::factory()->create(['rider_id' => $rider->id, 'payment_method' => TripPaymentMethod::Card, 'payment_method_id' => $card->id]);
        $payments = app(TripCardPayments::class);
        $payments->hold($trip);

        $fare = (int) ($a['fare'] ?? 150000);
        $trip->transitionTo(TripStatus::Matched, attributes: ['driver_id' => DriverProfile::factory()->approved()->create()->id]);
        $trip->transitionTo(TripStatus::Arrived);
        $trip->transitionTo(TripStatus::InProgress);
        $trip->transitionTo(TripStatus::Completed, attributes: ['fare_amount' => $fare, 'commission_amount' => intdiv($fare, 5), 'driver_earnings_amount' => $fare - intdiv($fare, 5)]);
        $payments->complete($trip->fresh());

        return ['id' => $trip->id, 'public_code' => $trip->public_code, 'payment_method_id' => $card->id, 'payment_status' => $trip->fresh()->payment_status->value];
    }

    /**
     * What happens when a new card trip is requested for the rider with this card (the step trip creation, P7-T6, runs). {rider_id, payment_method_id}
     */
    private function holdCardTrip(array $a): array
    {
        $trip = Trip::factory()->create(['rider_id' => $a['rider_id'], 'payment_method' => TripPaymentMethod::Card, 'payment_method_id' => $a['payment_method_id']]);

        try {
            $hold = app(TripCardPayments::class)->hold($trip);

            return ['trip_id' => $trip->id, 'held' => $hold->amount, 'payment_status' => $trip->fresh()->payment_status->value];
        } catch (PaymentException $e) {
            return ['trip_id' => $trip->id, 'refused' => $e->errorCode, 'status' => $e->status];
        } finally {
            // The probe is over: the rider may have only one active trip (P7-T6), and a spec asks this again for the same rider.
            DB::table('trips')->where('id', $trip->id)->update(['status' => TripStatus::Cancelled->value, 'cancelled_by' => 'system', 'cancelled_at' => now()]);
        }
    }

    private function clearRateLimits(): array
    {
        Cache::flush();

        return ['cleared' => true];
    }

    private function findUser(array $a): User
    {
        return match (true) {
            isset($a['id']) => User::withTrashed()->findOrFail($a['id']),
            isset($a['email']) => User::withTrashed()->where('email', $a['email'])->firstOrFail(),
            isset($a['phone']) => User::withTrashed()->wherePhone($a['phone'])->firstOrFail(),
            default => throw new InvalidArgumentException('Give id, email or phone.'),
        };
    }
}
