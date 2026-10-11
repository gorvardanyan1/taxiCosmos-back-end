<?php

namespace App\Support;

use App\Models\Audit\ActivityLogEntry;
use App\Models\User;
use App\Queries\RiderQuery;

/**
 * Riders as the admin list and detail pages show them. Trips, payments, tickets, ratings and
 * payment methods have no tables yet (P2-T4, P6, P7), so those parts are empty or zero; nothing
 * is borrowed from fixtures. The Activity tab is real: what admins did to this rider.
 */
final class RiderPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function row(User $rider): array
    {
        return [
            'id' => $rider->id,
            'code' => RiderQuery::code($rider->id),
            'name' => $rider->name ?? 'Unnamed rider',
            'phone' => $rider->phone ?? '—',
            'email' => $rider->email ?? '—',
            'status' => $rider->status->value,
            'trips_count' => 0,
            'registered_at' => $rider->created_at->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(User $rider): array
    {
        $zero = ['amount' => 0, 'currency' => config('taxikosmos.base_currency')];

        return [
            ...self::row($rider),
            // The edit form needs the raw values, not the "—" placeholders.
            'raw' => ['name' => $rider->name, 'email' => $rider->email, 'phone' => $rider->phone, 'locale' => $rider->locale],
            'locale' => $rider->locale,
            'suspension_reason' => $rider->status->value === 'suspended' ? $rider->suspension_reason : null,
            'last_login_at' => $rider->last_login_at?->toIso8601String(),
            'stats' => ['total_spent' => $zero, 'cancellation_rate_bp' => 0, 'open_tickets' => 0],
            'deletion_scheduled_for' => null,
            'payment_methods' => [],
            'records' => [
                'trips' => [], 'payments' => [], 'tickets' => [], 'ratings' => [],
                'activity' => self::activity($rider),
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function activity(User $rider): array
    {
        return ActivityLogEntry::query()
            ->where('subject_type', User::class)->where('subject_id', $rider->id)
            ->latest('id')->limit(50)->get()
            ->map(function (ActivityLogEntry $entry) {
                $properties = $entry->properties?->all() ?? [];
                $by = $properties['actor_name'] ?? 'System';
                $reason = $properties['reason'] ?? null;

                return [
                    'reference' => 'ACT-'.$entry->id,
                    'description' => "{$entry->description} by {$by}".($reason ? ": {$reason}" : ''),
                    'status' => 'completed',
                    'value' => '—',
                    'occurred_at' => $entry->created_at->toIso8601String(),
                ];
            })->all();
    }
}
