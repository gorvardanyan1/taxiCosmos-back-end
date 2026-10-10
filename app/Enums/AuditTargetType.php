<?php

namespace App\Enums;

use App\Models\DriverDocument;
use App\Models\DriverProfile;
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
    case Driver = 'driver';
    case DriverDocument = 'driver_document';
    case Vehicle = 'vehicle';
    case User = 'user';
    case Zone = 'zone';

    /**
     * @return class-string<Model>
     */
    public function modelClass(): string
    {
        return match ($this) {
            self::Driver => DriverProfile::class,
            self::DriverDocument => DriverDocument::class,
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
            $model instanceof DriverDocument => sprintf('D-%04d / %s', $model->driver_id, $model->type->label()),
            $model instanceof DriverProfile => sprintf('D-%04d', $model->getKey()),
            $model instanceof Vehicle => sprintf('Vehicle #%d', $model->getKey()),
            $model instanceof User => sprintf('User #%d', $model->getKey()),
            $model instanceof Zone => sprintf('Zone %s', $model->code),
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
