<?php

namespace Tests\Feature\Localisation;

use Tests\TestCase;

/**
 * Display text that the server builds for the admin UI (presenters, role labels, shared props,
 * controller props) must go through __(). The Vitest guard cannot see PHP, so this scans those
 * files for English-looking literals that are not wrapped.
 */
class StaticServerTextGuardTest extends TestCase
{
    /** Literals that are data, not words for a person. */
    private const ALLOWED = [
        'Admin Console', // never sent; the sidebar's own text lives in React
    ];

    /**
     * @return list<string>
     */
    private function files(): array
    {
        $files = [
            ...glob(base_path('app/Support/*Presenter.php')),
            base_path('app/Enums/AdminRole.php'),
            base_path('app/Http/Middleware/HandleInertiaRequests.php'),
            ...glob(base_path('app/Http/Controllers/Admin/*Controller.php')),
        ];

        return array_values(array_filter($files, 'is_file'));
    }

    /**
     * Words-for-a-person: a capitalised word followed by more letters ("Unnamed rider", "System").
     */
    private function looksLikeText(string $value): bool
    {
        if (in_array($value, self::ALLOWED, true)) {
            return false;
        }

        // Identifiers, paths, routes, enum values, timezones, header names, date formats and codes are not text.
        if (preg_match('/^[a-z0-9_.\/:\-\\\\]+$/', $value) || str_contains($value, '/') || str_contains($value, '\\') || str_contains($value, '::') || (str_contains($value, '-') && ! str_contains($value, ' '))) {
            return false;
        }

        return preg_match('/^[A-Z][a-z]{2,}/', $value) === 1 || preg_match('/\s[a-z]{3,}\s[a-z]{3,}/', $value) === 1;
    }

    /**
     * @return list<string> "file:line 'literal'"
     */
    private function untranslated(string $file): array
    {
        $tokens = token_get_all(file_get_contents($file));
        $found = [];

        foreach ($tokens as $i => $token) {
            if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $value = stripcslashes(substr($token[1], 1, -1));
            if ($token[1][0] === "'") {
                $value = str_replace("\\'", "'", substr($token[1], 1, -1));
            }

            if (! $this->looksLikeText($value)) {
                continue;
            }

            $previous = $this->text($tokens, $this->neighbour($tokens, $i, -1));
            $beforeThat = $this->text($tokens, $this->neighbour($tokens, $i, -2));
            $next = $this->text($tokens, $this->neighbour($tokens, $i, 1));

            $isTranslated = $previous === '(' && in_array($beforeThat, ['__', 'trans', 'trans_choice'], true);
            $isArrayKey = $next === '=>';
            // Names the framework or the browser consumes, not words shown to a person.
            $isReference = $previous === '(' && in_array($beforeThat, ['render', 'route', 'config', 'view', 'header', 'set', 'has', 'is', 'can', 'where', 'orderBy', 'streamDownload'], true);

            if (! $isTranslated && ! $isArrayKey && ! $isReference) {
                $found[] = str_replace(base_path().'/', '', $file).':'.$token[2].' '.$token[1];
            }
        }

        return $found;
    }

    /** @param array<int, mixed> $tokens */
    private function neighbour(array $tokens, int $index, int $offset, bool $named = false): int
    {
        $step = $offset < 0 ? -1 : 1;
        $count = abs($offset);

        for ($i = $index + $step; isset($tokens[$i]); $i += $step) {
            if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if (--$count === 0 || $named) {
                return $i;
            }
        }

        return -1;
    }

    /** @param array<int, mixed> $tokens */
    private function text(array $tokens, int $index): string
    {
        return $index >= 0 && isset($tokens[$index]) ? (is_array($tokens[$index]) ? $tokens[$index][1] : $tokens[$index]) : '';
    }

    public function test_no_server_built_display_text_is_left_untranslated(): void
    {
        $found = [];
        foreach ($this->files() as $file) {
            array_push($found, ...$this->untranslated($file));
        }

        $this->assertSame([], $found, 'Wrap display text in __() and add it to lang/ru.json and lang/hy.json');
    }

    public function test_the_guard_scans_the_files_and_would_catch_a_new_literal(): void
    {
        $this->assertGreaterThan(15, count($this->files()));

        $probe = tempnam(sys_get_temp_dir(), 'probe').'.php';
        file_put_contents($probe, "<?php\nreturn ['name' => \$x ?? 'Unnamed thing', 'label' => __('Translated thing'), 'code' => 'abc_def', 'path' => 'Riders/Index'];\n");

        $this->assertCount(1, $this->untranslated($probe));
        $this->assertStringContainsString("'Unnamed thing'", $this->untranslated($probe)[0]);
        unlink($probe);
    }
}
