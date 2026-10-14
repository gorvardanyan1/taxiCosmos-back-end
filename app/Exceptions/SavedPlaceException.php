<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A saved place the rider app may not add. Renders as the JSON error the app switches on (`code`).
 */
class SavedPlaceException extends RuntimeException
{
    private function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function limitReached(int $max): self
    {
        return new self(trans_choice('You can keep up to :count saved place. Delete one to add another.|You can keep up to :count saved places. Delete one to add another.', $max), 'saved_places_limit_reached');
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => $this->reason], 422);
    }
}
