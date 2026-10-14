<?php

namespace App\Support;

use App\Enums\AppClient;
use App\Models\SupportTicket;
use App\Queries\RiderQuery;

/** Support tickets as the admin Support Tickets page shows them. */
final class SupportTicketPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function row(SupportTicket $ticket): array
    {
        $requester = $ticket->requester;

        return [
            'id' => $ticket->id,
            'code' => $ticket->code,
            'subject' => $ticket->subject,
            'category' => $ticket->category->value,
            'priority' => $ticket->priority->value,
            'status' => $ticket->status->value,
            'assignee' => $ticket->assignee === null ? null : ['id' => $ticket->assignee->id, 'name' => $ticket->assignee->name],
            'requester' => [
                'name' => $requester->name ?? __('Unnamed rider'),
                'type' => $ticket->requester_type->value,
                'code' => $ticket->requester_type === AppClient::Driver && $requester->driverProfile !== null ? sprintf('D-%04d', $requester->driverProfile->id) : RiderQuery::code($requester->id),
            ],
            'created_at' => $ticket->created_at->toIso8601String(),
            'last_message_at' => $ticket->last_message_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(SupportTicket $ticket): array
    {
        $ticket->loadMissing(['requester.driverProfile', 'assignee', 'trip', 'messages.author']);

        return [
            ...self::row($ticket),
            'resolution' => $ticket->resolution,
            'resolved_at' => $ticket->resolved_at?->toIso8601String(),
            'messages' => $ticket->messages->sortBy('id')->map(fn ($message) => [
                'id' => $message->id,
                'kind' => $message->is_internal_note ? 'internal' : ($message->author_id === $ticket->requester_id ? 'requester' : 'staff'),
                'author' => $message->author->name,
                'body' => $message->body,
                'created_at' => $message->created_at->toIso8601String(),
            ])->values()->all(),
            'linked_trip' => $ticket->trip === null ? null : ['id' => $ticket->trip->id, 'code' => $ticket->trip->public_code],
        ];
    }
}
