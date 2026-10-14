<?php

namespace App\Services\Support;

use App\Enums\AdminPermission;
use App\Enums\AppClient;
use App\Enums\SupportCategory;
use App\Enums\SupportStatus;
use App\Enums\UserStatus;
use App\Events\Realtime\SupportTicketUpdated;
use App\Exceptions\SupportTicketStateException;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\Trip;
use App\Models\User;
use App\Notifications\SafetyAlert;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Rider and driver complaints (P7-T5). Everything that changes a ticket goes through here, one transaction each, with the
 * ticket row locked, so two staff members (or a double click) cannot apply the same step twice.
 *
 *  - the requester opens a ticket and adds messages (retry-safe through an Idempotency-Key);
 *  - staff reply, add internal notes (never shown to the requester), assign, resolve (a resolution is required) and reopen;
 *  - a safety ticket starts urgent and emails every active admin who handles support, after the commit;
 *  - realtime events (support_ticket.updated) go out after the commit; internal notes never publish one.
 */
final class SupportTicketService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Active admins who can handle support: the people a safety alert goes to and who a ticket can be assigned to.
     *
     * @return Builder<User>
     */
    public static function staff(): Builder
    {
        return User::permission(AdminPermission::SupportManage->value)->where('is_admin', true)->where('status', UserStatus::Active->value);
    }

    /**
     * @return array{ticket: SupportTicket, created: bool} created=false when the idempotency key already opened this ticket
     */
    public function open(User $requester, AppClient $app, SupportCategory $category, string $subject, string $message, ?int $tripId, ?string $idempotencyKey): array
    {
        $trip = $tripId === null ? null : $this->ownTrip($requester, $app, $tripId);

        if ($idempotencyKey !== null && ($existing = $this->byKey($requester, $idempotencyKey)) !== null) {
            return ['ticket' => $this->sameRequest($existing, $category, $subject, $trip?->id), 'created' => false];
        }

        try {
            $ticket = DB::transaction(function () use ($requester, $app, $category, $subject, $message, $trip, $idempotencyKey) {
                $ticket = new SupportTicket;
                $ticket->forceFill([
                    'requester_id' => $requester->getKey(),
                    'requester_type' => $app,
                    'trip_id' => $trip?->id,
                    'subject' => $subject,
                    'category' => $category,
                    'priority' => $category->initialPriority(),
                    'status' => SupportStatus::Open,
                    'last_message_at' => now(),
                    'idempotency_key' => $idempotencyKey,
                ])->save();
                $this->addMessage($ticket, $requester, $message, false);

                if ($category === SupportCategory::Safety) {
                    $ticket->refresh();
                    // Emailed only once the ticket is really saved.
                    DB::afterCommit(fn () => Notification::send(self::staff()->get(), new SafetyAlert($ticket)));
                }

                return $ticket;
            });
        } catch (UniqueConstraintViolationException) {
            // A parallel retry with the same key won the race.
            $winner = $idempotencyKey === null ? null : $this->byKey($requester, $idempotencyKey);

            return $winner === null ? throw new \RuntimeException('Support ticket could not be created.') : ['ticket' => $this->sameRequest($winner, $category, $subject, $trip?->id), 'created' => false];
        }

        return ['ticket' => $ticket->refresh(), 'created' => true];
    }

    /**
     * The requester writes on their own ticket. A reply to a staff question moves it back to in_progress; writing on a
     * resolved ticket reopens it; a closed ticket takes nothing.
     *
     * @return array{message: SupportTicketMessage, created: bool}
     */
    public function requesterMessage(User $requester, SupportTicket $ticket, string $body, ?string $idempotencyKey): array
    {
        return DB::transaction(function () use ($requester, $ticket, $body, $idempotencyKey) {
            $locked = $this->lock($ticket);

            if ($idempotencyKey !== null && ($existing = $this->messageByKey($locked, $requester, $idempotencyKey)) !== null) {
                return ['message' => $existing->body === $body ? $existing : throw SupportTicketStateException::idempotencyKeyReused(), 'created' => false];
            }

            if ($locked->status === SupportStatus::Closed) {
                throw SupportTicketStateException::closed();
            }

            $message = $this->addMessage($locked, $requester, $body, false, $idempotencyKey);
            $next = match ($locked->status) {
                SupportStatus::WaitingOnCustomer => SupportStatus::InProgress,
                SupportStatus::Resolved => SupportStatus::Open,
                default => $locked->status,
            };
            $locked->forceFill(['status' => $next, 'last_message_at' => now()] + ($next === SupportStatus::Open ? ['resolved_at' => null, 'resolution' => null] : []))->save();

            return ['message' => $message, 'created' => true];
        });
    }

    /**
     * A staff reply (the requester sees it, and gets a push) or an internal note (staff only, no event, no status change).
     */
    public function staffMessage(User $actor, SupportTicket $ticket, string $body, bool $internal): SupportTicketMessage
    {
        return DB::transaction(function () use ($actor, $ticket, $body, $internal) {
            $locked = $this->lock($ticket);

            if ($locked->status->isFinished()) {
                throw SupportTicketStateException::alreadyFinished();
            }

            $message = $this->addMessage($locked, $actor, $body, $internal);

            if ($internal) {
                $this->audit->record(actor: $actor, action: 'support_ticket.note_added', target: $locked, context: ['message_id' => $message->id]);

                return $message;
            }

            $before = $locked->status;
            $locked->forceFill([
                'status' => SupportStatus::WaitingOnCustomer,
                'assigned_to' => $locked->assigned_to ?? $actor->getKey(),
                'last_message_at' => now(),
            ])->save();
            $this->audit->record(actor: $actor, action: 'support_ticket.replied', target: $locked, old: ['status' => $before->value], new: ['status' => $locked->status->value], context: ['message_id' => $message->id]);
            $this->publish($locked, 'reply');

            return $message;
        });
    }

    /**
     * Give the ticket to a staff member, or take the assignee off (null). Returns false when nothing changed.
     */
    public function assign(User $actor, SupportTicket $ticket, ?int $assigneeId): bool
    {
        return DB::transaction(function () use ($actor, $ticket, $assigneeId) {
            $locked = $this->lock($ticket);

            if ($locked->status->isFinished()) {
                throw SupportTicketStateException::finishedForAssignment();
            }

            if ($assigneeId !== null && ! self::staff()->whereKey($assigneeId)->exists()) {
                throw SupportTicketStateException::notAStaffMember();
            }

            if ($locked->assigned_to === $assigneeId) {
                return false;
            }

            $old = ['assigned_to' => $locked->assigned_to, 'status' => $locked->status->value];
            $locked->forceFill(['assigned_to' => $assigneeId, 'status' => $assigneeId !== null && $locked->status === SupportStatus::Open ? SupportStatus::InProgress : $locked->status])->save();
            $this->audit->record(actor: $actor, action: 'support_ticket.assigned', target: $locked, old: $old, new: ['assigned_to' => $assigneeId, 'status' => $locked->status->value]);
            $this->publish($locked, 'assignment');

            return true;
        });
    }

    public function resolve(User $actor, SupportTicket $ticket, string $resolution): SupportTicket
    {
        return DB::transaction(function () use ($actor, $ticket, $resolution) {
            $locked = $this->lock($ticket);

            if ($locked->status->isFinished()) {
                throw SupportTicketStateException::alreadyFinished();
            }

            $before = $locked->status;
            $locked->forceFill([
                'status' => SupportStatus::Resolved,
                'resolved_at' => now(),
                'resolution' => trim($resolution),
                'assigned_to' => $locked->assigned_to ?? $actor->getKey(),
            ])->save();
            $this->audit->record(actor: $actor, action: 'support_ticket.resolved', target: $locked, reason: $resolution, old: ['status' => $before->value], new: ['status' => SupportStatus::Resolved->value], reasonRequired: true);
            $this->publish($locked, 'status');

            return $locked;
        });
    }

    public function reopen(User $actor, SupportTicket $ticket): SupportTicket
    {
        return DB::transaction(function () use ($actor, $ticket) {
            $locked = $this->lock($ticket);

            if ($locked->status->isActive()) {
                throw SupportTicketStateException::notFinished();
            }

            $before = $locked->status;
            $locked->forceFill(['status' => SupportStatus::Open, 'resolved_at' => null, 'resolution' => null])->save();
            $this->audit->record(actor: $actor, action: 'support_ticket.reopened', target: $locked, old: ['status' => $before->value], new: ['status' => SupportStatus::Open->value]);
            $this->publish($locked, 'status');

            return $locked;
        });
    }

    private function addMessage(SupportTicket $ticket, User $author, string $body, bool $internal, ?string $idempotencyKey = null): SupportTicketMessage
    {
        $message = new SupportTicketMessage;
        $message->forceFill([
            'ticket_id' => $ticket->getKey(),
            'author_id' => $author->getKey(),
            'body' => $body,
            'is_internal_note' => $internal,
            'idempotency_key' => $idempotencyKey,
            'created_at' => now(),
        ])->save();

        return $message;
    }

    private function publish(SupportTicket $ticket, string $change): void
    {
        SupportTicketUpdated::dispatch($ticket->getKey(), $ticket->code, $ticket->status->value, $ticket->requester_id, $change);
    }

    private function lock(SupportTicket $ticket): SupportTicket
    {
        return SupportTicket::query()->lockForUpdate()->findOrFail($ticket->getKey());
    }

    /** The trip must be the requester's own: a rider's as rider, or one the driver drove. Anything else is "not valid", never "not yours". */
    private function ownTrip(User $requester, AppClient $app, int $tripId): Trip
    {
        $query = Trip::query()->whereKey($tripId);
        $app === AppClient::Rider
            ? $query->where('rider_id', $requester->getKey())
            : $query->whereIn('driver_id', $requester->driverProfile()->select('id'));

        return $query->first() ?? throw ValidationException::withMessages(['trip_id' => __('The selected trip is invalid.')]);
    }

    private function byKey(User $requester, string $key): ?SupportTicket
    {
        return SupportTicket::query()->where('requester_id', $requester->getKey())->where('idempotency_key', $key)->first();
    }

    private function messageByKey(SupportTicket $ticket, User $author, string $key): ?SupportTicketMessage
    {
        return SupportTicketMessage::query()->where('ticket_id', $ticket->getKey())->where('author_id', $author->getKey())->where('idempotency_key', $key)->first();
    }

    /** A retry must repeat the same request; the same key for a different one is refused. */
    private function sameRequest(SupportTicket $existing, SupportCategory $category, string $subject, ?int $tripId): SupportTicket
    {
        return $existing->category === $category && $existing->subject === $subject && $existing->trip_id === $tripId
            ? $existing
            : throw SupportTicketStateException::idempotencyKeyReused();
    }
}
