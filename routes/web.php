<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\AuthPageController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

// Admin authentication screens (POST handlers: Fortify in P3-T1/P3-T2, invitations in P3-T4).
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthPageController::class, 'login'])->name('login');
    Route::get('/forgot-password', [AuthPageController::class, 'forgotPassword'])->name('password.request');
    Route::get('/reset-password/{token}', [AuthPageController::class, 'resetPassword'])->name('password.reset');
    Route::get('/accept-invitation/{token}', [AuthPageController::class, 'acceptInvitation'])->name('invitation.accept');
    Route::get('/two-factor-challenge', [AuthPageController::class, 'twoFactorChallenge'])->name('two-factor.login');
});

Route::get('/two-factor-setup', [AuthPageController::class, 'twoFactorSetup'])
    ->middleware(['auth', 'admin'])
    ->name('two-factor.setup');

// Admin panel: session guard + admin access, and a named permission per page (docs/permissions.md).
Route::prefix('admin')->name('admin.')->middleware(['auth', 'admin'])->group(function () {
    Route::get('/', Admin\DashboardController::class)->middleware('permission:dashboard.view')->name('dashboard');
    Route::get('/live-map', Admin\LiveMapController::class)->middleware('permission:live_map.view')->name('live-map');

    Route::middleware('permission:riders.view')->group(function () {
        Route::get('/riders', [Admin\RiderController::class, 'index'])->name('riders.index');
        Route::get('/riders/{rider}', [Admin\RiderController::class, 'show'])->whereNumber('rider')->name('riders.show');
    });

    Route::middleware('permission:drivers.view')->group(function () {
        Route::get('/drivers', [Admin\DriverController::class, 'index'])->name('drivers.index');
        Route::get('/drivers/{driver}', [Admin\DriverController::class, 'show'])->whereNumber('driver')->name('drivers.show');
    });

    Route::middleware('permission:trips.view')->group(function () {
        Route::get('/trips', [Admin\TripController::class, 'index'])->name('trips.index');
        Route::get('/trips/{trip}', [Admin\TripController::class, 'show'])->whereNumber('trip')->name('trips.show');
    });

    Route::get('/support-tickets', Admin\SupportTicketController::class)->middleware('permission:support.manage')->name('support-tickets.index');
    Route::get('/ratings', Admin\RatingController::class)->middleware('permission:ratings.view')->name('ratings.index');
    Route::get('/transactions', Admin\TransactionController::class)->middleware('permission:payments.view')->name('transactions.index');
    Route::get('/payouts', Admin\PayoutController::class)->middleware('permission:payouts.view')->name('payouts.index');
    Route::get('/chargebacks', Admin\ChargebackController::class)->middleware('permission:chargebacks.manage')->name('chargebacks.index');
    Route::get('/driver-balances', Admin\DriverBalanceController::class)->middleware('permission:driver_balances.view')->name('driver-balances.index');
    Route::get('/reports', Admin\ReportController::class)->middleware('permission:reports.view')->name('reports.index');
    Route::get('/zones', Admin\ZoneController::class)->middleware('permission:zones.manage')->name('zones.index');
    Route::get('/surge', Admin\SurgeController::class)->middleware('permission:fares.manage')->name('surge.index');
    Route::get('/commission-rules', Admin\CommissionRuleController::class)->middleware('permission:fares.manage')->name('commission-rules.index');

    Route::get('/settings', fn () => to_route('admin.settings.users'))->name('settings');
    Route::get('/settings/users', [Admin\SettingsController::class, 'users'])->middleware('permission:admins.manage')->name('settings.users');
    Route::middleware('permission:settings.manage')->group(function () {
        Route::get('/settings/gateways', [Admin\SettingsController::class, 'gateways'])->name('settings.gateways');
        Route::get('/settings/currencies', [Admin\SettingsController::class, 'currencies'])->name('settings.currencies');
        Route::get('/settings/platform', [Admin\SettingsController::class, 'platform'])->name('settings.platform');
        Route::get('/settings/maps', [Admin\SettingsController::class, 'maps'])->name('settings.maps');
    });

    Route::get('/activity-logs', Admin\ActivityLogController::class)->middleware('permission:activity_log.view')->name('activity-logs.index');

    // Self-service: admin access only, no permission (docs/permissions.md).
    Route::get('/account', Admin\AccountController::class)->name('account');
});
