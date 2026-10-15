<?php

namespace App\Enums;

/** What a transaction row does. A capture, a void and a refund always point back (parent_transaction_id) at the authorization or charge they act on. */
enum TransactionType: string
{
    case Authorization = 'authorization';
    case Capture = 'capture';
    case Charge = 'charge';
    case Void = 'void';
    case Refund = 'refund';

    /** The types that act on an earlier transaction. */
    public function needsParent(): bool
    {
        return in_array($this, [self::Capture, self::Void, self::Refund], true);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
