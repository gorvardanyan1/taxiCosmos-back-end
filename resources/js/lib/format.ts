import type { Currency, Money } from '@/types';

/** Money arrives as integer minor units; this is the only place it becomes a decimal, for display. */
export function formatMoney(money: Money, currencies: Currency[], options: { compact?: boolean; signed?: boolean } = {}): string {
    const decimals = currencies.find((c) => c.code === money.currency)?.decimals ?? 2;
    const major = money.amount / 10 ** decimals;
    const sign = money.amount < 0 ? '- ' : options.signed && money.amount > 0 ? '+ ' : '';
    const body = options.compact
        ? compactNumber(Math.abs(major))
        : Math.abs(major).toLocaleString('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });

    return `${sign}${money.currency} ${body}`;
}

/** Plain value for charts/axes (display only). */
export function toMajorUnits(money: Money, currencies: Currency[]): number {
    const decimals = currencies.find((c) => c.code === money.currency)?.decimals ?? 2;
    return money.amount / 10 ** decimals;
}

export function compactNumber(value: number): string {
    if (value >= 1_000_000) return `${trimZero(value / 1_000_000)}M`;
    if (value >= 1_000) return `${trimZero(value / 1_000)}K`;
    return value.toLocaleString('en-US');
}

function trimZero(value: number): string {
    return value.toFixed(1).replace(/\.0$/, '');
}

export function formatNumber(value: number): string {
    return value.toLocaleString('en-US');
}

/** Basis points (480) → "4.8%". */
export function formatBasisPoints(bp: number): string {
    return `${trimZero(bp / 100)}%`;
}

export function formatDate(iso: string, timeZone: string): string {
    return new Date(iso.length === 10 ? `${iso}T00:00:00Z` : iso).toLocaleDateString('en-US', {
        month: 'short', day: 'numeric', year: 'numeric', timeZone: iso.length === 10 ? 'UTC' : timeZone,
    });
}

/** "Oct 5 · 18:14" in the admin's timezone. */
export function formatDateTime(iso: string, timeZone: string): string {
    const date = new Date(iso);
    const day = date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', timeZone });
    const time = date.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', timeZone });
    return `${day} · ${time}`;
}

/** "2m ago", "3h ago", "in 3 days", "Overdue 2 days" style helpers. */
export function formatRelative(iso: string, now: Date = new Date()): string {
    const seconds = Math.round((now.getTime() - new Date(iso).getTime()) / 1000);
    const abs = Math.abs(seconds);
    const unit = abs < 60 ? `${abs}s` : abs < 3600 ? `${Math.floor(abs / 60)}m` : abs < 86400 ? `${Math.floor(abs / 3600)}h` : `${Math.floor(abs / 86400)}d`;
    return seconds >= 0 ? `${unit} ago` : `in ${unit}`;
}

export function daysUntil(iso: string, now: Date = new Date()): number {
    return Math.round((new Date(iso).getTime() - now.getTime()) / 86_400_000);
}

export function initials(name: string): string {
    return name.split(/\s+/).filter(Boolean).map((part) => part[0]).join('').slice(0, 2).toUpperCase();
}

const avatarColors = ['#6366f1', '#0891b2', '#059669', '#d97706', '#e11d48', '#7c3aed', '#0284c7', '#db2777'];

export function avatarColor(seed: number): string {
    return avatarColors[Math.abs(seed) % avatarColors.length];
}

/**
 * Parses a typed amount ("18,500", "12.5") into integer minor units without floating-point
 * maths. Returns null for anything that is not a non-negative amount with at most `decimals` places.
 */
export function parseMoneyInput(input: string, decimals: number): number | null {
    const cleaned = input.replace(/[\s,]/g, '');
    const pattern = decimals > 0 ? new RegExp(`^(\\d+)(?:\\.(\\d{1,${decimals}}))?$`) : /^(\d+)$/;
    const match = cleaned.match(pattern);
    if (!match) return null;
    const fraction = (match[2] ?? '').padEnd(decimals, '0');
    const minor = Number(`${match[1]}${fraction}`);
    return Number.isSafeInteger(minor) ? minor : null;
}

export function newIdempotencyKey(): string {
    return globalThis.crypto?.randomUUID?.() ?? `${Date.now()}-${Math.random().toString(16).slice(2)}`;
}
