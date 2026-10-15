<?php

namespace Tests\Feature\Support;

use App\Enums\AdminRole;
use App\Enums\SupportStatus;
use App\Models\Audit\ActivityLogEntry;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\Support\SupportTicketService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Proves every change to a ticket takes the ticket's row lock, so two staff members acting together queue up and the second
 * one sees the state the first left (a second resolve is a 409, not a second resolution). Uses committed data and a second
 * real connection (RefreshDatabase's wrapping transaction would hide the rows from it), like SavedPlaceRowLockTest.
 */
class SupportTicketRowLockTest extends TestCase
{
    use DatabaseTruncation;

    private const OTHER = 'pgsql_lock_holder';

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.'.self::OTHER => config('database.connections.pgsql')]);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->staff = User::factory()->admin(AdminRole::Support)->create();
    }

    protected function tearDown(): void
    {
        DB::connection(self::OTHER)->rollBack();
        DB::purge(self::OTHER);
        DB::statement('RESET lock_timeout');
        // Clear the committed rows so they never leak into the RefreshDatabase tests that run after this class.
        $this->truncateTablesForAllConnections();

        parent::tearDown();
    }

    private function holdTicketLock(SupportTicket $ticket): void
    {
        $other = DB::connection(self::OTHER);
        $other->beginTransaction();
        // KEY SHARE conflicts only with the service's FOR UPDATE: not with a plain UPDATE of the ticket (which takes NO KEY UPDATE)
        // and not with the foreign-key check of a new message. So only the service's own lock can make a change wait.
        $other->select('select id from support_tickets where id = ? for key share', [$ticket->id]);
    }

    /**
     * @return array<string, array{string, SupportStatus}>
     */
    public static function changes(): array
    {
        return [
            'resolve' => ['resolve', SupportStatus::Open],
            'reply' => ['reply', SupportStatus::Open],
            'internal note' => ['note', SupportStatus::Open],
            'assign' => ['assign', SupportStatus::Open],
            'reopen' => ['reopen', SupportStatus::Resolved],
        ];
    }

    private function change(string $change, SupportTicket $ticket): void
    {
        $service = app(SupportTicketService::class);

        match ($change) {
            'resolve' => $service->resolve($this->staff, $ticket, 'Fixed.'),
            'reply' => $service->staffMessage($this->staff, $ticket, 'Hello.', false),
            'note' => $service->staffMessage($this->staff, $ticket, 'Note.', true),
            'assign' => $service->assign($this->staff, $ticket, $this->staff->id),
            'reopen' => $service->reopen($this->staff, $ticket),
        };
    }

    #[DataProvider('changes')]
    public function test_a_change_waits_for_the_ticket_row_lock_and_changes_nothing_while_it_is_held(string $change, SupportStatus $status): void
    {
        $ticket = SupportTicket::factory()->status($status)->create();
        $this->holdTicketLock($ticket);
        DB::statement("SET lock_timeout = '300ms'");

        try {
            $this->change($change, $ticket);
            $this->fail("The {$change} must wait for the ticket row lock held by another transaction.");
        } catch (QueryException $e) {
            $this->assertSame('55P03', $e->getCode(), 'Expected lock_not_available, got: '.$e->getMessage());
        }

        $this->assertSame($status, $ticket->fresh()->status);
        $this->assertSame(0, ActivityLogEntry::query()->where('description', 'like', 'support_ticket.%')->count(), 'No audit entry while the lock is held.');
    }

    public function test_the_change_is_applied_once_the_lock_is_released(): void
    {
        $ticket = SupportTicket::factory()->create();
        $this->holdTicketLock($ticket);
        DB::connection(self::OTHER)->rollBack();

        $this->change('resolve', $ticket);

        $this->assertSame(SupportStatus::Resolved, $ticket->fresh()->status);
        $this->assertSame(1, ActivityLogEntry::query()->where('description', 'support_ticket.resolved')->count());
        DB::connection(self::OTHER)->beginTransaction(); // balance tearDown's rollBack
    }
}
