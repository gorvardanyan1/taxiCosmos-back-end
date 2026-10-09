<?php

namespace Database\Seeders;

use App\Enums\AdminRole;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        if (app()->environment(['local', 'testing'])) {
            $user = User::query()->where('email', 'test@example.com')->first()
                ?? User::factory()->create(['name' => 'Test User', 'email' => 'test@example.com']);
            $user->forceFill(['is_admin' => true])->save();
            $user->syncRoles([AdminRole::SuperAdmin->value]);
        }
    }
}
