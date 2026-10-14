<?php

namespace App\Enums;

/**
 * Where one required document stands for a driver who is not fully verified yet (what the driver app shows per document).
 */
enum DocumentRequirementState: string
{
    case Missing = 'missing';
    case Pending = 'pending';
    case Rejected = 'rejected';
    case Expired = 'expired';
}
