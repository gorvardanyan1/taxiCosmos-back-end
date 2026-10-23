<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A trip needs a driver: it was just requested, or the driver who had it gave it back. Dispatch (P7-T7) listens and starts offering the
 * trip to drivers. Fired after the database commit, so a rolled-back request never reaches dispatch. Carries only the id: dispatch
 * reads the trip as it is then (it may already be cancelled).
 */
class TripRequested implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly int $tripId) {}
}
