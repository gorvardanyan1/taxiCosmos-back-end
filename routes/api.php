<?php

use App\Enums\SocialProvider;
use App\Http\Controllers\Api\V1\AppConfigController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\DriverApplicationController;
use App\Http\Controllers\Api\V1\DriverAvailabilityController;
use App\Http\Controllers\Api\V1\DriverBankAccountController;
use App\Http\Controllers\Api\V1\DriverDocumentController;
use App\Http\Controllers\Api\V1\DriverEarningsController;
use App\Http\Controllers\Api\V1\DriverLocationController;
use App\Http\Controllers\Api\V1\DriverProfileController;
use App\Http\Controllers\Api\V1\DriverTripController;
use App\Http\Controllers\Api\V1\DriverVehicleController;
use App\Http\Controllers\Api\V1\FareEstimateController;
use App\Http\Controllers\Api\V1\GeoController;
use App\Http\Controllers\Api\V1\Internal\SocketAuthController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\OutstandingBalanceController;
use App\Http\Controllers\Api\V1\PaymentMethodController;
use App\Http\Controllers\Api\V1\SavedPlaceController;
use App\Http\Controllers\Api\V1\SupportTicketController;
use App\Http\Controllers\Api\V1\TripController;
use Illuminate\Support\Facades\Route;

// Mobile surface (rider and driver apps): JSON, Sanctum bearer tokens only, never a session.

// What an app asks before anyone has signed in: may this build still run, which features are on (P14-T4). Public, cacheable.
Route::get('app-config', AppConfigController::class)->middleware('throttle:app-config')->name('app-config');

