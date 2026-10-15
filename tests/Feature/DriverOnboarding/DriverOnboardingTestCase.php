<?php

namespace Tests\Feature\DriverOnboarding;

use App\Enums\AdminRole;
use App\Enums\AppClient;
use App\Models\Audit\ActivityLogEntry;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Account\AccountTestCase;

/**
 * Real PostgreSQL, real sessions and tokens through the real session service, a fake private documents disk. A driver signs in
 * through the driver app (which is what sets their driver flag), so the tokens carry the driver ability.
 */
abstract class DriverOnboardingTestCase extends AccountTestCase
{
    protected const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private int $phones = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake(config('taxikosmos.documents.disk'));
        config(['taxikosmos.documents.required' => ['license', 'id_card', 'vehicle_registration', 'insurance']]);
    }

    /** A person who signed in through the driver app: a driver flag, no application yet. */
    protected function newDriver(array $attributes = []): User
    {
        return User::factory()->driver()->withPhone('+3749173'.str_pad((string) ++$this->phones, 4, '0', STR_PAD_LEFT))->create($attributes);
    }

    /** @var array<int, array<string, string>> */
    private array $sessions = [];

    /**
     * One session per person for the whole test: signing in again on the same device ends the earlier session.
     *
     * @return array<string, string>
     */
    protected function asDriver(User $user): array
    {
        return $this->sessions[$user->id] ??= $this->as($user, AppClient::Driver);
    }

    protected function admin(AdminRole $role = AdminRole::Admin): User
    {
        return User::factory()->admin($role)->create();
    }

    protected function pdf(string $name = 'paper.pdf', string $extra = ''): UploadedFile
    {
        return $this->fileOf($name, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF".$extra);
    }

    protected function png(string $name = 'paper.png'): UploadedFile
    {
        return $this->fileOf($name, base64_decode(self::PNG));
    }

    /** A real file on disk, so the type is sniffed from its content like in production. */
    protected function fileOf(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'doc');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    protected function entries(string $action)
    {
        return ActivityLogEntry::query()->where('description', $action)->get();
    }

    /** @return array<string, mixed> */
    protected function vehicle(array $over = []): array
    {
        return array_merge(['make' => 'Toyota', 'model' => 'Camry', 'year' => 2020, 'color' => 'White', 'plate_number' => '01 AB 234', 'vehicle_class' => 'economy'], $over);
    }

    /** @return array<string, mixed> */
    protected function bankAccount(array $over = []): array
    {
        return array_merge(['account_holder' => 'Aram Sargsyan', 'bank_name' => 'Ameriabank', 'account_number' => 'AM00 1234 5678 9012', 'swift' => 'ARMIAM22'], $over);
    }
}
