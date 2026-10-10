<?php

namespace App\Console\Commands;

use App\Enums\AdminRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Bootstrap an admin account before admin user management (P3-T4) exists.
 */
#[Signature('admin:create {email} {--role=super_admin : One of super_admin, admin, support, finance, dispatcher}')]
#[Description('Create an admin user (prompts for name and password)')]
class CreateAdmin extends Command
{
    public function handle(): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));
        $role = (string) $this->option('role');

        $input = Validator::make(['email' => $email, 'role' => $role], [
            'email' => ['required', 'email:rfc', 'max:255'],
            'role' => ['required', Rule::enum(AdminRole::class)],
        ]);
        if ($input->fails()) {
            return $this->failWith($input->errors()->all());
        }

        $existing = User::withTrashed()->where('email', $email)->first();
        if ($existing !== null) {
            return $this->failWith([$existing->is_admin
                ? "An admin with the email {$email} already exists."
                : "The email {$email} belongs to a non-admin account; refusing to turn it into an admin."]);
        }

        $name = trim((string) $this->ask('Name'));
        $password = (string) $this->secret('Password (min 12 chars, mixed case, number, symbol)');
        $confirmation = (string) $this->secret('Confirm password');

        $details = Validator::make(
            ['name' => $name, 'password' => $password, 'password_confirmation' => $confirmation],
            ['name' => ['required', 'string', 'max:255'], 'password' => ['required', 'string', Password::defaults(), 'confirmed']],
        );
        if ($details->fails()) {
            return $this->failWith($details->errors()->all());
        }

        $user = DB::transaction(function () use ($email, $name, $password, $role) {
            $user = new User(['name' => $name, 'email' => $email, 'password' => $password]);
            $user->forceFill(['is_admin' => true, 'status' => UserStatus::Active, 'email_verified_at' => now()])->save();
            $user->assignRole($role);

            return $user;
        });

        $this->info("Admin {$user->email} created with role {$role}.");

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $messages
     */
    private function failWith(array $messages): int
    {
        foreach ($messages as $message) {
            $this->error($message);
        }

        return self::FAILURE;
    }
}
