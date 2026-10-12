<?php

namespace App\Enums;

use App\Models\CommissionRule;
use App\Models\DriverBankAccount;
use App\Models\DriverDocument;
use App\Models\DriverProfile;
use App\Models\FareRule;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Zone;
use Illuminate\Database\Eloquent\Model;

/**
 * The record types an audit entry can point at, with the stable name the Activity Log filter and
 * export use. Add a case (and its describe() line) when a task starts auditing a new model.
 */
enum AuditTargetType: string
{
    case CommissionRule = 'commission_rule';
    case Driver = 'driver';
    case DriverBankAccount = 'driver_bank_account';
    case DriverDocument = 'driver_document';
    case FareRule = 'fare_rule';
    case Vehicle = 'vehicle';
    case User = 'user';
    case Zone = 'zone';

    /**
     * @return class-string<Model>
     */
    public function modelClass(): string
    {
        return match ($this) {
            self::CommissionRule => CommissionRule::class,
            self::Driver => DriverProfile::class,
            self::DriverBankAccount => DriverBankAccount::class,
            self::DriverDocument => DriverDocument::class,
            self::FareRule => FareRule::class,
            self::Vehicle => Vehicle::class,
            self::User => User::class,
            self::Zone => Zone::class,
        };
    }

    public static function forModel(Model $model): ?self
    {
        foreach (self::cases() as $type) {
            if ($model instanceof ($type->modelClass())) {
                return $type;
            }
        }

        return null;
    }

    public static function forClass(?string $class): ?self
    {
        foreach (self::cases() as $type) {
            if ($class === $type->modelClass()) {
                return $type;
            }
        }

        return null;
    }

    /**
     * Human reference for the entry, stored with it so it stays readable when the record is
     * deleted. Never personal data: codes and ids only.
     */
    public static function describe(Model $model): string
    {
        return match (true) {
            $model instanceof DriverBankAccount => sprintf('Bank account #%d / •••• %s', $model->getKey(), $model->account_last4),
            $model instanceof DriverDocument => sprintf('D-%04d / %s', $model->driver_id, $model->type->label()),
            $model instanceof DriverProfile => sprintf('D-%04d', $model->getKey()),
            $model instanceof Vehicle => sprintf('Vehicle #%d', $model->getKey()),
            $model instanceof User => sprintf('User #%d', $model->getKey()),
            $model instanceof Zone => sprintf('Zone %s', $model->code),
            $model instanceof CommissionRule => sprintf('Commission rule #%d / %s', $model->getKey(), $model->vehicle_class->value ?? 'all'),
            $model instanceof FareRule => sprintf('Fare rule #%d / %s', $model->getKey(), $model->vehicle_class->value),
            default => class_basename($model).' #'.$model->getKey(),
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
