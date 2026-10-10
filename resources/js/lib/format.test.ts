import { describe, expect, it } from 'vitest';
import { compactNumber, daysUntil, formatBasisPoints, formatMoney, formatRelative, parseMoneyInput } from '@/lib/format';

const currencies = [{ code: 'AMD', symbol: '֏', decimals: 0 }, { code: 'USD', symbol: '$', decimals: 2 }];

describe('formatMoney', () => {
    it('formats integer minor units with the currency’s decimals', () => {
        expect(formatMoney({ amount: 12500, currency: 'AMD' }, currencies)).toBe('AMD 12,500');
        expect(formatMoney({ amount: 1250, currency: 'USD' }, currencies)).toBe('USD 12.50');
        expect(formatMoney({ amount: 5, currency: 'USD' }, currencies)).toBe('USD 0.05');
    });

    it('prefixes negatives and optionally positives', () => {
        expect(formatMoney({ amount: -18000, currency: 'AMD' }, currencies)).toBe('- AMD 18,000');
        expect(formatMoney({ amount: 8920, currency: 'AMD' }, currencies, { signed: true })).toBe('+ AMD 8,920');
        expect(formatMoney({ amount: 0, currency: 'AMD' }, currencies, { signed: true })).toBe('AMD 0');
    });

    it('compacts large amounts', () => {
        expect(formatMoney({ amount: 18400000, currency: 'AMD' }, currencies, { compact: true })).toBe('AMD 18.4M');
        expect(formatMoney({ amount: 284000, currency: 'AMD' }, currencies, { compact: true })).toBe('AMD 284K');
        expect(compactNumber(1_000_000)).toBe('1M');
    });
});

describe('parseMoneyInput', () => {
    it('turns typed amounts into integer minor units without floats', () => {
        expect(parseMoneyInput('18,500', 0)).toBe(18500);
        expect(parseMoneyInput('12.5', 2)).toBe(1250);
        expect(parseMoneyInput('0.07', 2)).toBe(7);
        expect(parseMoneyInput(' 1 000.10 ', 2)).toBe(100010);
        // 0.1 + 0.2 style float traps do not apply: 0.29 stays exact.
        expect(parseMoneyInput('0.29', 2)).toBe(29);
    });

    it('rejects invalid input', () => {
        expect(parseMoneyInput('', 2)).toBeNull();
        expect(parseMoneyInput('-5', 2)).toBeNull();
        expect(parseMoneyInput('1.234', 2)).toBeNull();
        expect(parseMoneyInput('1.5', 0)).toBeNull();
        expect(parseMoneyInput('abc', 2)).toBeNull();
        expect(parseMoneyInput('99999999999999999999', 0)).toBeNull();
    });
});

describe('other formatters', () => {
    it('formats basis points and relative times', () => {
        expect(formatBasisPoints(480)).toBe('4.8%');
        expect(formatBasisPoints(1800)).toBe('18%');
        const now = new Date('2026-10-05T12:00:00Z');
        expect(formatRelative('2026-10-05T11:58:00Z', now)).toBe('2m ago');
        expect(formatRelative('2026-10-05T09:00:00Z', now)).toBe('3h ago');
        expect(formatRelative('2026-10-06T12:00:00Z', now)).toBe('in 1d');
        expect(daysUntil('2026-10-08T12:00:00Z', now)).toBe(3);
        expect(daysUntil('2026-10-03T12:00:00Z', now)).toBe(-2);
    });
});
