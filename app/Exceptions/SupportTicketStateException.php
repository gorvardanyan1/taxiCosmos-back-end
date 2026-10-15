<?php

namespace App\Exceptions;

/** An action on a support ticket its current state does not allow (HTTP 409). */
class SupportTicketStateException extends ConflictException
{
    public static function alreadyFinished(): self
    {
        return new self(__('This ticket is already resolved.'));
    }

    public static function notFinished(): self
    {
        return new self(__('Only a resolved or closed ticket can be reopened.'));
    }

    public static function closed(): self
    {
        return new self(__('This ticket is closed.'));
    }

    public static function finishedForAssignment(): self
    {
        return new self(__('A resolved ticket cannot be assigned. Reopen it first.'));
    }

    public static function notAStaffMember(): self
    {
        return new self(__('Tickets can only be assigned to an active admin who handles support.'));
    }

    public static function idempotencyKeyReused(): self
    {
        return new self(__('This idempotency key was already used for a different request.'));
    }
}
