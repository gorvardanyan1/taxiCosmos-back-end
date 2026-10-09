<?php

namespace App\Enums;

/**
 * Admin-tier roles (spatie roles on the web guard). Each role is a bundle of
 * AdminPermission values; the matrix below is the seeded default and is
 * documented in docs/permissions.md.
 */
enum AdminRole: string
{
    case SuperAdmin = 'super_admin';
    case Admin = 'admin';
    case Support = 'support';
    case Finance = 'finance';
    case Dispatcher = 'dispatcher';

    /**
     * @return list<AdminPermission>
     */
    public function defaultPermissions(): array
    {
        return match ($this) {
            self::SuperAdmin => AdminPermission::cases(),
            self::Admin => array_values(array_filter(
                AdminPermission::cases(),
                fn (AdminPermission $permission) => $permission !== AdminPermission::AdminsManage,
            )),
            self::Support => [
                AdminPermission::RidersView,
                AdminPermission::RidersSuspend,
                AdminPermission::DriversView,
                AdminPermission::DriversSuspend,
                AdminPermission::DriversVerify,
                AdminPermission::TripsView,
                AdminPermission::PaymentsView,
                AdminPermission::SupportManage,
            ],
            self::Finance => [
                AdminPermission::RidersView,
                AdminPermission::DriversView,
                AdminPermission::TripsView,
                AdminPermission::TripsAdjustFare,
                AdminPermission::PaymentsView,
                AdminPermission::PaymentsRefund,
                AdminPermission::PaymentsManual,
                AdminPermission::PayoutsView,
                AdminPermission::PayoutsApprove,
                AdminPermission::ChargebacksManage,
                AdminPermission::ReportsView,
            ],
            self::Dispatcher => [
                AdminPermission::RidersView,
                AdminPermission::DriversView,
                AdminPermission::TripsView,
                AdminPermission::TripsForceCancel,
                AdminPermission::LiveMapView,
            ],
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
