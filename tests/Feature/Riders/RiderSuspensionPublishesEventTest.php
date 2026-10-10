<?php

namespace Tests\Feature\Riders;

use App\Enums\AdminRole;
use App\Models\User;
use App\Realtime\RealtimeEventType;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Realtime\RealtimeTestCase;

/** Real PostgreSQL + real Redis: suspending a rider publishes user.suspended after the commit (P8-T1). */
class RiderSuspensionPublishesEventTest extends RealtimeTestCase
{
    private User $support;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->support = User::factory()->admin(AdminRole::Support)->create();
    }

    public function test_suspending_publishes_one_user_suspended_event_without_the_reason(): void
    {
        $rider = User::factory()->rider()->create();

        $this->actingAs($this->support)->post("/admin/riders/{$rider->id}/suspend", ['reason' => 'Internal: fraud ring 42'])->assertSessionHasNoErrors();

        $this->assertSame(1, $this->totalPublished());
        $entry = $this->entries(RealtimeEventType::UserSuspended)[0];
        $this->assertSame(['user_id' => $rider->id, 'status' => 'suspended'], $entry['envelope']->payload);
        $this->assertStringNotContainsString('fraud ring', $entry['fields']['payload']);
    }

    public function test_reactivating_editing_and_refused_suspensions_publish_nothing(): void
    {
        $suspended = User::factory()->rider()->suspended()->create();
        $active = User::factory()->rider()->create();

        $this->actingAs($this->support)->post("/admin/riders/{$suspended->id}/reactivate", ['reason' => 'Appeal accepted']);
        $this->actingAs($this->support)->patch("/admin/riders/{$active->id}", ['name' => 'Renamed', 'reason' => 'Typo']);
        $this->actingAs($this->support)->postJson("/admin/riders/{$suspended->id}/reactivate", ['reason' => 'Again'])->assertStatus(409);
        $this->actingAs($this->support)->post("/admin/riders/{$active->id}/suspend", ['reason' => ''])->assertSessionHasErrors('reason');

        $this->assertSame(0, $this->totalPublished());
    }

    public function test_a_second_suspension_of_the_same_rider_does_not_publish_again(): void
    {
        $rider = User::factory()->rider()->create();

        $this->actingAs($this->support)->post("/admin/riders/{$rider->id}/suspend", ['reason' => 'First']);
        $this->actingAs($this->support)->postJson("/admin/riders/{$rider->id}/suspend", ['reason' => 'Second'])->assertStatus(409);

        $this->assertSame(1, $this->streamLength(RealtimeEventType::UserSuspended));
    }

    public function test_nothing_is_published_when_the_suspension_rolls_back(): void
    {
        $rider = User::factory()->rider()->create();
        DB::statement('ALTER TABLE activity_log ADD CONSTRAINT force_audit_failure CHECK (false)');

        try {
            $this->withoutExceptionHandling();
            $this->actingAs($this->support)->post("/admin/riders/{$rider->id}/suspend", ['reason' => 'x']);
            $this->fail('The audit insert should have failed.');
        } catch (\Throwable) {
            // expected
        }

        $this->assertSame(0, $this->totalPublished(), 'The event is published only after the commit.');
        $this->assertSame('active', $rider->fresh()->status->value);
    }
}
