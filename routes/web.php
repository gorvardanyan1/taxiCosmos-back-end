<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\AuthPageController;
use App\Http\Controllers\TwoFactorSetupController;
use Illuminate\Support\Facades\Route;

// Record ids in URLs: 1-18 digits, so an id too large for a bigint is a plain 404, never a type error.
foreach (['rider', 'driver', 'document', 'trip', 'zone', 'vehicle', 'account', 'user'] as $parameter) {
    Route::pattern($parameter, '[0-9]{1,18}');
}

Route::redirect('/', '/admin');

// Admin authentication screens. Fortify (views disabled) handles POST /login, /logout,
// /forgot-password and /reset-password; two-factor arrives in P3-T2, invitations in P3-T4.
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthPageController::class, 'login'])->name('login');
    Route::get('/forgot-password', [AuthPageController::class, 'forgotPassword'])->name('password.request');
    Route::get('/reset-password/{token}', [AuthPageController::class, 'resetPassword'])->name('password.reset');
    Route::get('/accept-invitation/{token}', [AuthPageController::class, 'acceptInvitation'])->name('invitation.accept');
    Route::get('/two-factor-challenge', [AuthPageController::class, 'twoFactorChallenge'])->name('two-factor.login');
});

// Two-factor setup: any signed-in admin, including one who must set it up before anything else (no EnsureTwoFactorSetUp here).
Route::middleware(['auth', 'admin.idle', 'admin', 'throttle:admin'])->group(function () {
    Route::get('/two-factor-setup', [TwoFactorSetupController::class, 'show'])->name('two-factor.setup');
    Route::post('/two-factor-setup', [TwoFactorSetupController::class, 'start'])->middleware('throttle:password-confirm')->name('two-factor.setup.start');
    Route::post('/two-factor-setup/confirm', [TwoFactorSetupController::class, 'confirm'])->middleware('throttle:two-factor-confirm')->name('two-factor.setup.confirm');
});

