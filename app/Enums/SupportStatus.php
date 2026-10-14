<?php

namespace App\Enums;

/**
 * Where a support ticket stands. open: nobody has answered; in_progress: staff is on it; waiting_on_customer: staff
 * asked the requester something; resolved: answered with a resolution (can be reopened, or the requester writes again);
 * closed: final, nothing can be added.
 */
enum SupportStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case WaitingOnCustomer = 'waiting_on_customer';
    case Resolved = 'resolved';
    case Closed = 'closed';

    /** Still being worked on (the states that count in the "open" badge and can be assigned). */
    public function isActive(): bool
    {
        return in_array($this, [self::Open, self::InProgress, self::WaitingOnCustomer], true);
    }

    public function isFinished(): bool
    {
        return ! $this->isActive();
    }

    /**
     * @return list<string>
     */
    public static function activeValues(): array
    {
        return [self::Open->value, self::InProgress->value, self::WaitingOnCustomer->value];
    }
}
