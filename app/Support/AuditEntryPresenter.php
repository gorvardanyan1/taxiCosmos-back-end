<?php

namespace App\Support;

use App\Enums\AuditTargetType;
use App\Models\Audit\ActivityLogEntry;

/**
 * An audit row as the Activity Log page and the CSV export show it. Everything comes from the copy
 * stored with the entry, so it reads the same after the actor or the record changes or goes away.
 */
final class AuditEntryPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function present(ActivityLogEntry $entry): array
    {
        $properties = $entry->properties?->all() ?? [];
        $changes = $entry->attribute_changes?->all() ?? [];

        return [
            'id' => $entry->id,
            'occurred_at' => $entry->created_at->toIso8601String(),
            'actor' => ['id' => $entry->causer_id, 'name' => $properties['actor_name'] ?? 'System', 'role' => $properties['actor_role'] ?? null],
            'action' => $entry->description,
            'target_type' => AuditTargetType::forClass($entry->subject_type)?->value,
            'target_id' => $entry->subject_id,
            'target' => $properties['target_label'] ?? '—',
            'reason' => $properties['reason'] ?? null,
            'ip' => $properties['ip'] ?? '—',
            'user_agent' => $properties['user_agent'] ?? null,
            'before' => $changes['old'] ?? [],
            'after' => $changes['attributes'] ?? [],
        ];
    }
}
