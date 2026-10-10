<?php

namespace Tests\Unit\Support;

use App\Support\FixtureTable;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class FixtureTableTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $rows = [
        ['id' => 1, 'name' => 'Alpha', 'status' => 'active', 'balance' => ['amount' => -500]],
        ['id' => 2, 'name' => 'bravo', 'status' => 'suspended', 'balance' => ['amount' => 1200]],
        ['id' => 3, 'name' => 'Charlie', 'status' => 'active', 'balance' => ['amount' => -20]],
    ];

    private function paginate(string $query, ?string $default = null): array
    {
        return FixtureTable::paginate(Request::create("/admin/x?{$query}"), $this->rows, ['name'], ['status'], ['name', 'balance.amount'], $default)->toArray();
    }

    public function test_filters_sorts_and_paginates(): void
    {
        $page = $this->paginate('filter[status]=active&sort=-name');

        $this->assertSame(2, $page['total']);
        $this->assertSame(['Charlie', 'Alpha'], array_column($page['data'], 'name'));
    }

    public function test_numeric_sort_on_nested_keys(): void
    {
        $this->assertSame([1, 3, 2], array_column($this->paginate('sort=balance.amount')['data'], 'id'));
        $this->assertSame([2, 3, 1], array_column($this->paginate('sort=-balance.amount')['data'], 'id'));
    }

    public function test_default_sort_applies_only_without_an_explicit_one(): void
    {
        $this->assertSame([2, 3, 1], array_column($this->paginate('', '-balance.amount')['data'], 'id'));
        $this->assertSame([1, 2, 3], array_column($this->paginate('sort=name', '-balance.amount')['data'], 'id'));
    }

    public function test_search_is_trimmed_case_insensitive_and_empty_values_are_ignored(): void
    {
        $this->assertSame(1, $this->paginate('filter[search]=%20BRAVO%20')['total']);
        $this->assertSame(3, $this->paginate('filter[search]=&filter[status]=')['total']);
    }

    public function test_out_of_range_page_is_empty_not_an_error(): void
    {
        $page = $this->paginate('page=9');

        $this->assertSame([], $page['data']);
        $this->assertSame(3, $page['total']);
    }

    public function test_links_keep_the_query_string(): void
    {
        $page = $this->paginate('filter[status]=active&per_page=10');

        $this->assertStringContainsString('filter%5Bstatus%5D=active', $page['links'][1]['url']);
    }

    public function test_unknown_filter_or_sort_is_a_400(): void
    {
        foreach (['filter[id]=1', 'sort=id', 'sort=-password', 'filter[search][]=a'] as $query) {
            try {
                $this->paginate($query);
                $this->fail("[{$query}] must be rejected.");
            } catch (HttpException $e) {
                $this->assertSame(400, $e->getStatusCode(), $query);
            }
        }
    }
}