Route::prefix('auth')->group(function () {
    Route::post('otp/request', [AuthController::class, 'requestOtp'])->middleware('throttle:otp-request');
    Route::post('otp/verify', [AuthController::class, 'verifyOtp'])->middleware('throttle:otp-verify');
    Route::post('social/{provider}', [AuthController::class, 'social'])
        ->whereIn('provider', array_column(SocialProvider::cases(), 'value'))
        ->middleware('throttle:social-login');
    Route::post('refresh', [AuthController::class, 'refresh'])->middleware('throttle:auth-refresh');

    Route::middleware(['auth:sanctum', 'mobile', 'throttle:api'])->group(function () {
        Route::post('logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::post('logout-all', [AuthController::class, 'logoutAll'])->name('auth.logout-all');
        // Only a session that still needs its phone number (social sign-in without one).
        Route::post('phone/verify', [AuthController::class, 'verifyPhone'])->middleware(['ability:pending-phone:rider,pending-phone:driver', 'throttle:otp-verify']);
    });
});

// The signed-in person's own account (P3-T5). GET /me is also open to a session that still has to add its phone, and to a
// blocked (suspended) account: it is how the apps decide which screen to show. Everything else needs a full session.
Route::middleware(['auth:sanctum', 'mobile', 'throttle:api', 'ability:rider,driver,pending-phone:rider,pending-phone:driver'])->get('me', [MeController::class, 'show'])->name('me.show');
Route::middleware(['auth:sanctum', 'mobile', 'throttle:api', 'ability:rider,driver'])->prefix('me')->group(function () {
    Route::patch('/', [MeController::class, 'update']);
    // A code goes to the new number, then comes back; both calls are limited like sign-in (SMS cost, guessing).
    Route::post('phone/request', [MeController::class, 'requestPhone'])->middleware('throttle:otp-request');
    Route::post('phone/verify', [MeController::class, 'verifyPhone'])->middleware('throttle:otp-verify');
});

// The rider app's saved places (home, work, others).
Route::middleware(['auth:sanctum', 'mobile', 'throttle:api', 'ability:rider'])->prefix('saved-places')->group(function () {
    Route::get('/', [SavedPlaceController::class, 'index']);
    Route::post('/', [SavedPlaceController::class, 'store']);
    Route::get('{place}', [SavedPlaceController::class, 'show'])->whereNumber('place');
    Route::patch('{place}', [SavedPlaceController::class, 'update'])->whereNumber('place');
    Route::delete('{place}', [SavedPlaceController::class, 'destroy'])->whereNumber('place');
});

Route::middleware(['auth:sanctum', 'mobile', 'throttle:api', 'ability:rider'])->prefix('trips')->group(function () {
    // What a trip would cost per vehicle class; returns the quote_id trip creation (P7-T6) refers to.
    Route::post('fare-estimate', FareEstimateController::class)->middleware('throttle:fare-estimate');
});

// The rider's saved cards and what they owe after a failed card payment (P6-T2). Rider app only; each rider only ever sees their own
// cards. Writes share the payment budget (card testing and double charges are attacks, not traffic).
Route::middleware(['auth:sanctum', 'mobile', 'throttle:api', 'ability:rider'])->group(function () {
    Route::get('payment-methods', [PaymentMethodController::class, 'index']);
    Route::get('account/outstanding', [OutstandingBalanceController::class, 'show']);

    Route::middleware('throttle:payment')->group(function () {
        Route::post('payment-methods', [PaymentMethodController::class, 'store']);
        Route::delete('payment-methods/{paymentMethod}', [PaymentMethodController::class, 'destroy'])->whereNumber('paymentMethod');
        Route::post('payment-methods/{paymentMethod}/default', [PaymentMethodController::class, 'makeDefault'])->whereNumber('paymentMethod');
        Route::post('account/outstanding/pay', [OutstandingBalanceController::class, 'pay']);
    });
});

// Push tokens (P8-T4): the apps register on sign-in and token refresh and deregister on logout. Either app, signed in fully
// (a session that still has to add its phone has nothing to be pushed about).
Route::middleware(['auth:sanctum', 'mobile', 'throttle:api', 'ability:rider,driver', 'throttle:devices'])->prefix('devices')->group(function () {
    Route::post('/', [DeviceController::class, 'store']);
    Route::delete('{device}', [DeviceController::class, 'destroy'])->whereNumber('device');
});

// Support tickets (P7-T5): either app, signed in fully. A blocked (suspended) account may still write to support (EnsureMobileClient lets
// the support.* routes through), so it can ask why. Each person only ever sees their own tickets, and never the internal notes.
Route::middleware(['auth:sanctum', 'mobile', 'throttle:api', 'ability:rider,driver', 'throttle:support-tickets'])->prefix('support-tickets')->group(function () {
    Route::get('/', [SupportTicketController::class, 'index'])->name('support.index');
    Route::post('/', [SupportTicketController::class, 'store'])->name('support.store');
    Route::get('{ticket}', [SupportTicketController::class, 'show'])->whereNumber('ticket')->name('support.show');
    Route::post('{ticket}/messages', [SupportTicketController::class, 'storeMessage'])->whereNumber('ticket')->name('support.messages.store');
});

// Driver onboarding (P4-T4): apply, licence and home zone, vehicles, documents, payout accounts. Driver app only. Each driver only ever
// touches their own records; a suspended account is stopped by the `mobile` gate like everywhere else.
Route::middleware(['auth:sanctum', 'mobile', 'throttle:api', 'ability:driver'])->prefix('driver')->group(function () {
    Route::middleware('throttle:driver-onboarding')->group(function () {
        Route::post('application', DriverApplicationController::class);
        Route::put('profile', DriverProfileController::class);

        Route::get('vehicles', [DriverVehicleController::class, 'index']);
        Route::post('vehicles', [DriverVehicleController::class, 'store']);
        Route::patch('vehicles/{vehicle}', [DriverVehicleController::class, 'update'])->whereNumber('vehicle');
        Route::delete('vehicles/{vehicle}', [DriverVehicleController::class, 'destroy'])->whereNumber('vehicle');

        Route::get('bank-accounts', [DriverBankAccountController::class, 'index']);
        Route::post('bank-accounts', [DriverBankAccountController::class, 'store']);
        Route::delete('bank-accounts/{account}', [DriverBankAccountController::class, 'destroy'])->whereNumber('account');

        Route::get('documents', [DriverDocumentController::class, 'index']);
    });

    Route::post('documents', [DriverDocumentController::class, 'store'])->middleware('throttle:driver-documents');
});

// The driver's own state (P8-T2): where they are and whether they take trips. Driver app only.
Route::middleware(['auth:sanctum', 'mobile', 'throttle:api', 'ability:driver'])->prefix('driver')->group(function () {
    Route::post('location', DriverLocationController::class)->middleware('throttle:driver-location');
    Route::post('availability', DriverAvailabilityController::class)->middleware('throttle:driver-availability');
});

// Trips (P7-T6), rider app: request and cancel (retry-safe through the Idempotency-Key header), the current trip, history and receipts. A rider only
// ever reaches their own trips. `current` is declared before `{trip}` so it is not read as an id.
Route::middleware(['auth:sanctum', 'mobile', 'throttle:api', 'ability:rider', 'throttle:trips'])->prefix('trips')->name('trips.')->group(function () {
    Route::get('current', [TripController::class, 'current'])->name('current');
    Route::get('/', [TripController::class, 'index'])->name('index');
    Route::get('{trip}', [TripController::class, 'show'])->whereNumber('trip')->name('show');
    Route::get('{trip}/receipt', [TripController::class, 'receipt'])->whereNumber('trip')->name('receipt');

    Route::middleware('idempotent')->group(function () {
        Route::post('/', [TripController::class, 'store'])->name('store');
        Route::post('{trip}/cancel', [TripController::class, 'cancel'])->whereNumber('trip')->name('cancel');
    });
});

// Trips (P7-T6), driver app: the trip they are on, finished trips with earnings, and the steps. A driver only ever reaches trips assigned to them.
Route::middleware(['auth:sanctum', 'mobile', 'throttle:api', 'ability:driver', 'throttle:trips'])->prefix('driver')->name('driver.')->group(function () {
    Route::get('trips/current', [DriverTripController::class, 'current'])->name('trips.current');
    Route::get('trips', [DriverTripController::class, 'index'])->name('trips.index');
    Route::get('earnings', DriverEarningsController::class)->name('earnings');

    Route::middleware('idempotent')->prefix('trips/{trip}')->whereNumber('trip')->name('trips.')->group(function () {
        Route::post('arrived', [DriverTripController::class, 'arrived'])->name('arrived');
        Route::post('start', [DriverTripController::class, 'start'])->name('start');
        Route::post('complete', [DriverTripController::class, 'complete'])->name('complete');
        Route::post('cancel', [DriverTripController::class, 'cancel'])->name('cancel');
    });
});

// Address search, pickup pin and route preview for both apps (a phone-less pending session cannot use them). Each call that misses
// the cache costs money, so they share one per-user budget (throttle:geo).
Route::middleware(['auth:sanctum', 'mobile', 'throttle:api', 'ability:rider,driver', 'throttle:geo'])->prefix('geo')->group(function () {
    Route::get('autocomplete', [GeoController::class, 'autocomplete']);
    // Place ids are the provider's own (Google ChIJ…, 2GIS digits): letters, digits, - _ : and . but never starting with a dot, so one cannot climb the provider's URL path.
    Route::get('places/{placeId}', [GeoController::class, 'place'])->where('placeId', '[A-Za-z0-9_:\-][A-Za-z0-9_.:\-]{0,199}');
    Route::get('reverse', [GeoController::class, 'reverse']);
    Route::get('route', [GeoController::class, 'route']);
});

// Service-to-service (P8-T3): the Node real-time service asks who owns a token. Authenticated by the shared service token and the
// caller's address, never by a user token; kept out of the public API docs (docs/realtime-events.md).
Route::middleware(['socket.service', 'throttle:socket-auth'])->prefix('internal')->group(function () {
    Route::post('socket-auth', SocketAuthController::class)->name('internal.socket-auth');
});
