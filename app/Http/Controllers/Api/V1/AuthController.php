<?php

namespace App\Http\Controllers\Api\V1;

use App\Data\MobileSession;
use App\Enums\AppClient;
use App\Enums\SocialProvider;
use App\Exceptions\MobileAuthException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\OtpRequestRequest;
use App\Http\Requests\Api\V1\OtpVerifyRequest;
use App\Http\Requests\Api\V1\PhoneVerifyRequest;
use App\Http\Requests\Api\V1\RefreshTokenRequest;
use App\Http\Requests\Api\V1\SocialLoginRequest;
use App\Http\Resources\Api\V1\MeResource;
use App\Services\MobileAuth\MobileAuthService;
use App\Services\MobileAuth\MobileSessionService;
use App\Services\MobileAuth\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function __construct(
        private readonly OtpService $otp,
        private readonly MobileAuthService $auth,
        private readonly MobileSessionService $sessions,
    ) {}

    /**
     * Send a sign-in code. The answer is the same whether or not the phone has an account.
     */
    public function requestOtp(OtpRequestRequest $request): JsonResponse
    {
        $this->otp->request($request->phone());

        return response()->json([
            'message' => 'If the number can receive a code, it is on its way.',
            'expires_in' => config('taxikosmos.mobile_auth.otp.ttl_minutes') * 60,
        ], 202);
    }

    public function verifyOtp(OtpVerifyRequest $request): JsonResponse
    {
        $session = $this->auth->loginWithPhone(
            AppClient::from($request->validated('app')), $request->phone(), $request->validated('code'),
            $request->validated('device_id'), $request->validated('locale'),
        );

        return $this->sessionResponse($session);
    }

    public function social(SocialLoginRequest $request, string $provider): JsonResponse
    {
        $session = $this->auth->loginWithSocial(
            SocialProvider::from($provider), AppClient::from($request->validated('app')), $request->validated('token'),
            $request->validated('device_id'), $request->validated('locale'),
        );

        return $this->sessionResponse($session);
    }

    /**
     * Collect the phone number of a social sign-in that has none (see needs_phone). Send the code
     * with POST /auth/otp/request first.
     */
    public function verifyPhone(PhoneVerifyRequest $request): JsonResponse
    {
        $session = $this->auth->attachPhone($request->user(), $this->currentToken($request), $request->phone(), $request->validated('code'));

        return $this->sessionResponse($session);
    }

    public function refresh(RefreshTokenRequest $request): JsonResponse
    {
        $session = $this->sessions->refresh($request->validated('refresh_token'));

        return $this->sessionResponse($session ?? throw MobileAuthException::invalidRefreshToken());
    }

    public function logout(Request $request): JsonResponse
    {
        $this->sessions->revokeCurrent($this->currentToken($request));

        return response()->json(status: 204);
    }

    public function logoutAll(Request $request): JsonResponse
    {
        $this->sessions->revokeAll($request->user());

        return response()->json(status: 204);
    }

    private function currentToken(Request $request): PersonalAccessToken
    {
        return $request->user()->currentAccessToken();
    }

    private function sessionResponse(MobileSession $session): JsonResponse
    {
        return response()->json([
            'access_token' => $session->accessToken,
            'access_token_expires_at' => $session->accessTokenExpiresAt->toIso8601String(),
            'refresh_token' => $session->refreshToken,
            'user' => (new MeResource($session->user))->resolve(),
            'needs_phone' => $session->needsPhone,
        ]);
    }
}
