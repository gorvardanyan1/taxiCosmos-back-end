<?php

namespace App\Enums;

enum WalletEntryType: string
{
    case Credit = 'credit';
    case Debit = 'debit';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
