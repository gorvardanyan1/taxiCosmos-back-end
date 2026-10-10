<?php

namespace Tests\Feature\Admin;

use App\Support\AdminFixtures;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Tests\TestCase;

class AdminFixturesTest extends TestCase
{
    /**
     * @return array<string, array<string, mixed>>
     */
    private function allFixtures(): array
    {
        return collect(File::files(database_path('fixtures/admin')))
            ->mapWithKeys(fn ($file) => [$file->getFilenameWithoutExtension() => app(AdminFixtures::class)->get($file->getFilenameWithoutExtension())])
            ->all();
    }

    /**
     * @return list<array{string, mixed}>
     */
    private function leaves(mixed $value, string $path = ''): array
    {
        if (! is_array($value)) {
            return [[$path, $value]];
        }

        return collect($value)->flatMap(fn ($child, $key) => $this->leaves($child, ltrim("{$path}.{$key}", '.')))->all();
    }

    public function test_every_money_value_is_an_integer_amount_in_minor_units_with_a_currency(): void
    {
        $count = 0;
        $walk = function (mixed $value, string $path) use (&$walk, &$count): void {
            if (! is_array($value)) {
                return;
            }
            if (array_key_exists('amount', $value) && array_key_exists('currency', $value)) {
                $count++;
                $this->assertIsInt($value['amount'], "{$path}.amount must be an integer (minor units).");
                $this->assertContains($value['currency'], array_column(config('taxikosmos.currencies'), 'code'), "{$path} has an unknown currency.");
            }
            foreach ($value as $key => $child) {
                $walk($child, "{$path}.{$key}");
            }
        };

        foreach ($this->allFixtures() as $name => $fixture) {
            $walk($fixture, $name);
        }

        $this->assertGreaterThan(50, $count, 'Expected the fixtures to contain money values.');
    }

    public function test_no_component_hardcodes_fixture_data(): void
    {
        // Person names and record codes from the fixtures must only reach the UI through props.
        $needles = collect($this->allFixtures())
            ->flatMap(fn ($fixture) => $this->leaves($fixture))
            ->map(fn ($leaf) => $leaf[1])
            ->filter(fn ($value) => is_string($value) && (
                preg_match('/^[A-Z][a-z]+ [A-Z][a-z]+(-[A-Z][a-z]+)?$/u', $value)
                || preg_match('/^(TK|TXN|TKT|PAY|AUTH|R|D|dp)[-_][A-Za-z0-9]{3,}$/', $value)
            ))
            ->reject(fn ($value) => in_array($value, ['Downtown Core', 'Airport Zone', 'Google Maps'], true))
            ->unique()
            ->values();

        $this->assertGreaterThan(30, $needles->count());

        $sources = collect(File::allFiles(resource_path('js')))
            ->reject(fn ($file) => str_contains($file->getFilename(), '.test.'))
            ->mapWithKeys(fn ($file) => [$file->getRelativePathname() => $file->getContents()]);

        foreach ($sources as $path => $contents) {
            foreach ($needles as $needle) {
                $this->assertStringNotContainsString($needle, $contents, "{$path} hardcodes fixture data [{$needle}].");
            }
        }
    }

    public function test_every_fixture_file_is_used_by_a_controller_or_middleware(): void
    {
        $code = collect(File::allFiles(app_path('Http')))->map(fn ($file) => $file->getContents())->implode("\n");

        foreach (array_keys($this->allFixtures()) as $name) {
            $this->assertStringContainsString("get('{$name}')", $code, "Fixture {$name} is never loaded.");
        }
    }

    public function test_fixture_names_are_validated(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(AdminFixtures::class)->get('../../.env');
    }

    public function test_missing_fixture_is_an_error(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(AdminFixtures::class)->get('does-not-exist');
    }
}
