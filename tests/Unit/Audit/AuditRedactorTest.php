<?php

namespace Tests\Unit\Audit;

use App\Services\Audit\AuditRedactor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AuditRedactorTest extends TestCase
{
    public function test_ordinary_values_pass_through_unchanged(): void
    {
        [$before, $after] = AuditRedactor::diff(['status' => 'pending', 'reviewer' => null], ['status' => 'rejected', 'reviewer' => 'Jordan']);

        $this->assertSame(['status' => 'pending', 'reviewer' => null], $before);
        $this->assertSame(['status' => 'rejected', 'reviewer' => 'Jordan'], $after);
    }

    public function test_a_changed_sensitive_field_shows_only_that_it_changed(): void
    {
        [$before, $after] = AuditRedactor::diff(['document_number' => 'AB-111', 'status' => 'pending'], ['document_number' => 'AB-222', 'status' => 'approved']);

        $this->assertSame(['document_number' => 'changed', 'status' => 'pending'], $before);
        $this->assertSame(['document_number' => 'changed', 'status' => 'approved'], $after);
    }

    public function test_an_unchanged_sensitive_field_is_left_out(): void
    {
        [$before, $after] = AuditRedactor::diff(['phone' => '+37491440221', 'name' => 'A'], ['phone' => '+37491440221', 'name' => 'B']);

        $this->assertSame(['name' => 'A'], $before);
        $this->assertSame(['name' => 'B'], $after);
    }

    public function test_a_sensitive_field_added_or_removed_counts_as_changed(): void
    {
        [$before, $after] = AuditRedactor::diff([], ['license_number' => 'DL-1']);
        $this->assertSame([], $before);
        $this->assertSame(['license_number' => 'changed'], $after);

        [$before, $after] = AuditRedactor::diff(['license_number' => 'DL-1'], []);
        $this->assertSame(['license_number' => 'changed'], $before);
        $this->assertSame([], $after);
    }

    #[DataProvider('sensitiveKeys')]
    public function test_secrets_credentials_and_pii_are_never_stored_as_values(string $key): void
    {
        [$before, $after] = AuditRedactor::diff([$key => 'old-secret-value'], [$key => 'new-secret-value']);

        $this->assertSame([$key => 'changed'], $before);
        $this->assertSame([$key => 'changed'], $after);
        $this->assertStringNotContainsString('secret-value', json_encode([$before, $after]));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function sensitiveKeys(): array
    {
        return array_combine($keys = [
            'phone', 'license_number', 'document_number', 'id_number', 'account_number', 'iban', 'two_factor_secret', 'two_factor_recovery_codes',
            'secret_key', 'api_key', 'private_key', 'gateway_credentials', 'password', 'new_password', 'remember_token', 'access_token', 'otp_code', 'passport_number',
        ], array_map(fn ($k) => [$k], $keys));
    }

    public function test_sensitive_fields_inside_nested_arrays_are_redacted_too(): void
    {
        [$before, $after] = AuditRedactor::diff(
            ['gateway' => ['name' => 'stripe', 'secret_key' => 'sk_old']],
            ['gateway' => ['name' => 'stripe', 'secret_key' => 'sk_new']],
        );

        $this->assertSame(['name' => 'stripe', 'secret_key' => 'changed'], $before['gateway']);
        $this->assertSame(['name' => 'stripe', 'secret_key' => 'changed'], $after['gateway']);
    }

    public function test_a_nested_sensitive_field_without_a_counterpart_is_still_hidden(): void
    {
        [$before, $after] = AuditRedactor::diff(['gateway' => ['api_key' => 'sk_old']], ['gateway' => 'removed']);

        $this->assertSame(['api_key' => 'changed'], $before['gateway']);
        $this->assertSame('removed', $after['gateway']);
    }

    public function test_non_sensitive_lookalikes_are_kept(): void
    {
        [$before] = AuditRedactor::diff(['status' => 'a', 'account_last4' => '4821', 'swift' => 'AMRBAM22'], ['status' => 'b']);

        $this->assertSame(['status' => 'a', 'account_last4' => '4821', 'swift' => 'AMRBAM22'], $before);
    }
}
