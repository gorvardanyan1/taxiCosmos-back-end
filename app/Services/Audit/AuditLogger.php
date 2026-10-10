<?php

namespace App\Services\Audit;

use App\Enums\AdminRole;
use App\Enums\AuditTargetType;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Spatie\Activitylog\Contracts\Activity;

/**
 * The one way admin actions and sign-in events are written to the audit log (docs/audit-log.md).
 *
 * Each entry stores its own copy of who, what and where (actor name and role, target reference, IP,
 * user agent), so it stays readable after the actor or the record is renamed or deleted. The diff
 * goes through AuditRedactor: sensitive values are never stored.
 */
final class AuditLogger
{
    public const ADMIN_LOG = 'admin';

    public const AUTH_LOG = 'auth';

    /**
     * @param  string  $action  dotted name, e.g. driver.document.rejected
     * @param  array<string, mixed>  $old  values before the change
     * @param  array<string, mixed>  $new  values after the change
     * @param  array<string, mixed>  $context  extra facts (never secrets or personal data)
     */
    public function record(
        ?User $actor,
        string $action,
        ?Model $target = null,
        ?string $reason = null,
        array $old = [],
        array $new = [],
        array $context = [],
        bool $reasonRequired = false,
        string $log = self::ADMIN_LOG,
        ?string $targetLabel = null,
    ): Activity {
        $reason = $reason === null ? null : trim($reason);
        $reason = $reason === '' ? null : $reason;

        if ($reasonRequired && $reason === null) {
            throw new InvalidArgumentException("The action {$action} needs a reason.");
        }

        [$before, $after] = AuditRedactor::diff($old, $new);

        $logger = activity($log)
            ->event(str($action)->afterLast('.')->toString())
            ->withChanges(['old' => $before, 'attributes' => $after])
            ->withProperties(array_filter([
                'reason' => $reason,
                'ip' => request()->ip(),
                'user_agent' => mb_substr((string) request()->userAgent(), 0, 255) ?: null,
                'actor_name' => $actor?->name,
                'actor_role' => $actor === null ? null : $this->roleOf($actor),
                'target_label' => $targetLabel ?? ($target === null ? null : AuditTargetType::describe($target)),
                'context' => $context === [] ? null : $context,
            ], fn ($value) => $value !== null));

        if ($actor !== null) {
            $logger->causedBy($actor);
        }

        if ($target !== null) {
            $logger->performedOn($target);
        }

        return $logger->log($action);
    }

    private function roleOf(User $user): ?string
    {
        foreach (AdminRole::cases() as $role) {
            if ($user->hasRole($role->value)) {
                return $role->value;
            }
        }

        return null;
    }
}
