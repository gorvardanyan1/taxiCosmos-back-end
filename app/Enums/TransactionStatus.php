<?php

namespace App\Enums;

enum TransactionStatus: string
{
    case Pending = 'pending';
    case RequiresAction = 'requires_action';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /** Finished for good: the gateway has given its final answer. */
    public function isFinal(): bool
    {
        return in_array($this, [self::Succeeded, self::Failed, self::Cancelled], true);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
