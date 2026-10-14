<?php

namespace Tests\Feature\Account;

use App\Enums\AppClient;
use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * P3-T5: PATCH /api/v1/me changes the name, email and language of the signed-in person, and only those.
 */
class ProfileUpdateApiTest extends AccountTestCase
{
    public function test_it_changes_name_email_and_locale_and_returns_the_new_me(): void
    {
        $user = $this->rider(['name' => 'Old Name', 'locale' => 'en']);

        $response = $this->patchJson('/api/v1/me', ['name' => 'Ani Hakobyan', 'email' => 'Ani@Example.com', 'locale' => 'hy'], $this->as($user))->assertOk();

        $response->assertJsonPath('user.name', 'Ani Hakobyan')->assertJsonPath('user.email', 'ani@example.com')->assertJsonPath('user.locale', 'hy')->assertJsonPath('user.email_verified', false);
        $fresh = $user->fresh();
        $this->assertSame('Ani Hakobyan', $fresh->name);
        $this->assertSame('ani@example.com', $fresh->email, 'one spelling per address');
        $this->assertSame('hy', $fresh->locale);
    }

    public function test_only_the_fields_sent_change(): void
    {
        $user = $this->rider(['name' => 'Keep Me', 'email' => 'keep@example.com', 'locale' => 'ru']);

        $this->patchJson('/api/v1/me', ['name' => 'New'], $this->as($user))->assertOk();

        $fresh = $user->fresh();
        $this->assertSame('New', $fresh->name);
        $this->assertSame('keep@example.com', $fresh->email);
        $this->assertSame('ru', $fresh->locale);
    }

    public function test_a_changed_email_is_no_longer_verified_but_an_unchanged_one_stays_verified(): void
    {
        $user = $this->rider(['email' => 'ann@example.com']);
        $user->forceFill(['email_verified_at' => now()])->save();

        $this->patchJson('/api/v1/me', ['email' => 'ann@example.com', 'name' => 'Ann'], $this->as($user))->assertOk();
        $this->assertNotNull($user->fresh()->email_verified_at, 'sending the same address back changes nothing');

        $this->patchJson('/api/v1/me', ['email' => 'other@example.com'], $this->as($user))->assertOk();
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_the_email_can_be_removed(): void
    {
        $user = $this->rider(['email' => 'ann@example.com']);

        $this->patchJson('/api/v1/me', ['email' => null], $this->as($user))->assertOk()->assertJsonPath('user.email', null);

        $this->assertNull($user->fresh()->email);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalid(): array
    {
        return [
            'empty name' => [['name' => ''], 'name'],
            'name too long' => [['name' => str_repeat('a', 121)], 'name'],
            'name not a string' => [['name' => ['x']], 'name'],
            'not an email' => [['email' => 'not-an-email'], 'email'],
            'email too long' => [['email' => str_repeat('a', 251).'@x.io'], 'email'],
            'unsupported language' => [['locale' => 'fr'], 'locale'],
            'empty language' => [['locale' => ''], 'locale'],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('invalid')]
    public function test_invalid_input_is_refused_and_nothing_is_saved(array $body, string $field): void
    {
        $user = $this->rider(['name' => 'Before', 'email' => 'before@example.com', 'locale' => 'en']);

        $this->patchJson('/api/v1/me', $body, $this->as($user))->assertStatus(422)->assertJsonValidationErrors($field);

        $fresh = $user->fresh();
        $this->assertSame(['Before', 'before@example.com', 'en'], [$fresh->name, $fresh->email, $fresh->locale]);
    }

    public function test_the_boundary_name_length_is_accepted(): void
    {
        $user = $this->rider();

        $this->patchJson('/api/v1/me', ['name' => str_repeat('a', 120)], $this->as($user))->assertOk();
    }

    public function test_an_address_that_belongs_to_another_account_is_refused_whatever_its_case(): void
    {
        User::factory()->rider()->create(['email' => 'taken@example.com']);
        $user = $this->rider(['email' => 'mine@example.com']);

        $this->patchJson('/api/v1/me', ['email' => 'TAKEN@example.com'], $this->as($user))->assertStatus(422)->assertJsonValidationErrors('email');

        $this->assertSame('mine@example.com', $user->fresh()->email);
    }

    public function test_an_address_held_by_a_deleted_account_is_refused_too(): void
    {
        $gone = User::factory()->rider()->create(['email' => 'gone@example.com']);
        $gone->delete();
        $user = $this->rider();

        $this->patchJson('/api/v1/me', ['email' => 'gone@example.com'], $this->as($user))->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_it_cannot_change_status_phone_or_capabilities_or_name_another_user(): void
    {
        $user = $this->rider();
        $other = User::factory()->rider()->create(['name' => 'Other']);

        $this->patchJson('/api/v1/me', [
            'id' => $other->id, 'user_id' => $other->id, 'status' => 'suspended', 'is_driver' => true, 'is_admin' => true,
            'phone' => '+37499000000', 'rider_rating_avg' => 5, 'name' => 'Mine',
        ], $this->as($user))->assertOk();

        $fresh = $user->fresh();
        $this->assertSame('Mine', $fresh->name);
        $this->assertSame('active', $fresh->status->value);
        $this->assertFalse($fresh->is_driver);
        $this->assertFalse($fresh->is_admin);
        $this->assertSame('+37491210443', $fresh->phone);
        $this->assertNull($fresh->rider_rating_avg);
        $this->assertSame('Other', $other->fresh()->name);
    }

    public function test_a_driver_can_update_their_profile_too(): void
    {
        $driver = User::factory()->driver()->withPhone('+37491770001')->create();

        $this->patchJson('/api/v1/me', ['name' => 'Driver Dan'], $this->as($driver, AppClient::Driver))->assertOk()->assertJsonPath('user.name', 'Driver Dan');
    }

    public function test_a_guest_and_a_session_without_a_phone_are_refused(): void
    {
        $this->patchJson('/api/v1/me', ['name' => 'X'])->assertUnauthorized();

        $pending = User::factory()->rider()->create();
        $this->patchJson('/api/v1/me', ['name' => 'X'], $this->as($pending))->assertForbidden();
        $this->assertNotSame('X', $pending->fresh()->name);
    }
}
