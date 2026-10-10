<?php

namespace App\Enums;

enum DriverDocumentType: string
{
    case License = 'license';
    case IdCard = 'id_card';
    case VehicleRegistration = 'vehicle_registration';
    case Insurance = 'insurance';
    case BackgroundCheck = 'background_check';

    public function label(): string
    {
        return match ($this) {
            self::License => "Driver's license",
            self::IdCard => 'ID card',
            self::VehicleRegistration => 'Vehicle registration',
            self::Insurance => 'Insurance certificate',
            self::BackgroundCheck => 'Background check',
        };
    }

    /**
     * Documents that belong to a vehicle rather than to the driver, so they need a vehicle_id.
     */
    public function isVehicleDocument(): bool
    {
        return $this === self::VehicleRegistration || $this === self::Insurance;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
