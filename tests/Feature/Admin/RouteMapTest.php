<?php

namespace Tests\Feature\Admin;

use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Every page in the P13-T1 route map renders its Inertia component from server props
 * (fixtures until the domain task is built). assertInertia also checks the .tsx exists.
 */
class RouteMapTest extends AdminTestCase
{
    /**
     * @return array<string, array{string, string, callable(Assert): void}>
     */
    public static function pages(): array
    {
        return [
            'dashboard' => ['/admin', 'Dashboard', fn (Assert $p) => $p
                ->has('metrics', 6)->where('metrics.3.key', 'revenue_today')->where('metrics.3.value', ['amount' => 18400000, 'currency' => 'AMD'])
                ->where('alerts.stuck_trips', 3)->has('revenue_trend.points', 9)->has('trip_status', 4)->has('activity', 7)->where('filters.zone', null)],
            'live map' => ['/admin/live-map', 'LiveMap', fn (Assert $p) => $p->where('stats.online', 142)->has('markers', 6)->where('focused_driver.name', 'Arman Petrosyan')],
            'riders' => ['/admin/riders', 'Riders/Index', fn (Assert $p) => $p
                ->where('riders.total', 0)->where('riders.data', [])->where('totalRegistered', 0)->where('statuses', ['active', 'suspended', 'deactivated', 'pending_deletion'])
                ->where('actions.suspend', '/admin/riders/{id}/suspend')->where('actions.reactivate', '/admin/riders/{id}/reactivate')],
            'drivers' => ['/admin/drivers', 'Drivers/Index', fn (Assert $p) => $p
                ->where('drivers.total', 8)->where('verificationStatuses', ['pending', 'approved', 'rejected', 'expired'])->where('totalRegistered', 5847)],
            'driver detail' => ['/admin/drivers/8', 'Drivers/Show', fn (Assert $p) => $p
                ->where('driver.name', 'Arman Petrosyan')->where('driver.earnings', ['amount' => 18410000, 'currency' => 'AMD'])->where('driver.earnings_detail.balance.amount', -18000)
                ->has('driver.documents', 4)->has('driver.vehicles', 2)->where('tab', 'profile')->has('tabs', 6)],
            'trips' => ['/admin/trips', 'Trips/Index', fn (Assert $p) => $p->where('trips.total', 8)->where('trips.data.0.code', 'TK-8F3K2')->has('statuses', 7)],
            'trip detail' => ['/admin/trips/4', 'Trips/Show', fn (Assert $p) => $p->where('trip.code', 'TK-58200')->where('trip.status', 'in_progress')->where('trip.fare', null)],
            'support tickets' => ['/admin/support-tickets', 'SupportTickets/Index', fn (Assert $p) => $p->where('tickets.total', 4)->where('selected.code', 'TKT-2941')->has('selected.messages', 3)],
            'ratings' => ['/admin/ratings', 'Ratings/Index', fn (Assert $p) => $p->where('ratings.total', 3)->where('lowRatedDrivers', 12)],
            'transactions' => ['/admin/transactions', 'Transactions/Index', fn (Assert $p) => $p->where('transactions.total', 7)->where('summary.refunds_today.amount', 84500)->where('selected', null)],
            'payouts' => ['/admin/payouts', 'Payouts/Index', fn (Assert $p) => $p->where('payouts.total', 3)->where('summary.drivers_in_batch', 284)->has('skipped', 2)],
            'chargebacks' => ['/admin/chargebacks', 'Chargebacks/Index', fn (Assert $p) => $p->where('chargebacks.total', 3)->where('chargebacks.data.0.gateway_dispute_id', 'dp_1QV20a')],
            'driver balances' => ['/admin/driver-balances', 'DriverBalances/Index', fn (Assert $p) => $p->where('balances.total', 3)->where('balances.data.0.balance.amount', -48000)],
            'reports' => ['/admin/reports', 'Reports/Index', fn (Assert $p) => $p->has('series', 5)->where('filters.metric', 'revenue')->where('filters.currency', 'AMD')->has('metrics', 8)],
            'zones' => ['/admin/zones', 'Zones/Index', fn (Assert $p) => $p->where('zones', [])->where('selectedZoneId', null)->where('selectedPolygon', null)->where('fareRules', [])],
            'surge' => ['/admin/surge', 'Surge/Index', fn (Assert $p) => $p->where('surges.total', 3)->where('maxMultiplier', '3.00')],
            'commission rules' => ['/admin/commission-rules', 'CommissionRules/Index', fn (Assert $p) => $p->where('rules.total', 3)->where('previewRule.rate_bp', 1800)],
            'settings users' => ['/admin/settings/users', 'Settings/Users', fn (Assert $p) => $p->has('roles', 5)->has('matrix.finance')],
            'settings gateways' => ['/admin/settings/gateways', 'Settings/Gateways', fn (Assert $p) => $p->has('gateways', 2)->where('gateways.0.masked_key', 'sk_live_…a91F')],
            'settings currencies' => ['/admin/settings/currencies', 'Settings/Currencies', fn (Assert $p) => $p->has('currencies', 4)->where('baseCurrency', 'AMD')->where('currencies.3.active', false)],
            'settings platform' => ['/admin/settings/platform', 'Settings/Platform', fn (Assert $p) => $p->has('settings', 7)],
            'settings maps' => ['/admin/settings/maps', 'Settings/Maps', fn (Assert $p) => $p->where('maps.provider', 'google')],
            'activity log' => ['/admin/activity-logs', 'ActivityLog/Index', fn (Assert $p) => $p->where('entries.total', 0)->where('expandedId', null)->where('expanded', null)->where('actions.export', '/admin/activity-logs/export')],
            'my account' => ['/admin/account', 'Account/Show', fn (Assert $p) => $p->where('tab', 'profile')->has('sessions', 2)->has('notification_preferences', 8)],
        ];
    }

