import { Search, SlidersHorizontal } from 'lucide-react';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import { filterKey, visitQuery } from '@/lib/query';
import type { Filters } from '@/types';

export interface SelectFilter {
    key: string;
    allLabel: string;
    options: { value: string; label: string }[];
}

interface Props {
    filters: Filters;
    searchPlaceholder?: string;
    selects?: SelectFilter[];
    resultLabel?: string;
    children?: ReactNode;
}

const inputStyle = { background: '#f8fafc', border: '1px solid #e2e8f0', color: '#0f172a', outline: 'none' } as const;

/**
 * Search box + select filters bound to filter[<key>] in the URL. Typing waits for a pause
 * (or Enter) before visiting; selects visit immediately. Every change resets to page 1.
 */
export default function FilterBar({ filters, searchPlaceholder = 'Search records…', selects = [], resultLabel, children }: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    // The search this box last sent (or last took from the URL).
    const sent = useRef(filters.search ?? '');

    const submit = (value: string) => {
        sent.current = value.trim();
        visitQuery({ [filterKey('search')]: sent.current }, { resetPage: true });
    };

    // When the URL's search changes for another reason (back/forward, clear filters), show it.
    // The response to our own request is ignored, so text typed meanwhile is not overwritten.
    useEffect(() => {
        const fromUrl = filters.search ?? '';
        if (fromUrl === sent.current) return;
        sent.current = fromUrl;
        setSearch(fromUrl);
    }, [filters.search]);

    useEffect(() => {
        if (search.trim() === sent.current) return;
        const timer = setTimeout(() => submit(search), 350);
        return () => clearTimeout(timer);
    }, [search]);

    const active = Object.keys(filters).length > 0;
    const clear = () => visitQuery(Object.fromEntries(Object.keys(filters).map((key) => [filterKey(key), null])), { resetPage: true });

    return (
        <div className="mb-5 flex flex-wrap items-center gap-3 rounded-2xl p-3" style={{ background: 'white', border: '1px solid #f1f5f9', boxShadow: '0 1px 3px rgba(0,0,0,0.04)' }}>
            <div className="relative max-w-sm min-w-56 flex-1">
                <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2" style={{ color: '#94a3b8' }} />
                <input
                    type="search"
                    aria-label="Search"
                    value={search}
                    onChange={(event) => setSearch(event.target.value)}
                    onKeyDown={(event) => event.key === 'Enter' && submit(search)}
                    placeholder={searchPlaceholder}
                    className="w-full rounded-xl py-2 pl-9 pr-3 text-sm"
                    style={inputStyle}
                />
            </div>
            {selects.map((select, index) => (
                <div key={select.key} className="flex items-center gap-1.5">
                    {index === 0 && <SlidersHorizontal size={14} style={{ color: '#94a3b8' }} />}
                    <select
                        aria-label={select.allLabel}
                        value={filters[select.key] ?? ''}
                        onChange={(event) => visitQuery({ [filterKey(select.key)]: event.target.value }, { resetPage: true })}
                        className="rounded-xl px-3 py-2 text-sm"
                        style={{ ...inputStyle, fontFamily: 'var(--font-display)', fontWeight: 500 }}
                    >
                        <option value="">{select.allLabel}</option>
                        {select.options.map((option) => (
                            <option key={option.value} value={option.value}>{option.label}</option>
                        ))}
                    </select>
                </div>
            ))}
            {children}
            {active && (
                <button onClick={clear} className="rounded-xl px-3 py-2 text-xs font-semibold transition-colors hover:bg-slate-100" style={{ color: '#6366f1' }}>
                    Clear filters
                </button>
            )}
            {resultLabel && <div className="ml-auto text-xs" style={{ color: '#94a3b8' }}>{resultLabel}</div>}
        </div>
    );
}
