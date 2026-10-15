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

/** `resetScroll` goes back to the top of the page (opening a record from a long list shows it from its start). */
export function visitQuery(changes: QueryChanges, options: { resetPage?: boolean; resetScroll?: boolean } = {}): void {
    router.get(buildQueryUrl(changes, options), {}, { preserveState: true, preserveScroll: !options.resetScroll });
}

export const filterKey = (name: string): string => `filter[${name}]`;

/** Row actions are URL templates with an {id} placeholder (e.g. /admin/riders/{id}/suspend). */
export function withId(template: string | null, id: number | null | undefined): string | null {
    return template === null || id === null || id === undefined ? null : template.replace('{id}', String(id));
}
