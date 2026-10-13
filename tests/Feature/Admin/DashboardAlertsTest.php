<?php

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * P13-T2: a dashboard alert links to a page, so its card (and its count) goes only to admins who may open that page.
 */
class DashboardAlertsTest extends AdminTestCase
{
    /**
     * @return array<string, array{AdminRole, array<string, int>}>
     */
    public static function alertsByRole(): array
    {
        return [
            'super admin' => [AdminRole::SuperAdmin, ['stuck_trips' => 3, 'failed_payments' => 5, 'urgent_tickets' => 1]],
            'admin' => [AdminRole::Admin, ['stuck_trips' => 3, 'failed_payments' => 5, 'urgent_tickets' => 1]],
            'support' => [AdminRole::Support, ['stuck_trips' => 3, 'failed_payments' => 5, 'urgent_tickets' => 1]],
            'finance' => [AdminRole::Finance, ['stuck_trips' => 3, 'failed_payments' => 5]],
            'dispatcher' => [AdminRole::Dispatcher, ['stuck_trips' => 3]],
        ];
    }

    /**
     * @param  array<string, int>  $expected
     */
    #[DataProvider('alertsByRole')]
    public function test_each_role_gets_only_the_alerts_whose_page_it_may_open(AdminRole $role, array $expected): void
    {
        $this->actingAs($this->admin($role))->get('/admin')
            ->assertInertia(fn (Assert $page) => $page->where('alerts', $expected));
    }

    public function test_every_alert_a_role_receives_leads_to_a_page_that_role_can_open(): void
    {
        $pages = ['stuck_trips' => '/admin/trips', 'failed_payments' => '/admin/transactions', 'urgent_tickets' => '/admin/support-tickets'];

        foreach (AdminRole::cases() as $role) {
            $admin = $this->admin($role);
            $alerts = [];
            $this->actingAs($admin)->get('/admin')->assertInertia(function (Assert $page) use (&$alerts) {
                $alerts = $page->toArray()['props']['alerts'];
            });

            foreach (array_keys($alerts) as $key) {
                $this->actingAs($admin)->get($pages[$key])->assertOk();
            }
        }
    }
}
