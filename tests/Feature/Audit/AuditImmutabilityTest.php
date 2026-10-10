<?php

namespace Tests\Feature\Audit;

use App\Models\Audit\ActivityLogEntry;
use App\Models\Audit\AuditEntryBuilder;
use Illuminate\Support\Facades\Route;
use LogicException;
use ReflectionClass;
use ReflectionMethod;

class AuditImmutabilityTest extends AuditTestCase
{
    private function entry(): ActivityLogEntry
    {
        return $this->audit()->record($this->admin(), 'driver.document.approved', reason: 'Original reason');
    }

    public function test_the_application_logs_through_the_immutable_model(): void
    {
        $this->assertSame(ActivityLogEntry::class, config('activitylog.activity_model'));
        $this->assertInstanceOf(ActivityLogEntry::class, $this->entry());
        $this->assertInstanceOf(ActivityLogEntry::class, activity()->log('x'));
    }

    public function test_an_existing_entry_cannot_be_saved_updated_or_deleted_through_the_model(): void
    {
        $entry = $this->entry();
        $original = $entry->fresh()->toArray();

        foreach ([
            fn () => $entry->update(['description' => 'tampered']),
            fn () => $entry->forceFill(['description' => 'tampered'])->save(),
            fn () => $entry->delete(),
            fn () => $entry->forceDelete(),
            fn () => ActivityLogEntry::find($entry->id)->delete(),
            fn () => $entry->touch(),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('The change must be refused.');
            } catch (LogicException $e) {
                $this->assertSame('Audit log entries cannot be changed or deleted.', $e->getMessage());
            }
        }

        $this->assertSame($original, $entry->fresh()->toArray());
    }

    public function test_it_cannot_be_changed_or_deleted_through_the_query_builder_either(): void
    {
        $entry = $this->entry();

        foreach ([
            fn () => ActivityLogEntry::query()->update(['description' => 'tampered']),
            fn () => ActivityLogEntry::query()->where('id', $entry->id)->update(['description' => 'tampered']),
            fn () => ActivityLogEntry::query()->delete(),
            fn () => ActivityLogEntry::query()->where('id', $entry->id)->forceDelete(),
            fn () => ActivityLogEntry::query()->where('id', $entry->id)->increment('id'),
            fn () => ActivityLogEntry::query()->upsert([['id' => $entry->id, 'description' => 'tampered']], ['id']),
            fn () => ActivityLogEntry::destroy($entry->id),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('The change must be refused.');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame('driver.document.approved', $entry->fresh()->description);
        $this->assertSame(1, ActivityLogEntry::count());
    }

    public function test_new_entries_can_still_be_created_and_read(): void
    {
        $first = $this->entry();
        $second = $this->entry();

        $this->assertSame([$first->id, $second->id], ActivityLogEntry::orderBy('id')->pluck('id')->all());
        $this->assertSame('Original reason', ActivityLogEntry::find($first->id)->properties['reason']);
    }

    public function test_no_route_can_write_to_the_log_the_only_activity_routes_are_the_two_reads(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($route) => str_contains($route->uri(), 'activity'));

        $this->assertEqualsCanonicalizing(
            ['admin/activity-logs', 'admin/activity-logs/export'],
            $routes->map(fn ($route) => $route->uri())->values()->all(),
        );
        foreach ($routes as $route) {
            $this->assertEqualsCanonicalizing(['GET', 'HEAD'], $route->methods(), "{$route->uri()} must be read-only.");
        }
    }

    public function test_only_retention_can_remove_entries_the_builder_exposes_nothing_else(): void
    {
        $methods = collect((new ReflectionClass(AuditEntryBuilder::class))->getMethods(ReflectionMethod::IS_PUBLIC))
            ->filter(fn ($m) => $m->getDeclaringClass()->getName() === AuditEntryBuilder::class)
            ->map(fn ($m) => $m->getName())->all();

        $this->assertEqualsCanonicalizing(['update', 'delete', 'forceDelete', 'increment', 'decrement', 'upsert', 'purgeOlderThan'], $methods);
    }
}