    #[DataProvider('pages')]
    public function test_each_admin_page_renders_its_component_with_props(string $url, string $component, callable $assert): void
    {
        $this->actingAs($this->admin())
            ->get($url)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $assert($page->component($component)));
    }

    /**
     * @return array<string, array{string, string, ?string}>
     */
    public static function authPages(): array
    {
        return [
            'login' => ['/login', 'Auth/Login', '/login'],
            'forgot password' => ['/forgot-password', 'Auth/ForgotPassword', '/forgot-password'],
            'reset password' => ['/reset-password/token-123?email=a%40b.test', 'Auth/ResetPassword', '/reset-password'],
            // POST handlers arrive with P3-T4 (invitations) and P3-T2 (two-factor).
            'accept invitation' => ['/accept-invitation/invite-abc', 'Auth/AcceptInvitation', null],
            'two-factor challenge' => ['/two-factor-challenge', 'Auth/TwoFactorChallenge', null],
        ];
    }

    #[DataProvider('authPages')]
    public function test_each_auth_screen_renders_for_guests_with_its_submit_url(string $url, string $component, ?string $submitUrl): void
    {
        $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->component($component)->where('submitUrl', $submitUrl));
    }

    public function test_reset_password_and_invitation_receive_their_token(): void
    {
        $this->get('/reset-password/token-123?email=a%40b.test')
            ->assertInertia(fn (Assert $page) => $page->where('token', 'token-123')->where('email', 'a@b.test'));
        $this->get('/accept-invitation/invite-abc')
            ->assertInertia(fn (Assert $page) => $page->where('token', 'invite-abc')->where('invitation', null));
    }

    public function test_two_factor_setup_is_for_signed_in_admins(): void
    {
        $this->get('/two-factor-setup')->assertRedirect('/login');

        $this->actingAs($this->admin())->get('/two-factor-setup')
            ->assertOk()->assertInertia(fn (Assert $page) => $page->component('Auth/TwoFactorSetup')->where('setup', null));
    }

    public function test_signed_in_users_are_sent_away_from_guest_screens(): void
    {
        $this->actingAs($this->admin())->get('/login')->assertRedirect();
    }

    public function test_settings_root_redirects_to_the_users_tab(): void
    {
        $this->actingAs($this->admin())->get('/admin/settings')->assertRedirect('/admin/settings/users');
    }
}
