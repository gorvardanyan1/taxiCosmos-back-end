<?php

namespace App\Services\Riders;

use App\Enums\UserStatus;
use App\Exceptions\RiderStateException;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\E164PhoneNumber;
use Illuminate\Support\Facades\DB;

/**
 * What admins do to a rider: edit contact details (riders.edit) and suspend / reactivate
 * (riders.suspend). Each action is one transaction with its audit entry and a required reason.
 * Suspending revokes the rider's Sanctum tokens and, because the status changes through the model,
 * UserObserver publishes user.suspended after commit (P8-T1).
 */
final class RiderService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Only the fields present in $data change. Returns false when nothing differed (no write, no audit entry).
     *
     * @param  array{name?: string, email?: ?string, phone?: string, locale?: ?string}  $data
     */
    public function update(User $actor, User $rider, array $data, string $reason): bool
    {
        return DB::transaction(function () use ($actor, $rider, $data, $reason) {
            $locked = $this->lock($rider);
            $old = [];
            $new = [];

            foreach (['name', 'email', 'locale'] as $field) {
                if (array_key_exists($field, $data) && $data[$field] !== $locked->{$field}) {
                    $old[$field] = $locked->{$field};
                    $new[$field] = $data[$field];
                    $locked->{$field} = $data[$field];
                }
            }

            // The phone is stored in E.164; "091 440 221" and "+37491440221" are the same number.
            if (array_key_exists('phone', $data)) {
                $phone = E164PhoneNumber::normalize($data['phone']);

                if ($phone !== $locked->phone) {
                    $old['phone'] = $locked->phone;
                    $new['phone'] = $phone;
                    $locked->phone = $phone;
                }
            }

            if ($old === []) {
                return false;
            }

            $locked->save();

            $this->audit->record(actor: $actor, action: 'rider.updated', target: $locked, reason: $reason, old: $old, new: $new, reasonRequired: true);

            return true;
        });
    }

    public function suspend(User $actor, User $rider, string $reason): User
    {
        return DB::transaction(function () use ($actor, $rider, $reason) {
            $locked = $this->lock($rider);

            if ($locked->is($actor)) {
                throw RiderStateException::ownAccount();
            }

            // An admin who also rides is suspended from Settings → Users, not from the rider list.
            if ($locked->is_admin) {
                throw RiderStateException::adminAccount();
            }

            if ($locked->status !== UserStatus::Active) {
                throw RiderStateException::notActive();
            }

            $locked->forceFill(['status' => UserStatus::Suspended, 'suspension_reason' => trim($reason)])->save();
            $locked->tokens()->delete();

            $this->audit->record(
                actor: $actor, action: 'rider.suspended', target: $locked, reason: $reason, reasonRequired: true,
                old: ['status' => UserStatus::Active->value], new: ['status' => UserStatus::Suspended->value],
            );

            return $locked;
        });
    }

    public function reactivate(User $actor, User $rider, string $reason): User
    {
        return DB::transaction(function () use ($actor, $rider, $reason) {
            $locked = $this->lock($rider);

            if ($locked->status !== UserStatus::Suspended) {
                throw RiderStateException::notSuspended();
            }

            $locked->forceFill(['status' => UserStatus::Active, 'suspension_reason' => null])->save();

            $this->audit->record(
                actor: $actor, action: 'rider.reactivated', target: $locked, reason: $reason, reasonRequired: true,
                old: ['status' => UserStatus::Suspended->value], new: ['status' => UserStatus::Active->value],
            );

            return $locked;
        });
    }

    private function lock(User $rider): User
    {
        return User::query()->where('is_rider', true)->whereKey($rider->getKey())->lockForUpdate()->firstOrFail();
    }
}
