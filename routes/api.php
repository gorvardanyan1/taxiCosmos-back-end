<?php

use App\Enums\SocialProvider;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\DriverAvailabilityController;
use App\Http\Controllers\Api\V1\DriverLocationController;
use App\Http\Controllers\Api\V1\FareEstimateController;
use App\Http\Controllers\Api\V1\GeoController;
use App\Http\Controllers\Api\V1\Internal\SocketAuthController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\SavedPlaceController;
use App\Http\Controllers\Api\V1\SupportTicketController;
use Illuminate\Support\Facades\Route;

// Mobile surface (rider and driver apps): JSON, Sanctum bearer tokens only, never a session.
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

// The driver's own state (P8-T2): where they are and whether they take trips. Driver app only.
Route::middleware(['auth:sanctum', 'mobile', 'throttle:api', 'ability:driver'])->prefix('driver')->group(function () {
    Route::post('location', DriverLocationController::class)->middleware('throttle:driver-location');
    Route::post('availability', DriverAvailabilityController::class)->middleware('throttle:driver-availability');
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
