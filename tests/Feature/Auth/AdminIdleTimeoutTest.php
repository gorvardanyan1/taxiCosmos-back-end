<?php

namespace Tests\Feature\Auth;

use App\Enums\AdminRole;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Admin\AdminTestCase;

class AdminIdleTimeoutTest extends AdminTestCase
{
    public function test_activity_keeps_the_session_alive(): void
    {
        $this->actingAs($this->admin(AdminRole::Admin));
        $this->travelTo(now()->startOfMinute());

        $this->get('/admin')->assertOk();
        $this->travel(59)->minutes();
        $this->get('/admin/riders')->assertOk();
        $this->travel(59)->minutes();
        $this->get('/admin/trips')->assertOk();
        $this->assertAuthenticated();
    }

    public function test_after_the_idle_timeout_the_admin_is_signed_out_and_sees_a_message_on_login(): void
    {
        $this->actingAs($this->admin(AdminRole::Admin));
        $this->travelTo(now()->startOfMinute());
        $this->get('/admin')->assertOk();

        $this->travel(61)->minutes();

        $this->get('/admin/riders')
            ->assertRedirect('/login')
            ->assertSessionHas('status', 'Your session expired after 60 minutes of inactivity. Please sign in again.');
        $this->assertGuest();

        $this->get('/login')->assertInertia(fn (Assert $page) => $page->component('Auth/Login')
            ->where('status', 'Your session expired after 60 minutes of inactivity. Please sign in again.'));
        $this->get('/admin')->assertRedirect('/login');
    }

    public function test_exactly_at_the_limit_is_still_allowed(): void
    {
        $this->actingAs($this->admin(AdminRole::Admin));
        $this->travelTo(now()->startOfMinute());
        $this->get('/admin')->assertOk();

        $this->travel(60)->minutes();

        $this->get('/admin')->assertOk();
    }

    public function test_the_timeout_is_configurable_and_json_requests_get_401(): void
    {
        config(['taxikosmos.admin.idle_timeout_minutes' => 5]);
        $this->actingAs($this->admin(AdminRole::Admin));
        $this->travelTo(now()->startOfMinute());
        $this->get('/admin')->assertOk();

        $this->travel(6)->minutes();

        $this->getJson('/admin/riders')->assertUnauthorized()->assertJson(['message' => 'Your session expired after 5 minutes of inactivity. Please sign in again.']);
        $this->assertGuest();
    }
}
