<?php

namespace Tests\Unit\Trips;

use App\Enums\TripCancelledBy;
use App\Enums\TripStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TripStatusTest extends TestCase
{
    /**
     * The transition table of P2-T4, written out so a change to the enum has to change this too.
     *
     * @return array<string, list<string>>
     */
    private static function table(): array
    {
        return [
            'requested' => ['matched', 'no_driver_found', 'cancelled'],
            'matched' => ['arrived', 'requested', 'cancelled'],
            'arrived' => ['in_progress', 'requested', 'cancelled'],
            'in_progress' => ['completed', 'cancelled'],
            'completed' => [],
            'cancelled' => [],
            'no_driver_found' => [],
        ];
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function everyPair(): iterable
    {
        foreach (self::table() as $from => $allowed) {
            foreach (TripStatus::cases() as $to) {
                yield "$from → {$to->value}" => [$from, $to->value, in_array($to->value, $allowed, true)];
            }
        }
    }

    #[DataProvider('everyPair')]
    public function test_transition_matrix_matches_the_spec(string $from, string $to, bool $allowed): void
    {
        $this->assertSame($allowed, TripStatus::from($from)->canTransitionTo(TripStatus::from($to)));
    }

    public function test_the_spec_table_covers_every_status(): void
    {
        $this->assertEqualsCanonicalizing(TripStatus::values(), array_keys(self::table()));
    }

    public function test_completed_cannot_go_back_to_in_progress(): void
    {
        $this->assertFalse(TripStatus::Completed->canTransitionTo(TripStatus::InProgress));
    }

    public function test_only_finished_statuses_are_terminal(): void
    {
        $terminal = array_filter(TripStatus::cases(), fn (TripStatus $s) => $s->isTerminal());

        $this->assertEqualsCanonicalizing(
            ['completed', 'cancelled', 'no_driver_found'],
            array_map(fn (TripStatus $s) => $s->value, array_values($terminal)),
        );
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function cancellations(): iterable
    {
        yield 'rider cancels a request' => ['requested', 'rider', true];
        yield 'driver cannot cancel before matching' => ['requested', 'driver', false];
        yield 'system cancels a request' => ['requested', 'system', true];
        yield 'admin cancels a request' => ['requested', 'admin', true];
        yield 'driver cancels matched' => ['matched', 'driver', true];
        yield 'rider cancels arrived' => ['arrived', 'rider', true];
        yield 'driver cancels arrived' => ['arrived', 'driver', true];
        yield 'system cancels matched' => ['matched', 'system', true];
        yield 'rider cannot cancel in progress' => ['in_progress', 'rider', false];
        yield 'driver cannot cancel in progress' => ['in_progress', 'driver', false];
        yield 'system cannot cancel in progress' => ['in_progress', 'system', false];
        yield 'admin force-cancels in progress' => ['in_progress', 'admin', true];
        yield 'nobody cancels a completed trip' => ['completed', 'admin', false];
        yield 'nobody cancels a cancelled trip' => ['cancelled', 'admin', false];
    }

    #[DataProvider('cancellations')]
    public function test_who_may_cancel_from_which_status(string $status, string $by, bool $allowed): void
    {
        $this->assertSame($allowed, TripStatus::from($status)->canBeCancelledBy(TripCancelledBy::from($by)));
    }
}
