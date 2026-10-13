<?php

namespace App\Services\Admin;

use App\Enums\AdminPermission;
use App\Enums\DriverVerificationStatus;
use App\Models\DriverProfile;
use App\Models\User;
use Illuminate\Contracts\Cache\Repository;

/**
 * The counts on the sidebar items (pending driver verifications, open urgent tickets, pending manual payments, pending
 * payouts). The counts are the same for every admin, so they are computed once and cached for 60 seconds; each admin gets only
 * the badges of the pages their permissions open, so a count never leaks to someone who cannot see the page.
 * Keys are the sidebar item keys (resources/js/lib/navigation.tsx).
 */
final class NavigationBadges
{
    public const CACHE_KEY = 'admin:navigation-badges';

    public const CACHE_SECONDS = 60;

    /** Sidebar item => the permission of its page. */
    private const PERMISSIONS = [
        'drivers' => AdminPermission::DriversView,
        'support-tickets' => AdminPermission::SupportManage,
        'transactions' => AdminPermission::PaymentsView,
        'payouts' => AdminPermission::PayoutsView,
    ];

    public function __construct(private readonly Repository $cache) {}

    /**
     * The non-zero badges this admin may see.
     *
     * @return array<string, int>
     */
    public function forAdmin(User $admin): array
    {
        $badges = [];

        foreach ($this->counts() as $key => $count) {
            if ($count > 0 && $admin->can(self::PERMISSIONS[$key]->value)) {
                $badges[$key] = $count;
            }
        }

        return $badges;
    }

    /**
     * @return array<string, int>
     */
    public function counts(): array
    {
        return $this->cache->remember(self::CACHE_KEY, self::CACHE_SECONDS, fn () => [
            'drivers' => $this->pendingDriverVerifications(),
            'support-tickets' => $this->openUrgentTickets(),
            'transactions' => $this->pendingManualPayments(),
            'payouts' => $this->pendingPayouts(),
        ]);
    }

    /** The drivers the list's "pending" verification filter shows. */
    private function pendingDriverVerifications(): int
    {
        return DriverProfile::query()->where('verification_status', DriverVerificationStatus::Pending->value)->count();
    }

    /** No tickets table until the support tickets task (P7-T5) adds it; replace this with its "open and urgent" count there. */
    private function openUrgentTickets(): int
    {
        return 0;
    }

    /** No payments table until the manual payment task (P6-T5) adds it; replace this with the "awaiting confirmation" count there. */
    private function pendingManualPayments(): int
    {
        return 0;
    }

    /** No payouts table until the driver payout task (P6-T8) adds it; replace this with the "pending" count there. */
    private function pendingPayouts(): int
    {
        return 0;
    }
}
