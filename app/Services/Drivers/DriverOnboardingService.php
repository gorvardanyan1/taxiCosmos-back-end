<?php

namespace App\Services\Drivers;

use App\Enums\DriverDocumentStatus;
use App\Enums\DriverDocumentType;
use App\Enums\DriverVerificationStatus;
use App\Enums\TripStatus;
use App\Enums\VehicleStatus;
use App\Exceptions\DriverOnboardingException;
use App\Models\DriverBankAccount;
use App\Models\DriverDocument;
use App\Models\DriverProfile;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Audit\AuditLogger;
use App\Services\DriverDocuments\DriverDocumentUploader;
use App\Services\DriverDocuments\DriverVerificationSync;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * What a driver does from the app to get approved (P4-T4): apply, fill in the licence and home zone, add vehicles, upload documents
 * and add a payout account. Every change is one transaction under the driver's row lock, with an audit entry made by the driver.
 *
 * Changing something that was already approved sends exactly that item back to review (the licence documents after a licence change;
 * a vehicle and its own papers after a vehicle change) and nothing else: the driver's other documents and vehicles keep their status.
 * Retries are safe without a key: applying again, adding the same vehicle or payout account again, or uploading a document again
 * (which replaces the earlier pending or rejected one) changes nothing the second time.
 */
