import { router } from '@inertiajs/react';
import type { CSSProperties, ReactNode } from 'react';

export const UNAVAILABLE_HINT = 'Not available yet — this action is built in a later task.';

/**
 * A button for a server action. `url` is null until the owning domain task adds the
 * endpoint; the button is then disabled instead of pretending to succeed.
 */
export default function ActionButton({
    url, method = 'post', className, style, children, data, confirm,
}: {
    url: string | null;
    method?: 'post' | 'patch' | 'delete';
    className: string;
    style?: CSSProperties;
    children: ReactNode;
    data?: Record<string, string | number | boolean>;
    confirm?: string;
}) {
    return (
        <button
            type="button"
            disabled={url === null}
            title={url === null ? UNAVAILABLE_HINT : undefined}
            className={`${className} disabled:cursor-not-allowed disabled:opacity-40`}
            style={style}
            onClick={(event) => {
                event.stopPropagation();
                if (url === null || (confirm && !window.confirm(confirm))) return;
                router.visit(url, { method, data, preserveScroll: true });
            }}
        >
            {children}
        </button>
    );
}
