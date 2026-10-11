<?php

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Models\Audit\ActivityLogEntry;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A NUL byte in input is refused up front: PostgreSQL cannot store it and the driver would cut the
 * value at that byte, saving a shortened name or email that validation never saw.
 */
class NullByteInputTest extends AdminTestCase
{
    private User $support;

    private User $rider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->support = $this->admin(AdminRole::Support);
        $this->rider = User::factory()->rider()->create(['name' => 'Priya Mehta', 'email' => 'priya@example.com']);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function editsWithNul(): array
    {
        return [
            'name' => [['name' => "a\0b"]],
            'email' => [['email' => "x\0y@example.com"]],
            'reason' => [['name' => 'Fine', 'reason' => "r\0x"]],
            'escaped in JSON' => [['name' => json_decode('"a\u0000b"')]],
        ];
    }

    #[DataProvider('editsWithNul')]
    public function test_a_rider_edit_with_a_nul_byte_is_refused_and_nothing_is_saved(array $body): void
    {
        $this->actingAs($this->support)->patchJson("/admin/riders/{$this->rider->id}", ['reason' => 'Rider asked', ...$body])
            ->assertStatus(400)->assertJson(['message' => 'The request contains invalid characters.']);

        $fresh = $this->rider->fresh();
        $this->assertSame(['Priya Mehta', 'priya@example.com'], [$fresh->name, $fresh->email]);
        $this->assertSame(0, ActivityLogEntry::where('description', 'rider.updated')->count());
    }

    public function test_the_same_goes_for_form_posts_and_the_suspension_reason(): void
    {
        $this->actingAs($this->support)->post("/admin/riders/{$this->rider->id}/suspend", ['reason' => "x\0y"])->assertStatus(400);
        $this->actingAs($this->support)->patch("/admin/riders/{$this->rider->id}", ['reason' => 'ok', 'name' => "a\0b"])->assertStatus(400);

        $this->assertSame('active', $this->rider->fresh()->status->value);
        $this->assertNull($this->rider->fresh()->suspension_reason);
    }

    public function test_query_strings_nested_values_and_keys_are_checked_too(): void
    {
        $admin = $this->actingAs($this->support);

        $admin->get('/admin/riders?filter[search]=a%00b')->assertStatus(400);
        $admin->get('/admin/riders?filter[search][]=a%00b')->assertStatus(400);
        $admin->patchJson("/admin/riders/{$this->rider->id}", ['reason' => 'ok', 'name' => 'Fine', 'extra' => ['deep' => ['er' => "a\0b"]]])->assertStatus(400);
        $admin->patchJson("/admin/riders/{$this->rider->id}", ['reason' => 'ok', 'name' => 'Fine', "ke\0y" => 'v'])->assertStatus(400);
    }

    public function test_the_sign_in_form_and_the_api_group_are_protected_as_well(): void
    {
        $this->post('/login', ['email' => "a\0@example.com", 'password' => 'x'])->assertStatus(400);

        Route::middleware('api')->post('/api/v1/nul-probe', fn () => response()->json(['ok' => true]));
        $this->postJson('/api/v1/nul-probe', ['phone' => '+37491210443'])->assertOk();
        $this->postJson('/api/v1/nul-probe', ['phone' => "+374\0912"])->assertStatus(400);
    }

    public function test_a_nul_byte_at_the_very_edge_is_removed_by_trimming_and_never_stored(): void
    {
        $this->actingAs($this->support)->patchJson("/admin/riders/{$this->rider->id}", ['reason' => 'ok', 'name' => "Trimmed\0"])->assertSessionHasNoErrors();
        $this->assertSame('Trimmed', $this->rider->fresh()->name);

        $this->actingAs($this->support)->patchJson("/admin/riders/{$this->rider->id}", ['reason' => 'ok', 'name' => "\0"])->assertStatus(422);
        $this->assertSame('Trimmed', $this->rider->fresh()->name);
    }

    public function test_ordinary_unicode_and_control_characters_other_than_nul_still_work(): void
    {
        $this->actingAs($this->support)->patchJson("/admin/riders/{$this->rider->id}", [
            'reason' => "Tab\tand é and 日本語 and 😀",
            'name' => 'Ժենյա Մեհտա',
        ])->assertSessionHasNoErrors();

        $this->assertSame('Ժենյա Մեհտա', $this->rider->fresh()->name);
        $this->actingAs($this->support)->get('/admin/riders?filter[search]='.urlencode('Մեհտա'))->assertOk();
    }

    public function test_file_uploads_are_not_inspected_only_text_input_is(): void
    {
        $file = UploadedFile::fake()->createWithContent('doc.pdf', "%PDF-1.4\0\0binary");

        $this->actingAs($this->support)->post('/admin/riders/987654/suspend', ['reason' => 'x', 'attachment' => $file])->assertNotFound();
    }
}
