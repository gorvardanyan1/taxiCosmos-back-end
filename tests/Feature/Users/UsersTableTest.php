<?php

namespace Tests\Feature\Users;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UsersTableTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_table_has_the_platform_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('users', [
            'id', 'name', 'email', 'phone', 'phone_hash', 'password', 'is_rider', 'is_driver', 'is_admin',
            'status', 'suspension_reason', 'locale', 'timezone', 'rider_rating_avg', 'rider_rating_count',
            'last_login_at', 'created_at', 'updated_at', 'deleted_at',
        ]));
    }

    public function test_a_phone_only_user_needs_no_name_email_or_password_and_gets_safe_defaults(): void
    {
        $user = User::create(['phone' => '+15550000001']);

        $row = DB::table('users')->where('id', $user->id)->first();

        $this->assertNull($row->name);
        $this->assertNull($row->email);
        $this->assertNull($row->password);
        $this->assertFalse($row->is_rider);
        $this->assertFalse($row->is_driver);
        $this->assertFalse($row->is_admin);
        $this->assertSame('active', $row->status);
        $this->assertSame(0, $row->rider_rating_count);
        $this->assertNull($row->rider_rating_avg);
        $this->assertNull($row->deleted_at);

        $fresh = $user->fresh();
        $this->assertSame(UserStatus::Active, $fresh->status);
        $this->assertTrue($fresh->isActive());
    }

    public function test_status_is_cast_to_the_enum_and_persisted(): void
    {
        $user = User::factory()->suspended('Fraudulent chargebacks')->create();

        $fresh = $user->fresh();
        $this->assertSame(UserStatus::Suspended, $fresh->status);
        $this->assertSame('Fraudulent chargebacks', $fresh->suspension_reason);
        $this->assertFalse($fresh->isActive());
        $this->assertDatabaseHas('users', ['id' => $user->id, 'status' => 'suspended']);
    }

    public function test_database_rejects_an_unknown_status(): void
    {
        $user = User::factory()->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('users_status_check');

        DB::table('users')->where('id', $user->id)->update(['status' => 'banned']);
    }

    public function test_database_rejects_a_rider_rating_outside_one_to_five(): void
    {
        $user = User::factory()->create();

        DB::table('users')->where('id', $user->id)->update(['rider_rating_avg' => 4.87]);
        $this->assertSame('4.87', $user->fresh()->rider_rating_avg);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('users_rider_rating_avg_check');

        DB::table('users')->where('id', $user->id)->update(['rider_rating_avg' => 5.5]);
    }

    public function test_flags_and_status_are_not_mass_assignable(): void
    {
        $user = User::create([
            'name' => 'Mallory',
            'phone' => '+15550000002',
            'is_admin' => true,
            'is_driver' => true,
            'status' => 'suspended',
            'rider_rating_avg' => 5,
        ]);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'is_admin' => false,
            'is_driver' => false,
            'status' => 'active',
            'rider_rating_avg' => null,
        ]);
    }

    public function test_users_are_soft_deleted(): void
    {
        $user = User::factory()->create();

        $user->delete();

        $this->assertSoftDeleted('users', ['id' => $user->id]);
        $this->assertNull(User::find($user->id));
        $this->assertNotNull(User::withTrashed()->find($user->id));
    }
}
