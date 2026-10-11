import { afterEach, describe, expect, it } from 'vitest';
import { compactNumber, daysUntil, formatBasisPoints, formatDate, formatDateTime, formatMoney, formatNumber, formatRelative, parseMoneyInput } from '@/lib/format';
import { setDictionary } from '@/lib/i18n';

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

describe('formatting follows the admin\'s language', () => {
    const currencies = [{ code: 'AMD', symbol: '֏', decimals: 0 }, { code: 'USD', symbol: '$', decimals: 2 }];
    const money = { amount: 123456789, currency: 'USD' };

    afterEach(() => setDictionary('en', {}));

    it('keeps English output unchanged', () => {
        expect(formatMoney(money, currencies)).toBe('USD 1,234,567.89');
        expect(formatNumber(1234567)).toBe('1,234,567');
        expect(compactNumber(1_500_000)).toBe('1.5M');
        expect(formatBasisPoints(480)).toBe('4.8%');
        expect(formatDate('2026-10-11T10:00:00Z', 'Asia/Yerevan')).toBe('Oct 11, 2026');
        expect(formatDateTime('2026-10-11T10:05:00Z', 'Asia/Yerevan')).toBe('Oct 11 · 14:05');
    });

    it('formats numbers and money with Russian separators (the money is still integer minor units)', () => {
        setDictionary('ru', {});
        expect(formatNumber(1234567)).toBe('1\u00a0234\u00a0567');
        expect(formatMoney(money, currencies)).toBe('USD 1\u00a0234\u00a0567,89');
        expect(formatMoney({ amount: 150000, currency: 'AMD' }, currencies)).toBe('AMD 150\u00a0000');
        expect(formatMoney({ amount: -5050, currency: 'USD' }, currencies)).toBe('- USD 50,50');
    });

    it('formats numbers and money with Armenian separators', () => {
        setDictionary('hy', {});
        expect(formatNumber(1234567)).toBe('1\u00a0234\u00a0567');
        expect(formatMoney(money, currencies)).toBe('USD 1\u00a0234\u00a0567,89');
    });

    it('writes percentages the local way', () => {
        setDictionary('ru', {});
        expect(formatBasisPoints(480)).toMatch(/^4,8\s?%$/);
        setDictionary('hy', {});
        expect(formatBasisPoints(2500)).toMatch(/^25\s?%$/);
    });

    it('writes dates and times in the language, in the zone\'s timezone', () => {
        setDictionary('ru', {});
        expect(formatDate('2026-10-11T10:00:00Z', 'Asia/Yerevan')).toBe('11 окт. 2026 г.');
        expect(formatDateTime('2026-10-11T10:05:00Z', 'Asia/Yerevan')).toBe('11 окт. · 14:05');
        // A bare date is a calendar day, not an instant: no timezone shift.
        expect(formatDate('2026-10-11', 'Pacific/Auckland')).toBe('11 окт. 2026 г.');

        setDictionary('hy', {});
        expect(formatDate('2026-10-11T10:00:00Z', 'Asia/Yerevan')).toContain('2026');
        expect(formatDateTime('2026-10-11T10:05:00Z', 'Asia/Yerevan')).toMatch(/14:05$/);
    });

    it('writes relative times in the language', () => {
        const now = new Date('2026-10-05T12:00:00Z');

        setDictionary('ru', {});
        expect(formatRelative('2026-10-05T11:58:00Z', now)).toBe('2 мин. назад');
        expect(formatRelative('2026-10-05T09:00:00Z', now)).toBe('3 ч назад');
        expect(formatRelative('2026-10-06T12:00:00Z', now)).toBe('через 1 дн.');

        setDictionary('hy', {});
        expect(formatRelative('2026-10-05T11:58:00Z', now)).toBe('2 ր առաջ');
        expect(formatRelative('2026-10-05T09:00:00Z', now)).toBe('3 ժ առաջ');
        expect(formatRelative('2026-10-06T12:00:00Z', now)).toMatch(/^1\s?օրից$/);
    });

    it('abbreviates large numbers in Russian and Armenian instead of with English K and M', () => {
        setDictionary('ru', {});
        expect(compactNumber(1_500_000)).toBe('1,5\u00a0млн');
        setDictionary('hy', {});
        expect(compactNumber(1_500_000)).not.toMatch(/M$/);
    });

    it('still parses typed money the same way in every language', () => {
        setDictionary('ru', {});
        expect(parseMoneyInput('18,500', 0)).toBe(18500);
        expect(parseMoneyInput('12.5', 2)).toBe(1250);
    });
});
