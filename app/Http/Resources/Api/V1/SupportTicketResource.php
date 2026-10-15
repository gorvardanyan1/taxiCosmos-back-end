<?php

namespace App\Http\Resources\Api\V1;

use App\Models\SupportTicket;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A ticket as the rider and driver apps see it. Staff are never named, internal notes never appear, and the conversation is
 * only included when it was loaded (the detail endpoint).
 *
 * @mixin SupportTicket
 */
class SupportTicketResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'subject' => $this->subject,
            'category' => $this->category->value,
            'priority' => $this->priority->value,
            'status' => $this->status->value,
            'trip_id' => $this->trip_id,
            'resolution' => $this->resolution,
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'last_message_at' => $this->last_message_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'messages' => $this->whenLoaded('messages', fn () => $this->messages->map(fn ($message) => [
                'id' => $message->id,
                // "you" is the requester's own message, "support" a staff reply.
                'from' => $message->author_id === $this->requester_id ? 'you' : 'support',
                'body' => $message->body,
                'created_at' => $message->created_at->toIso8601String(),
            ])->values()->all()),
        ];
    }
}
