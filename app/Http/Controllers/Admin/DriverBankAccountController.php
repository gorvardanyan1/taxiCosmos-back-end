<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DriverProfile;
use App\Models\User;
use App\Services\Drivers\BankAccountReveal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DriverBankAccountController extends Controller
{
    public function __construct(private readonly BankAccountReveal $reveals) {}

    /**
     * The full account number, once, as JSON for the page to show (never as Inertia props, never cached).
     */
    public function reveal(Request $request, int $driver, int $account): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();
        $profile = DriverProfile::query()->findOrFail($driver);
        $number = $this->reveals->reveal($admin, $profile, $profile->bankAccounts()->findOrFail($account));

        return response()->json(['account_number' => $number])->header('Cache-Control', 'no-store, private');
    }
}
