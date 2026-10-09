<?php

namespace Tests\Feature\Users;

use App\Models\User;
use App\Support\BlindIndex;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RiderDriverAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_hold_both_rider_and_driver_flags_on_a_single_row(): void
    {
        $user = User::factory()->rider()->driver()->withPhone('+15551230001')->create();

        $this->assertSame(1, DB::table('users')->count());
        $this->assertDatabaseHas('users', ['id' => $user->id, 'is_rider' => true, 'is_driver' => true]);

        $found = User::query()->wherePhone('+15551230001')->sole();
        $this->assertTrue($found->is($user));
        $this->assertTrue($found->is_rider);
        $this->assertTrue($found->is_driver);
    }

    public function test_a_rider_who_becomes_a_driver_keeps_the_same_row(): void
    {
        $rider = User::factory()->rider()->withPhone('+15551230002')->create();

        $rider->enableDriverMode();
        // Repeating the call (e.g. a retried driver application) changes nothing.
        $rider->enableDriverMode();
        $rider->enableRiderMode();

        $this->assertSame(1, User::query()->wherePhone('+15551230002')->count());
        $this->assertSame(1, DB::table('users')->count());
        $this->assertDatabaseHas('users', ['id' => $rider->id, 'is_rider' => true, 'is_driver' => true, 'is_admin' => false]);
    }

    public function test_a_driver_can_become_a_rider_without_a_second_account(): void
    {
        $driver = User::factory()->driver()->withPhone('+15551230003')->create();

        $driver->enableRiderMode();

        $this->assertSame(1, DB::table('users')->count());
        $fresh = $driver->fresh();
        $this->assertTrue($fresh->is_rider);
        $this->assertTrue($fresh->is_driver);
    }

    public function test_the_same_phone_cannot_create_a_second_account(): void
    {
        User::factory()->rider()->withPhone('+15551230004')->create();

        try {
            // Savepoint so the test transaction survives the expected violation.
            DB::transaction(fn () => User::factory()->driver()->withPhone('+15551230004')->create());
            $this->fail('A duplicate phone must violate the phone_hash unique index.');
        } catch (UniqueConstraintViolationException $e) {
            $this->assertStringContainsString('users_phone_hash_unique', $e->getMessage());
        }

        $this->assertSame(1, DB::table('users')->count());
    }

    public function test_phone_is_stored_encrypted_with_an_hmac_blind_index(): void
    {
        $user = User::factory()->withPhone('+15551230005')->create();

        $row = DB::table('users')->where('id', $user->id)->first();

        $this->assertNotSame('+15551230005', $row->phone);
        $this->assertStringNotContainsString('15551230005', $row->phone);
        $this->assertSame('+15551230005', Crypt::decryptString($row->phone));
        $this->assertSame(
            hash_hmac('sha256', '+15551230005', config('app.blind_index_key')),
            $row->phone_hash,
        );
        $this->assertSame(app(BlindIndex::class)->hash('+15551230005'), $row->phone_hash);
        $this->assertSame('+15551230005', $user->fresh()->phone);
    }

    public function test_changing_the_phone_updates_the_blind_index_and_clearing_it_removes_it(): void
    {
        $user = User::factory()->withPhone('+15551230006')->create();

        $user->update(['phone' => '+15551230007']);

        $this->assertSame(0, User::query()->wherePhone('+15551230006')->count());
        $this->assertTrue(User::query()->wherePhone('+15551230007')->sole()->is($user));

        $user->update(['phone' => null]);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'phone' => null, 'phone_hash' => null]);
        $this->assertSame(0, User::query()->wherePhone('+15551230007')->count());
    }

    public function test_phone_lookup_does_not_match_other_numbers(): void
    {
        User::factory()->withPhone('+15551230008')->create();

        $this->assertSame(0, User::query()->wherePhone('+15551230009')->count());
        $this->assertSame(0, User::query()->wherePhone('15551230008')->count());
    }

    public function test_phone_and_its_hash_are_never_serialised(): void
    {
        $user = User::factory()->withPhone('+15551230010')->create();

        $array = $user->fresh()->toArray();

        $this->assertArrayNotHasKey('phone', $array);
        $this->assertArrayNotHasKey('phone_hash', $array);
        $this->assertArrayNotHasKey('password', $array);
        $this->assertStringNotContainsString('15551230010', $user->fresh()->toJson());
    }
}
