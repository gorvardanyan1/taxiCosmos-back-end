<?php

namespace Tests\Feature\Pii;

use App\Models\DriverBankAccount;
use App\Models\DriverProfile;
use App\Models\User;
use App\Support\Pii\PiiColumns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Every sensitive column is encrypted: unreadable in a raw query, readable through Eloquent,
 * never serialised, and a new sensitive-looking column cannot slip in unregistered.
 */
class PiiColumnsGuardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{table: string, column: string, make: callable, plaintext: string, attribute: string}>
     */
    private function cases(): array
    {
        return [
            'users.phone' => ['table' => 'users', 'column' => 'phone', 'plaintext' => '+37491440221', 'attribute' => 'phone',
                'make' => fn () => User::factory()->withPhone('091 440 221')->create()],
            'driver_profiles.license_number' => ['table' => 'driver_profiles', 'column' => 'license_number', 'plaintext' => 'AM-DL42', 'attribute' => 'license_number',
                'make' => fn () => DriverProfile::factory()->create(['license_number' => ' am-dl 42 '])],
            'driver_bank_accounts.account_number' => ['table' => 'driver_bank_accounts', 'column' => 'account_number', 'plaintext' => 'AM12345678904821', 'attribute' => 'account_number',
                'make' => fn () => DriverBankAccount::factory()->create(['account_number' => 'AM12 3456 7890 4821'])],
        ];
    }

    public function test_the_registry_lists_exactly_the_columns_covered_here(): void
    {
        $this->assertEqualsCanonicalizing(
            array_map(fn ($c) => $c->label(), PiiColumns::all()),
            array_keys($this->cases()),
            'A PiiColumns entry has no raw-vs-Eloquent case in this test (or the other way round).',
        );
    }

    public function test_encrypted_columns_are_text_and_unreadable_in_a_raw_query_but_readable_through_eloquent(): void
    {
        foreach ($this->cases() as $label => $case) {
            $model = ($case['make'])();

            $type = DB::table('information_schema.columns')->where('table_name', $case['table'])->where('column_name', $case['column'])->value('data_type');
            $this->assertSame('text', $type, "{$label} must be text (ciphertext is longer than the plaintext).");

            $raw = DB::table($case['table'])->where('id', $model->getKey())->value($case['column']);
            $this->assertNotSame($case['plaintext'], $raw, $label);
            $this->assertStringNotContainsString(substr($case['plaintext'], -6), $raw, "{$label}: the plaintext must not be visible in the column.");
            $this->assertSame($case['plaintext'], Crypt::decryptString($raw), "{$label}: raw value is the encrypted plaintext.");
            $this->assertSame($case['plaintext'], $model->fresh()->{$case['attribute']}, "{$label}: Eloquent returns the plaintext.");
        }
    }

    public function test_encryption_is_randomised_so_equal_values_do_not_look_equal(): void
    {
        $a = DriverBankAccount::factory()->create(['account_number' => 'AM1234567890']);
        $b = DriverBankAccount::factory()->create(['account_number' => 'AM1234567890']);

        $raw = DB::table('driver_bank_accounts')->whereIn('id', [$a->id, $b->id])->pluck('account_number')->all();

        $this->assertNotSame($raw[0], $raw[1], 'Same account number, different ciphertext: no equality leak.');
        $this->assertSame('AM1234567890', $a->fresh()->account_number);
        $this->assertSame('AM1234567890', $b->fresh()->account_number);
    }

    public function test_encrypted_attributes_are_never_serialised(): void
    {
        foreach ($this->cases() as $label => $case) {
            $array = ($case['make'])()->fresh()->toArray();

            $this->assertArrayNotHasKey($case['column'], $array, $label);
            $this->assertStringNotContainsString(substr($case['plaintext'], -6), json_encode($array), $label);
        }
    }

    public function test_searchable_columns_have_a_unique_indexed_hash_and_the_others_have_none(): void
    {
        foreach (PiiColumns::all() as $column) {
            $hashColumns = DB::table('information_schema.columns')->where('table_name', $column->table)->where('column_name', 'like', $column->column.'%hash')->pluck('column_name')->all();

            if ($column->hashColumn === null) {
                $this->assertSame([], $hashColumns, "{$column->label()} has no search requirement, so it must not get a blind index.");

                continue;
            }

            $this->assertSame([$column->hashColumn], $hashColumns);
            $definitions = DB::table('pg_indexes')->where('tablename', $column->table)->pluck('indexdef')->implode("\n");
            $this->assertMatchesRegularExpression('/UNIQUE INDEX \S+ ON public\.'.$column->table.' USING btree \('.$column->hashColumn.'\)/', $definitions, "{$column->label()}: hash must be unique and indexed.");
            $type = DB::selectOne('SELECT character_maximum_length FROM information_schema.columns WHERE table_name = ? AND column_name = ?', [$column->table, $column->hashColumn]);
            $this->assertSame(64, $type->character_maximum_length, 'A hex SHA-256 HMAC is 64 characters.');
        }
    }

    /**
     * @return list<string> "table.column" of sensitive-looking columns that are neither registered nor allowed in plaintext
     */
    private function unregisteredSensitiveColumns(): array
    {
        $registered = array_map(fn ($c) => $c->label(), PiiColumns::all());
        $allowed = [...$registered, ...PiiColumns::plaintextByDesign()];

        return collect(DB::select("SELECT table_name, column_name FROM information_schema.columns WHERE table_schema = 'public'"))
            ->filter(fn ($c) => preg_match(PiiColumns::sensitiveNamePattern(), $c->column_name))
            ->map(fn ($c) => "{$c->table_name}.{$c->column_name}")
            ->reject(fn ($label) => in_array($label, $allowed, true))
            ->values()
            ->all();
    }

    public function test_every_sensitive_looking_column_in_the_schema_is_registered_or_explicitly_plaintext(): void
    {
        $this->assertSame([], $this->unregisteredSensitiveColumns(), 'Encrypt the column (encrypted cast, text column) and add it to PiiColumns, or justify it in plaintextByDesign().');
    }

    public function test_the_guard_catches_a_new_unencrypted_sensitive_column(): void
    {
        DB::statement('CREATE TABLE guard_probe (id serial PRIMARY KEY, document_number varchar(40), two_factor_secret text, api_key text, nickname text)');

        $this->assertEqualsCanonicalizing(
            ['guard_probe.document_number', 'guard_probe.two_factor_secret', 'guard_probe.api_key'],
            $this->unregisteredSensitiveColumns(),
        );
    }

    public function test_the_plaintext_by_design_list_has_no_stale_or_registered_entries(): void
    {
        $registered = array_map(fn ($c) => $c->label(), PiiColumns::all());

        foreach (PiiColumns::plaintextByDesign() as $label) {
            [$table, $column] = explode('.', $label);
            $this->assertTrue(DB::table('information_schema.columns')->where('table_name', $table)->where('column_name', $column)->exists(), "{$label} no longer exists; remove it from plaintextByDesign().");
            $this->assertNotContains($label, $registered, "{$label} cannot be both encrypted and plaintext.");
            $this->assertMatchesRegularExpression(PiiColumns::sensitiveNamePattern(), $column, "{$label} does not look sensitive, so it needs no allow-list entry.");
        }
    }

    public function test_the_security_doc_lists_every_registered_column_and_the_pending_ones(): void
    {
        $doc = file_get_contents(base_path('docs/security.md'));

        foreach (PiiColumns::all() as $column) {
            $this->assertStringContainsString('`'.$column->label().'`', $doc);
        }
        foreach (['driver_documents.document_number', 'two-factor secrets', 'gateway / provider credentials', 'APP_PREVIOUS_KEYS', 'php artisan pii:reencrypt', 'BLIND_INDEX_KEY'] as $phrase) {
            $this->assertStringContainsString($phrase, $doc);
        }
    }
}
