import type { ReactNode } from 'react';
import type { PermissionRule } from '@/lib/permissions';
import { useShared } from '@/lib/useShared';

/** Renders its children only for an admin who holds the permission (any one of a list). The server still enforces it. */
export default function Can({ permission, children }: { permission: PermissionRule; children: ReactNode }) {
    const { can } = useShared();

    return can(permission) ? <>{children}</> : null;
}
