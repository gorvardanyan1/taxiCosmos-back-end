import { router } from '@inertiajs/react';

export type QueryChanges = Record<string, string | number | null | undefined>;

/**
 * All list filters, sorts, pages, tabs and selections live in the URL so deep links and
 * browser back/forward restore them. Each change is a real Inertia visit (history entry);
 * the server reads the query and returns the matching props.
 */
export function buildQueryUrl(changes: QueryChanges, options: { resetPage?: boolean; location?: Location } = {}): string {
    const location = options.location ?? window.location;
    const query = new URLSearchParams(location.search);

    for (const [key, value] of Object.entries(changes)) {
        if (value === null || value === undefined || value === '') {
            query.delete(key);
        } else {
            query.set(key, String(value));
        }
    }

    if (options.resetPage) {
        query.delete('page');
    }

    const search = query.toString();
    return `${location.pathname}${search ? `?${search}` : ''}`;
}

export function visitQuery(changes: QueryChanges, options: { resetPage?: boolean } = {}): void {
    router.get(buildQueryUrl(changes, options), {}, { preserveState: true, preserveScroll: true });
}

export const filterKey = (name: string): string => `filter[${name}]`;

/** Row actions are URL templates with an {id} placeholder (e.g. /admin/riders/{id}/suspend). */
export function withId(template: string | null, id: number | null | undefined): string | null {
    return template === null || id === null || id === undefined ? null : template.replace('{id}', String(id));
}
