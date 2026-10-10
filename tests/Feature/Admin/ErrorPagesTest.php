<?php

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use Inertia\Testing\AssertableInertia as Assert;

class ErrorPagesTest extends AdminTestCase
{
    public function test_outside_debug_mode_errors_render_the_inertia_error_page(): void
    {
        config(['app.debug' => false]);
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/riders/999')
            ->assertNotFound()
            ->assertInertia(fn (Assert $page) => $page->component('Errors/Show')->where('status', 404));

        $this->actingAs($this->admin(AdminRole::Dispatcher))->get('/admin/transactions')
            ->assertForbidden()
            ->assertInertia(fn (Assert $page) => $page->component('Errors/Show')->where('status', 403));
    }

    public function test_in_debug_mode_the_framework_error_page_is_kept(): void
    {
        config(['app.debug' => true]);

        $response = $this->actingAs($this->admin())->get('/admin/riders/999')->assertNotFound();

        $this->assertStringNotContainsString('Errors/Show', $response->getContent());
    }

    public function test_api_errors_stay_json(): void
    {
        config(['app.debug' => false]);

        $this->getJson('/api/v1/does-not-exist')->assertNotFound()->assertJsonStructure(['message']);
    }
}
