import { router } from '@inertiajs/react';
import { ChevronDown, ChevronLeft, ChevronRight, ChevronUp } from 'lucide-react';
import type { ReactNode } from 'react';
import EmptyState from '@/Components/EmptyState';
import { visitQuery } from '@/lib/query';
import { card, th, td } from '@/lib/ui';
import type { Paginated } from '@/types';

export interface Column<T> {
    key: string;
    header: string;
    align?: 'left' | 'right';
    className?: string;
    /** Makes the header clickable: sorts by this field (`sort=<key>`, `sort=-<key>`), a third click clears it. */
    sortKey?: string;
    render: (row: T) => ReactNode;
}

interface Props<T> {
    paginator: Paginated<T>;
    columns: Column<T>[];
    rowKey: (row: T) => string | number;
    onRowClick?: (row: T) => void;
    empty?: { title: string; hint?: string };
    minWidth?: number;
    /** The active `sort` query value, e.g. "-registered_at". */
    sort?: string | null;
}

/** ascending → descending → no sort */
export function nextSort(current: string | null | undefined, key: string): string | null {
    if (current === key) return `-${key}`;
    if (current === `-${key}`) return null;
    return key;
}

/**
 * Renders Laravel's LengthAwarePaginator. Page links come from the server (they already
 * carry the current filter/sort query), so paging is a normal Inertia visit and survives
 * reloads, deep links and back/forward.
 */
export default function PaginatedTable<T>({ paginator, columns, rowKey, onRowClick, empty, minWidth = 820, sort = null }: Props<T>) {
    const pages = paginator.links.slice(1, -1);
    const previous = paginator.links[0]?.url ?? null;
    const next = paginator.links[paginator.links.length - 1]?.url ?? null;
    const go = (url: string | null) => url && router.get(url, {}, { preserveState: true, preserveScroll: true });

    return (
        <div className={`${card} overflow-x-auto`}>
            <table className="w-full" style={{ minWidth }}>
                <thead>
                    <tr className="border-b border-slate-100">
                        {columns.map((column) => (
                            <th
                                key={column.key}
                                className={`${th} ${column.align === 'right' ? 'text-right' : ''}`}
                                aria-sort={column.sortKey ? (sort === column.sortKey ? 'ascending' : sort === `-${column.sortKey}` ? 'descending' : 'none') : undefined}
                            >
                                {column.sortKey ? (
                                    <button type="button" onClick={() => visitQuery({ sort: nextSort(sort, column.sortKey!) }, { resetPage: true })} className="inline-flex items-center gap-1 uppercase hover:text-indigo-600">
                                        {column.header}
                                        {sort === column.sortKey && <ChevronUp size={12} aria-label="sorted ascending" />}
                                        {sort === `-${column.sortKey}` && <ChevronDown size={12} aria-label="sorted descending" />}
                                    </button>
                                ) : column.header}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {paginator.data.length === 0 && (
                        <tr>
                            <td colSpan={columns.length}>
                                <EmptyState title={empty?.title} hint={empty?.hint ?? 'Try adjusting your filters'} />
                            </td>
                        </tr>
                    )}
                    {paginator.data.map((row) => (
                        <tr
                            key={rowKey(row)}
                            onClick={onRowClick ? () => onRowClick(row) : undefined}
                            className={`border-b border-slate-50 transition hover:bg-slate-50/70 ${onRowClick ? 'cursor-pointer' : ''}`}
                        >
                            {columns.map((column) => (
                                <td key={column.key} className={`${td} ${column.align === 'right' ? 'text-right' : ''} ${column.className ?? ''}`}>
                                    {column.render(row)}
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
            <div className="flex items-center justify-between border-t border-slate-100 px-5 py-3.5">
                <p className="text-xs" style={{ color: '#94a3b8' }}>
                    Showing{' '}
                    <span style={{ color: '#0f172a', fontWeight: 600 }}>
                        {paginator.from ?? 0}–{paginator.to ?? 0}
                    </span>{' '}
                    of {paginator.total}
                </p>
                <div className="flex items-center gap-1">
                    <button aria-label="Previous page" disabled={!previous} onClick={() => go(previous)} className="rounded-xl p-2 transition-colors hover:bg-slate-50 disabled:opacity-30" style={{ color: '#64748b' }}>
                        <ChevronLeft size={14} />
                    </button>
                    {pages.map((link) => (
                        <button
                            key={link.label}
                            aria-current={link.active ? 'page' : undefined}
                            disabled={!link.url || link.label === '...'}
                            onClick={() => go(link.url)}
                            className="h-8 w-8 rounded-xl text-xs font-semibold transition-all"
                            style={{
                                background: link.active ? 'linear-gradient(135deg, #6366f1, #8b5cf6)' : 'transparent',
                                color: link.active ? 'white' : '#64748b',
                                fontFamily: 'var(--font-mono)',
                            }}
                        >
                            {link.label}
                        </button>
                    ))}
                    <button aria-label="Next page" disabled={!next} onClick={() => go(next)} className="rounded-xl p-2 transition-colors hover:bg-slate-50 disabled:opacity-30" style={{ color: '#64748b' }}>
                        <ChevronRight size={14} />
                    </button>
                </div>
            </div>
        </div>
    );
}
