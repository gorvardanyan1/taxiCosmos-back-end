<?php

namespace Tests\E2e;

use App\Enums\AdminRole;
use App\Enums\AppClient;
use App\Enums\DriverDocumentType;
use App\Enums\TripStatus;
use App\Enums\UserStatus;
use App\Enums\VehicleClass;
use App\Http\Middleware\EnforceAdminIdleTimeout;
use App\Models\CommissionRule;
use App\Models\DriverBankAccount;
use App\Models\DriverDocument;
use App\Models\DriverProfile;
use App\Models\FareFee;
use App\Models\FareRule;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Zone;
use App\Realtime\RealtimeEventType;
use App\Realtime\StreamClient;
use App\Services\Audit\AuditLogger;
use App\Services\Commission\TripCommission;
use App\Services\Fares\FareCalculator;
use App\Services\MobileAuth\MobileSessionService;
use App\Support\BlindIndex;
use App\Support\E164PhoneNumber;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Sanctum\PersonalAccessToken;

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
            'clear_rate_limits' => $this->clearRateLimits(),
            default => throw new InvalidArgumentException("Unknown e2e fixture \"{$fixture}\"."),
        };
    }

    /**
     * An admin who can sign in with PASSWORD. {email, role, name?, phone?, timezone?, locale?}
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

        return ['id' => $user->id, 'email' => $user->email, 'name' => $user->name, 'role' => $role->value, 'password' => self::PASSWORD, 'locale' => $user->locale];
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
        $user->forceFill(['status' => UserStatus::from($a['status'])])->save();

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
     * Forget every rate-limit counter (the e2e cache holds nothing else that a test relies on).
     */
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
