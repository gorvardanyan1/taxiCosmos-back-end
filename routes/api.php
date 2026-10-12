<?php

use App\Enums\SocialProvider;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\FareEstimateController;
use Illuminate\Support\Facades\Route;

// Mobile surface (rider and driver apps): JSON, Sanctum bearer tokens only, never a session.
Route::prefix('auth')->group(function () {
    Route::post('otp/request', [AuthController::class, 'requestOtp'])->middleware('throttle:otp-request');
    Route::post('otp/verify', [AuthController::class, 'verifyOtp'])->middleware('throttle:otp-verify');
    Route::post('social/{provider}', [AuthController::class, 'social'])
        ->whereIn('provider', array_column(SocialProvider::cases(), 'value'))
        ->middleware('throttle:social-login');
    Route::post('refresh', [AuthController::class, 'refresh'])->middleware('throttle:auth-refresh');

    Route::middleware(['auth:sanctum', 'mobile'])->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('logout-all', [AuthController::class, 'logoutAll']);
        // Only a session that still needs its phone number (social sign-in without one).
        Route::post('phone/verify', [AuthController::class, 'verifyPhone'])->middleware(['ability:pending-phone:rider,pending-phone:driver', 'throttle:otp-verify']);
    });
});

Route::middleware(['auth:sanctum', 'mobile', 'ability:rider'])->prefix('trips')->group(function () {
    // What a trip would cost per vehicle class; returns the quote_id trip creation (P7-T6) refers to.
    Route::post('fare-estimate', FareEstimateController::class)->middleware('throttle:fare-estimate');
});
