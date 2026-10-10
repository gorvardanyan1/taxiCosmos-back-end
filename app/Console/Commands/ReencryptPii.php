<?php

namespace App\Console\Commands;

use App\Support\BlindIndex;
use App\Support\Pii\PiiColumn;
use App\Support\Pii\PiiColumns;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Re-encrypts every registered PII column with the current APP_KEY and rebuilds its blind index
 * with the current BLIND_INDEX_KEY and normalisation (docs/security.md, "Rotating APP_KEY").
 *
 * Rows are read and written with the query builder, so no model events, timestamps or
 * real-time events fire. Idempotent: running it twice only re-encrypts again.
 */
#[Signature('pii:reencrypt {--dry-run : Check that every value can be decrypted and normalised, change nothing}')]
#[Description('Re-encrypt PII columns with the current APP_KEY and rebuild their blind indexes')]
class ReencryptPii extends Command
{
    private const CHUNK = 500;

    public function handle(BlindIndex $blindIndex): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $failures = 0;
        $rows = [];

        foreach (PiiColumns::all() as $column) {
            [$updated, $failed] = $this->process($column, $blindIndex, $dryRun);
            $failures += $failed;
            $rows[] = [$column->label(), $updated, $failed];
        }

        $this->table(['Column', $dryRun ? 'Checked' : 'Re-encrypted', 'Failed'], $rows);

        if ($failures > 0) {
            $this->error("{$failures} value(s) could not be processed; fix them (or the keys) and run again.");

            return self::FAILURE;
        }

        $this->info($dryRun ? 'Dry run: all values can be read with the current keys.' : 'Done. Previous APP_KEYs are no longer needed to read this data.');

        return self::SUCCESS;
    }

    /**
     * @return array{int, int} [processed, failed]
     */
    private function process(PiiColumn $column, BlindIndex $blindIndex, bool $dryRun): array
    {
        $processed = 0;
        $failed = 0;

        DB::table($column->table)
            ->whereNotNull($column->column)
            ->orderBy($column->primaryKey)
            ->select([$column->primaryKey, $column->column])
            ->chunkById(self::CHUNK, function ($rows) use ($column, $blindIndex, $dryRun, &$processed, &$failed) {
                foreach ($rows as $row) {
                    try {
                        $plain = Crypt::decryptString($row->{$column->column});
                        $normalised = $column->normalizer ? $column->normalizer::normalize($plain) : $plain;

                        if (! $dryRun) {
                            $changes = [$column->column => Crypt::encryptString($normalised)];
                            if ($column->hashColumn !== null) {
                                $changes[$column->hashColumn] = $blindIndex->hash($normalised);
                            }

                            // Own (nested) transaction per row: a unique violation on one row must not
                            // abort the surrounding transaction and fail every row after it.
                            DB::transaction(fn () => DB::table($column->table)->where($column->primaryKey, $row->{$column->primaryKey})->update($changes));
                        }

                        $processed++;
                    } catch (Throwable $e) {
                        $failed++;
                        // Row id and reason only: never print the value.
                        $this->warn("{$column->label()} #{$row->{$column->primaryKey}}: ".class_basename($e));
                    }
                }
            }, $column->primaryKey);

        return [$processed, $failed];
    }
}
