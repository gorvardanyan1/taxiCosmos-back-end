<?php

namespace Tests\Feature\Realtime;

use App\Enums\DriverAvailability;
use App\Enums\DriverVerificationStatus;
use App\Enums\UserStatus;
use App\Models\DriverProfile;
use App\Models\User;
use App\Realtime\RealtimeEventType;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/** Real model changes raise the matching events (via observers) and nothing else does. */
class StateChangeEventsTest extends RealtimeTestCase
{
    private function approve(DriverProfile $profile): void
    {
        $profile->forceFill(['verification_status' => DriverVerificationStatus::Approved, 'approved_at' => now()])->save();
    }

    public function test_changing_verification_status_publishes_driver_verification_changed(): void
    {
        $profile = DriverProfile::factory()->create();
        $this->assertSame(0, $this->totalPublished(), 'Creating a profile is not a state change.');

        $this->approve($profile);

        $this->assertSame(1, $this->streamLength(RealtimeEventType::DriverVerificationChanged));
        $this->assertSame(1, $this->totalPublished());
        $this->assertSame(
            ['driver_user_id' => $profile->user_id, 'driver_profile_id' => $profile->id, 'from_status' => 'pending', 'to_status' => 'approved'],
            $this->entries(RealtimeEventType::DriverVerificationChanged)[0]['envelope']->payload,
        );
    }

    public function test_every_further_verification_transition_reports_the_previous_status(): void
    {
        $profile = DriverProfile::factory()->approved()->create();
        $profile->forceFill(['verification_status' => DriverVerificationStatus::Expired])->save();

        $payload = $this->entries(RealtimeEventType::DriverVerificationChanged)[0]['envelope']->payload;
        $this->assertSame(['approved', 'expired'], [$payload['from_status'], $payload['to_status']]);
    }

    public function test_changing_availability_publishes_driver_availability_changed(): void
    {
        $profile = DriverProfile::factory()->approved()->create();

        $profile->forceFill(['availability' => DriverAvailability::Online, 'last_online_at' => now()])->save();
        $profile->forceFill(['availability' => DriverAvailability::OnTrip])->save();

        $payloads = array_map(fn ($e) => $e['envelope']->payload, $this->entries(RealtimeEventType::DriverAvailabilityChanged));
        $this->assertSame([['offline', 'online'], ['online', 'on_trip']], array_map(fn ($p) => [$p['from_availability'], $p['to_availability']], $payloads));
        $this->assertSame($profile->user_id, $payloads[0]['driver_user_id']);
        $this->assertSame(0, $this->streamLength(RealtimeEventType::DriverVerificationChanged));
    }

    public function test_changes_to_other_columns_and_unchanged_saves_publish_nothing(): void
    {
        $profile = DriverProfile::factory()->create();

        $profile->update(['license_expiry' => now()->addYear()->toDateString()]);
        $profile->forceFill(['rating_count' => 3])->save();
        $profile->forceFill(['verification_status' => DriverVerificationStatus::Pending])->save(); // same value
        $profile->save();

        $this->assertSame(0, $this->totalPublished());
    }

    public function test_changing_both_fields_at_once_publishes_one_event_on_each_stream(): void
    {
        $profile = DriverProfile::factory()->create();

        $profile->forceFill(['verification_status' => DriverVerificationStatus::Approved, 'availability' => DriverAvailability::Online])->save();

        $this->assertSame(1, $this->streamLength(RealtimeEventType::DriverVerificationChanged));
        $this->assertSame(1, $this->streamLength(RealtimeEventType::DriverAvailabilityChanged));
        $this->assertSame(2, $this->totalPublished());
    }

    public function test_a_driver_change_in_a_rolled_back_transaction_is_never_published(): void
    {
        $profile = DriverProfile::factory()->create();

        try {
            DB::transaction(function () use ($profile) {
                $this->approve($profile);
                $profile->forceFill(['availability' => DriverAvailability::Online])->save();

                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(0, $this->totalPublished());
        $this->assertSame('pending', $profile->fresh()->verification_status->value, 'The database change was rolled back too.');
    }

    public function test_a_committed_driver_change_is_published_only_after_the_commit(): void
    {
        $profile = DriverProfile::factory()->create();
        $inside = null;

        DB::transaction(function () use ($profile, &$inside) {
            $this->approve($profile);
            $inside = $this->totalPublished();
        });

        $this->assertSame(0, $inside);
        $this->assertSame(1, $this->streamLength(RealtimeEventType::DriverVerificationChanged));
    }

    /**
     * @return array<string, array{UserStatus}>
     */
    public static function nonActiveStatuses(): array
    {
        return [
            'suspended' => [UserStatus::Suspended],
            'deactivated' => [UserStatus::Deactivated],
            'pending deletion' => [UserStatus::PendingDeletion],
        ];
    }

    #[DataProvider('nonActiveStatuses')]
    public function test_a_user_who_is_no_longer_active_gets_a_user_suspended_event_so_sockets_are_dropped(UserStatus $status): void
    {
        $user = User::factory()->rider()->create();

        $user->forceFill(['status' => $status, 'suspension_reason' => 'Fraud ring'])->save();

        $this->assertSame(1, $this->totalPublished());
        $entry = $this->entries(RealtimeEventType::UserSuspended)[0];
        $this->assertSame(['user_id' => $user->id, 'status' => $status->value], $entry['envelope']->payload);
        $this->assertStringNotContainsString('Fraud ring', $entry['fields']['payload'], 'The internal reason is never published.');
    }

    public function test_reactivating_or_editing_a_user_publishes_nothing(): void
    {
        $user = User::factory()->suspended()->create();
        $this->assertSame(0, $this->totalPublished(), 'Creating a suspended user is not a transition.');

        $user->forceFill(['status' => UserStatus::Active, 'suspension_reason' => null])->save();
        $user->update(['name' => 'Renamed', 'locale' => 'hy']);
        $user->forceFill(['is_driver' => true])->save();

        $this->assertSame(0, $this->totalPublished());
    }

    public function test_a_suspension_in_a_rolled_back_transaction_is_not_published(): void
    {
        $user = User::factory()->rider()->create();

        try {
            DB::transaction(function () use ($user) {
                $user->forceFill(['status' => UserStatus::Suspended])->save();

                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(0, $this->totalPublished());
        $this->assertSame(UserStatus::Active, $user->fresh()->status);
    }

    public function test_suspending_a_user_who_is_also_a_driver_publishes_a_single_user_event(): void
    {
        $profile = DriverProfile::factory()->approved()->create();

        $profile->user->forceFill(['status' => UserStatus::Suspended])->save();

        $this->assertSame(1, $this->streamLength(RealtimeEventType::UserSuspended));
        $this->assertSame(1, $this->totalPublished());
    }
}
