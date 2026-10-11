<?php

namespace Tests\Feature\E2e;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use RuntimeException;
use Tests\E2e\E2eFixtures;
use Tests\TestCase;

/**
 * The Cypress helpers rebuild and edit a database, so the guards around them are tested here.
 */
class E2eToolingGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_helpers_refuse_to_run_outside_the_e2e_and_testing_environments(): void
    {
        foreach (['local', 'staging', 'production'] as $environment) {
            $this->app['env'] = $environment;

            foreach ([['e2e:reset', []], ['e2e:sql', ['query' => 'select 1']], ['e2e:make', ['fixture' => 'admin', 'attributes' => '{"email":"x@y.z"}']]] as [$command, $arguments]) {
                try {
                    Artisan::call($command, $arguments);
                    $this->fail("{$command} ran in {$environment}.");
                } catch (RuntimeException $e) {
                    $this->assertStringContainsString("\"{$environment}\" environment", $e->getMessage());
                }
            }
        }

        $this->assertSame(0, User::query()->count());
    }

    public function test_the_helpers_refuse_a_database_that_is_not_a_throwaway_one(): void
    {
        config(['database.connections.pgsql.database' => 'taxikosmos']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('"taxikosmos" database');

        Artisan::call('e2e:reset');
    }

    public function test_sql_is_read_only_and_single_statement(): void
    {
        User::factory()->create();

        foreach (['delete from users', 'select 1; delete from users', 'update users set name = \'x\''] as $query) {
            try {
                Artisan::call('e2e:sql', ['query' => $query]);
                $this->fail("Accepted: {$query}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        // A data-modifying CTE gets past the prefix check but not the READ ONLY transaction.
        try {
            Artisan::call('e2e:sql', ['query' => 'with x as (delete from users returning id) select * from x']);
            $this->fail('The CTE delete ran.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('read-only transaction', $e->getMessage());
        }

        $this->assertSame(1, User::query()->count());

        Artisan::call('e2e:sql', ['query' => 'select count(*) as n from users where id > ?', 'bindings' => '[0]']);
        $this->assertSame([['n' => 1]], json_decode(trim(Artisan::output()), true));
    }

    public function test_the_admin_fixture_creates_an_admin_who_can_sign_in(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        Artisan::call('e2e:make', ['fixture' => 'admin', 'attributes' => '{"email":"cy-support@taxikosmos.test","role":"support"}']);
        $result = json_decode(trim(Artisan::output()), true);

        $admin = User::query()->findOrFail($result['id']);
        $this->assertTrue($admin->hasAdminAccess());
        $this->assertTrue($admin->hasRole('support'));
        $this->assertTrue(Hash::check(E2eFixtures::PASSWORD, $admin->password));
    }

    public function test_unknown_fixtures_are_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Artisan::call('e2e:make', ['fixture' => 'drop_everything']);
    }
}
