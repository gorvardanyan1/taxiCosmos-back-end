<?php

namespace Tests\Feature\Auth;

use App\Enums\AdminRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Tests\Feature\Admin\AdminTestCase;

class HorizonGateTest extends AdminTestCase
{
    public function test_only_active_super_admins_and_admins_may_view_horizon(): void
    {
        $this->assertTrue(Gate::forUser($this->admin(AdminRole::SuperAdmin))->allows('viewHorizon'));
        $this->assertTrue(Gate::forUser($this->admin(AdminRole::Admin))->allows('viewHorizon'));
        $this->assertFalse(Gate::forUser($this->admin(AdminRole::Finance))->allows('viewHorizon'));

        $suspended = $this->admin(AdminRole::Admin);
        $suspended->forceFill(['status' => UserStatus::Suspended])->save();
        $this->assertFalse(Gate::forUser($suspended->fresh())->allows('viewHorizon'));

        $roleWithoutFlag = User::factory()->create();
        $roleWithoutFlag->assignRole(AdminRole::Admin->value);
        $this->assertFalse(Gate::forUser($roleWithoutFlag)->allows('viewHorizon'));
    }
}