final class DriverOnboardingService
{
    /** Trips a vehicle is in use on. */
    private const LIVE_TRIP_STATUSES = [TripStatus::Matched, TripStatus::Arrived, TripStatus::InProgress];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly DriverVerificationSync $verification,
        private readonly DriverDocumentUploader $uploader,
    ) {}

    /** The driver profile of a signed-in user, or "apply first". */
    public function profileOf(User $user): DriverProfile
    {
        return DriverProfile::query()->where('user_id', $user->getKey())->first() ?? throw DriverOnboardingException::applicationRequired();
    }

    /**
     * @return array{profile: DriverProfile, created: bool}
     */
    public function apply(User $user): array
    {
        return DB::transaction(function () use ($user) {
            // The user row serialises two simultaneous applications (the profile's user_id is unique).
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $existing = DriverProfile::query()->where('user_id', $locked->getKey())->first();

            if ($existing !== null) {
                $locked->is_driver ?: $locked->enableDriverMode();

                return ['profile' => $existing, 'created' => false];
            }

            $profile = new DriverProfile;
            $profile->forceFill(['user_id' => $locked->getKey(), 'verification_status' => DriverVerificationStatus::Pending])->save();
            $locked->enableDriverMode();
            $this->audit->record(actor: $locked, action: 'driver.application.submitted', target: $profile);

            return ['profile' => $profile->refresh(), 'created' => true];
        });
    }

    /**
     * @param  array{license_number: string, license_expiry: string, home_zone_id: ?int}  $data
     */
    public function updateProfile(User $user, DriverProfile $profile, array $data): DriverProfile
    {
        return DB::transaction(function () use ($user, $profile, $data) {
            $locked = $this->lock($profile);
            $before = ['license_number' => $locked->license_number, 'license_expiry' => $locked->license_expiry?->toDateString(), 'home_zone_id' => $locked->home_zone_id];

            // Compared by value: the stored licence number is randomised ciphertext, so assigning the same number again would look like a change.
            $numberChanged = $before['license_number'] !== $data['license_number'];
            $expiryChanged = $before['license_expiry'] !== $data['license_expiry'];
            $zoneChanged = $before['home_zone_id'] !== ($data['home_zone_id'] ?? null);

            if (! $numberChanged && ! $expiryChanged && ! $zoneChanged) {
                return $locked;
            }

            $numberChanged && $locked->license_number = $data['license_number'];
            $locked->license_expiry = $data['license_expiry'];
            $locked->home_zone_id = $data['home_zone_id'] ?? null;

            try {
                $locked->save();
            } catch (UniqueConstraintViolationException) {
                // Someone else holds this licence number. Said without naming who, or whether it is a real licence.
                throw ValidationException::withMessages(['license_number' => __('This licence number cannot be used.')]);
            }

            $after = ['license_number' => $locked->license_number, 'license_expiry' => $locked->license_expiry?->toDateString(), 'home_zone_id' => $locked->home_zone_id];

            if ($numberChanged || $expiryChanged) {
                $this->sendBackToReview($locked, fn (Builder $documents) => $documents->where('type', DriverDocumentType::License->value));
            }

            $this->audit->record(actor: $user, action: 'driver.self.profile_updated', target: $locked, old: array_diff_assoc($before, $after), new: array_diff_assoc($after, $before));

            return $locked->refresh();
        });
    }

    /**
     * @param  array{make: string, model: string, year: int, color: string, plate_number: string, vehicle_class: string}  $data
     * @return array{vehicle: Vehicle, created: bool}
     */
    public function addVehicle(User $user, DriverProfile $profile, array $data): array
    {
        return DB::transaction(function () use ($user, $profile, $data) {
            $locked = $this->lock($profile);

            // The same vehicle again (a retry) is the one already added.
            $same = Vehicle::query()->where('driver_id', $locked->id)->where('plate_number', $data['plate_number'])->first();
            if ($same !== null && $this->sameVehicle($same, $data)) {
                return ['vehicle' => $same, 'created' => false];
            }

            $this->assertPlateFree($data['plate_number'], null);

            if (Vehicle::query()->where('driver_id', $locked->id)->count() >= config('taxikosmos.onboarding.max_vehicles')) {
                throw DriverOnboardingException::vehicleLimit();
            }

            $vehicle = new Vehicle($data);
            $vehicle->forceFill(['driver_id' => $locked->id, 'status' => VehicleStatus::PendingReview])->save();
            $this->audit->record(actor: $user, action: 'driver.self.vehicle_added', target: $vehicle, new: $this->snapshot($vehicle->refresh()));

            return ['vehicle' => $vehicle, 'created' => true];
        });
    }

    /**
     * Only the fields sent change. Returns false when nothing differed. An approved vehicle goes back to review (and stops being the
     * primary one) together with its own papers; the driver's other vehicles and documents are not touched.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateVehicle(User $user, DriverProfile $profile, Vehicle $vehicle, array $data): bool
    {
        return DB::transaction(function () use ($user, $profile, $vehicle, $data) {
            $locked = $this->lock($profile);
            $row = Vehicle::query()->where('driver_id', $locked->id)->whereKey($vehicle->getKey())->lockForUpdate()->firstOrFail();
            $before = $this->snapshot($row);

            $row->fill($data);

            if (! $row->isDirty()) {
                return false;
            }

            if (isset($data['plate_number'])) {
                $this->assertPlateFree($data['plate_number'], $row->id);
            }

            $wasApproved = $row->status === VehicleStatus::Active;
            if ($wasApproved) {
                $row->forceFill(['status' => VehicleStatus::PendingReview, 'is_primary' => false]);
            }

            $row->save();

            if ($wasApproved) {
                $this->sendBackToReview($locked, fn (Builder $documents) => $documents->where('vehicle_id', $row->id));
            }

            $after = $this->snapshot($row->refresh());
            $this->audit->record(actor: $user, action: 'driver.self.vehicle_updated', target: $row, old: array_diff_assoc($before, $after), new: array_diff_assoc($after, $before));

            return true;
        });
    }

    public function removeVehicle(User $user, DriverProfile $profile, Vehicle $vehicle): void
    {
        $files = DB::transaction(function () use ($user, $profile, $vehicle) {
            $locked = $this->lock($profile);
            $row = Vehicle::query()->where('driver_id', $locked->id)->whereKey($vehicle->getKey())->lockForUpdate()->firstOrFail();

            if (Trip::query()->where('vehicle_id', $row->id)->whereIn('status', array_map(fn (TripStatus $s) => $s->value, self::LIVE_TRIP_STATUSES))->exists()) {
                throw DriverOnboardingException::vehicleOnTrip();
            }

            // Its papers stop counting for the driver's verification; the rows stay for the audit trail.
            $documents = DriverDocument::query()->where('driver_id', $locked->id)->where('vehicle_id', $row->id)->get();
            $paths = $documents->pluck('file_path')->all();
            $documents->each->delete();

            $snapshot = $this->snapshot($row);
            $row->forceFill(['is_primary' => false])->save();
            $row->delete();
            $this->verification->sync($locked, null);
            $this->audit->record(actor: $user, action: 'driver.self.vehicle_removed', target: $row, old: $snapshot);

            return $paths;
        });

        $this->forgetFiles($files);
    }

    /**
     * Upload one document. It replaces the driver's earlier pending or rejected document of the same type (and vehicle); approved
     * ones stay until the new one is approved.
     */
    public function uploadDocument(User $user, DriverProfile $profile, DriverDocumentType $type, UploadedFile $file, ?Vehicle $vehicle, ?string $documentNumber, ?CarbonInterface $expiresAt): DriverDocument
    {
        $document = $this->uploader->upload($profile, $type, $file, $vehicle, $documentNumber, $expiresAt);
        $this->audit->record(actor: $user, action: 'driver.self.document_uploaded', target: $document, context: ['type' => $type->value, 'vehicle_id' => $vehicle?->id]);

        return $document;
    }

    /**
     * @param  array{account_holder: string, bank_name: string, account_number: string, swift?: ?string}  $data
     * @return array{account: DriverBankAccount, created: bool}
     */
    public function addBankAccount(User $user, DriverProfile $profile, array $data): array
    {
        return DB::transaction(function () use ($user, $profile, $data) {
            $locked = $this->lock($profile);
            $accounts = DriverBankAccount::query()->where('driver_id', $locked->id)->get();
            $number = preg_replace('/\s+/', '', $data['account_number']);

            // The same account again (a retry) is the one already added.
            $same = $accounts->first(fn (DriverBankAccount $account) => $account->account_number === $number);
            if ($same !== null) {
                return ['account' => $same, 'created' => false];
            }

            if ($accounts->count() >= config('taxikosmos.onboarding.max_bank_accounts')) {
                throw DriverOnboardingException::bankAccountLimit();
            }

            $account = new DriverBankAccount($data);
            $account->forceFill(['driver_id' => $locked->id, 'is_default' => $accounts->isEmpty()])->save();
            $this->audit->record(actor: $user, action: 'driver.self.bank_account_added', target: $account, context: ['last4' => $account->account_last4]);

            return ['account' => $account->refresh(), 'created' => true];
        });
    }

    public function removeBankAccount(User $user, DriverProfile $profile, DriverBankAccount $account): void
    {
        DB::transaction(function () use ($user, $profile, $account) {
            $locked = $this->lock($profile);
            $row = DriverBankAccount::query()->where('driver_id', $locked->id)->whereKey($account->getKey())->lockForUpdate()->firstOrFail();
            $wasDefault = $row->is_default;

            $row->forceFill(['is_default' => false])->save();
            $row->delete();

            // Payouts always have a default to go to while any account is left: the newest one.
            if ($wasDefault) {
                DriverBankAccount::query()->where('driver_id', $locked->id)->latest('id')->first()?->markAsDefault();
            }

            $this->audit->record(actor: $user, action: 'driver.self.bank_account_removed', target: $row, context: ['last4' => $row->account_last4]);
        });
    }

    /**
     * Send the driver's approved documents that match back to pending, then bring the verification status in line. Documents that
     * are pending, rejected or of other types are untouched.
     *
     * @param  callable(Builder<DriverDocument>): Builder<DriverDocument>  $scope
     */
    private function sendBackToReview(DriverProfile $profile, callable $scope): void
    {
        $scope(DriverDocument::query()->where('driver_id', $profile->id)->where('status', DriverDocumentStatus::Approved->value))
            ->update(['status' => DriverDocumentStatus::Pending->value, 'reviewed_by' => null, 'reviewed_at' => null, 'rejection_reason' => null]);

        $this->verification->sync($profile, null);
    }

    private function lock(DriverProfile $profile): DriverProfile
    {
        return DriverProfile::query()->whereKey($profile->getKey())->lockForUpdate()->firstOrFail();
    }

    /** A plate belongs to one vehicle at a time across the platform. Said without naming who has it. */
    private function assertPlateFree(string $plate, ?int $exceptId): void
    {
        $taken = Vehicle::query()->where('plate_number', $plate)->when($exceptId !== null, fn (Builder $query) => $query->whereKeyNot($exceptId))->exists();

        if ($taken) {
            throw ValidationException::withMessages(['plate_number' => __('This plate number cannot be used.')]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function sameVehicle(Vehicle $vehicle, array $data): bool
    {
        return $vehicle->make === $data['make'] && $vehicle->model === $data['model'] && $vehicle->year === (int) $data['year']
            && $vehicle->color === $data['color'] && $vehicle->vehicle_class->value === $data['vehicle_class'];
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Vehicle $vehicle): array
    {
        return [
            'make' => $vehicle->make, 'model' => $vehicle->model, 'year' => $vehicle->year, 'color' => $vehicle->color,
            'plate_number' => $vehicle->plate_number, 'vehicle_class' => $vehicle->vehicle_class->value, 'status' => $vehicle->status->value,
        ];
    }

    /**
     * @param  list<string>  $paths
     */
    private function forgetFiles(array $paths): void
    {
        Storage::disk((string) config('taxikosmos.documents.disk'))->delete($paths);
    }
}
