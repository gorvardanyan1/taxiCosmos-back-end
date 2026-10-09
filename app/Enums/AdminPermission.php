<?php

namespace App\Enums;

/**
 * One named spatie permission per admin action. Every /admin route is protected
 * by one of these (never by a role check alone). Add new cases here, to the role
 * matrix in AdminRole::defaultPermissions() and to docs/permissions.md.
 */
enum AdminPermission: string
{
    case RidersView = 'riders.view';
    case RidersSuspend = 'riders.suspend';

    case DriversView = 'drivers.view';
    case DriversSuspend = 'drivers.suspend';
    case DriversVerify = 'drivers.verify';

    case TripsView = 'trips.view';
    case TripsForceCancel = 'trips.force_cancel';
    case TripsAdjustFare = 'trips.adjust_fare';

    case PaymentsView = 'payments.view';
    case PaymentsRefund = 'payments.refund';
    case PaymentsManual = 'payments.manual';

    case PayoutsView = 'payouts.view';
    case PayoutsApprove = 'payouts.approve';

    case ChargebacksManage = 'chargebacks.manage';

    case ZonesManage = 'zones.manage';
    case FaresManage = 'fares.manage';
    case SettingsManage = 'settings.manage';
    case AdminsManage = 'admins.manage';

    case ActivityLogView = 'activity_log.view';
    case LiveMapView = 'live_map.view';
    case ReportsView = 'reports.view';
    case SupportManage = 'support.manage';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
