<?php

namespace App\Notifications;

use App\Models\SupportTicket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emailed to every active admin who handles support when a safety ticket is opened (P7-T5). Only the ticket code, its kind and the
 * link: what the requester wrote stays in the admin, behind its permission. The in-app bell arrives with P13-T6.
 */
class SafetyAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly SupportTicket $ticket)
    {
        // Queued only once the ticket is committed.
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject(__('Safety ticket :code needs attention', ['code' => $this->ticket->code]))
            ->line(__('A :app reported a safety issue. The ticket is urgent.', ['app' => __($this->ticket->requester_type->value)]))
            ->line(__('Ticket: :code', ['code' => $this->ticket->code]));

        if ($this->ticket->trip !== null) {
            $message->line(__('Trip: :code', ['code' => $this->ticket->trip->public_code]));
        }

        return $message->action(__('Open the ticket'), route('admin.support-tickets.show', $this->ticket->getKey()));
    }
}