// Admin panel: session guard + idle timeout + admin access, and a named permission per page
// (docs/permissions.md).
Route::prefix('admin')->name('admin.')->middleware(['auth', 'admin.idle', 'admin', 'two-factor.required', 'throttle:admin'])->group(function () {
    Route::get('/', Admin\DashboardController::class)->middleware('permission:dashboard.view')->name('dashboard');
    Route::get('/live-map', Admin\LiveMapController::class)->middleware('permission:live_map.view')->name('live-map');

    Route::middleware('permission:riders.view')->group(function () {
        Route::get('/riders', [Admin\RiderController::class, 'index'])->name('riders.index');
        Route::get('/riders/{rider}', [Admin\RiderController::class, 'show'])->name('riders.show');
    });

    Route::patch('/riders/{rider}', [Admin\RiderController::class, 'update'])->middleware('permission:riders.edit')->name('riders.update');
    Route::middleware('permission:riders.suspend')->group(function () {
        Route::post('/riders/{rider}/suspend', [Admin\RiderController::class, 'suspend'])->name('riders.suspend');
        Route::post('/riders/{rider}/reactivate', [Admin\RiderController::class, 'reactivate'])->name('riders.reactivate');
    });

    Route::middleware('permission:drivers.view')->group(function () {
        Route::get('/drivers', [Admin\DriverController::class, 'index'])->name('drivers.index');
        Route::get('/drivers/{driver}', [Admin\DriverController::class, 'show'])->name('drivers.show');
        Route::get('/drivers/{driver}/vehicles', [Admin\DriverController::class, 'vehicles'])->name('drivers.vehicles');

        // Document files: only with a valid, unexpired signature (links are issued per page load).
        Route::get('/drivers/{driver}/documents/{document}/file', [Admin\DriverDocumentController::class, 'file'])
            ->middleware('signed:relative')->name('drivers.documents.file');
    });

    Route::patch('/drivers/{driver}', [Admin\DriverController::class, 'update'])->middleware('permission:drivers.edit')->name('drivers.update');
    Route::middleware('permission:drivers.suspend')->group(function () {
        Route::post('/drivers/{driver}/suspend', [Admin\DriverController::class, 'suspend'])->name('drivers.suspend');
        Route::post('/drivers/{driver}/reactivate', [Admin\DriverController::class, 'reactivate'])->name('drivers.reactivate');
    });
    Route::middleware('permission:drivers.edit')->group(function () {
        Route::post('/drivers/{driver}/vehicles', [Admin\DriverVehicleController::class, 'store'])->name('drivers.vehicles.store');
        Route::patch('/drivers/{driver}/vehicles/{vehicle}', [Admin\DriverVehicleController::class, 'update'])->name('drivers.vehicles.update');
        Route::post('/drivers/{driver}/vehicles/{vehicle}/set-primary', [Admin\DriverVehicleController::class, 'setPrimary'])->name('drivers.vehicles.set-primary');
    });
    Route::post('/drivers/{driver}/bank-accounts/{account}/reveal', [Admin\DriverBankAccountController::class, 'reveal'])
        ->middleware(['permission:drivers.reveal_bank_account', 'throttle:bank-account-reveal'])->name('drivers.bank-accounts.reveal');

    Route::middleware('permission:drivers.verify')->group(function () {
        Route::post('/drivers/{driver}/documents/{document}/approve', [Admin\DriverDocumentController::class, 'approve'])
            ->name('drivers.documents.approve');
        Route::post('/drivers/{driver}/documents/{document}/reject', [Admin\DriverDocumentController::class, 'reject'])
            ->name('drivers.documents.reject');
    });

    Route::middleware('permission:trips.view')->group(function () {
        Route::get('/trips', [Admin\TripController::class, 'index'])->name('trips.index');
        Route::get('/trips/{trip}', [Admin\TripController::class, 'show'])->name('trips.show');
    });

    Route::get('/support-tickets', Admin\SupportTicketController::class)->middleware('permission:support.manage')->name('support-tickets.index');
    Route::get('/ratings', Admin\RatingController::class)->middleware('permission:ratings.view')->name('ratings.index');
    Route::get('/transactions', Admin\TransactionController::class)->middleware('permission:payments.view')->name('transactions.index');
    Route::get('/payouts', Admin\PayoutController::class)->middleware('permission:payouts.view')->name('payouts.index');
    Route::get('/chargebacks', Admin\ChargebackController::class)->middleware('permission:chargebacks.manage')->name('chargebacks.index');
    Route::get('/driver-balances', Admin\DriverBalanceController::class)->middleware('permission:driver_balances.view')->name('driver-balances.index');
    Route::get('/reports', Admin\ReportController::class)->middleware('permission:reports.view')->name('reports.index');
    Route::middleware('permission:zones.manage')->group(function () {
        Route::get('/zones', [Admin\ZoneController::class, 'index'])->name('zones.index');
        Route::post('/zones', [Admin\ZoneController::class, 'store'])->name('zones.store');
        Route::patch('/zones/{zone}', [Admin\ZoneController::class, 'update'])->name('zones.update');
        Route::post('/zones/{zone}/deactivate', [Admin\ZoneController::class, 'deactivate'])->name('zones.deactivate');
        Route::post('/zones/{zone}/activate', [Admin\ZoneController::class, 'activate'])->name('zones.activate');
    });
    Route::post('/zones/{zone}/fare-rules', [Admin\FareRuleController::class, 'store'])->middleware('permission:fares.manage')->whereNumber('zone')->name('fare-rules.store');
    Route::get('/surge', Admin\SurgeController::class)->middleware('permission:fares.manage')->name('surge.index');
    Route::middleware('permission:fares.manage')->group(function () {
        Route::get('/commission-rules', [Admin\CommissionRuleController::class, 'index'])->name('commission-rules.index');
        Route::post('/commission-rules', [Admin\CommissionRuleController::class, 'store'])->name('commission-rules.store');
    });

    // Opens the first Settings tab the admin may use (Users & Roles, else Gateways).
    Route::get('/settings', [Admin\SettingsController::class, 'index'])->middleware('permission:admins.manage|settings.manage')->name('settings');
    Route::get('/settings/users', [Admin\SettingsController::class, 'users'])->middleware('permission:admins.manage')->name('settings.users');
    Route::post('/settings/users/{user}/two-factor/reset', [Admin\AdminUserTwoFactorController::class, 'reset'])->middleware(['permission:admins.manage', 'throttle:password-confirm'])->name('settings.users.two-factor.reset');
    Route::middleware('permission:settings.manage')->group(function () {
        Route::get('/settings/gateways', [Admin\SettingsController::class, 'gateways'])->name('settings.gateways');
        Route::get('/settings/currencies', [Admin\SettingsController::class, 'currencies'])->name('settings.currencies');
        Route::get('/settings/platform', [Admin\SettingsController::class, 'platform'])->name('settings.platform');
        Route::get('/settings/maps', [Admin\SettingsController::class, 'maps'])->name('settings.maps');
    });

    Route::middleware('permission:activity_log.view')->group(function () {
        Route::get('/activity-logs', [Admin\ActivityLogController::class, 'index'])->name('activity-logs.index');
        Route::get('/activity-logs/export', [Admin\ActivityLogController::class, 'export'])->name('activity-logs.export');
    });

    // Self-service: admin access only, no permission (docs/permissions.md).
    Route::get('/socket-ticket', Admin\SocketTicketController::class)->middleware('throttle:socket-ticket')->name('socket-ticket');
    Route::get('/account', Admin\AccountController::class)->name('account');
    Route::patch('/account/locale', Admin\AccountLocaleController::class)->name('account.locale');
    Route::delete('/account/two-factor', [Admin\AccountTwoFactorController::class, 'destroy'])->middleware('throttle:password-confirm')->name('account.two-factor.destroy');
    Route::post('/account/two-factor/recovery-codes', [Admin\AccountTwoFactorController::class, 'regenerateRecoveryCodes'])->middleware('throttle:password-confirm')->name('account.two-factor.recovery-codes');
});
