import { afterEach, describe, expect, it, vi } from 'vitest';
import { compactNumber, formatBasisPoints, formatDate, formatDateTime, formatMoney, formatNumber, formatRelative } from '@/lib/format';
import { setDictionary } from '@/lib/i18n';
import { armenianCompact, armenianDate, armenianRelative } from '@/lib/intlFallback';

const norm = (text: string) => text.replace(/[  ]/g, ' ');
const currencies = [{ code: 'USD', symbol: '$', decimals: 2 }];

/** Chrome ships no Armenian Intl data: asking for hy-AM quietly gives English. Reproduce that here. */
function withoutArmenianData() {
    const supported = Intl.DateTimeFormat.supportedLocalesOf.bind(Intl.DateTimeFormat);
    vi.spyOn(Intl.DateTimeFormat, 'supportedLocalesOf').mockImplementation((locales) => supported(locales).filter((tag) => !tag.startsWith('hy')));
    const realDate = Date.prototype.toLocaleDateString;
    vi.spyOn(Date.prototype, 'toLocaleDateString').mockImplementation(function (this: Date, locale?: Intl.LocalesArgument, options?: Intl.DateTimeFormatOptions) {
        return realDate.call(this, String(locale).startsWith('hy') ? 'en-US' : locale, options);
    });
    const realRelative = Intl.RelativeTimeFormat;
    vi.spyOn(Intl, 'RelativeTimeFormat').mockImplementation(function (locale?: Intl.LocalesArgument, options?: Intl.RelativeTimeFormatOptions) {
        return new realRelative(String(locale).startsWith('hy') ? 'en-US' : locale, options);
    } as never);
    const realNumber = Number.prototype.toLocaleString;
    vi.spyOn(Number.prototype, 'toLocaleString').mockImplementation(function (this: number, locale?: Intl.LocalesArgument, options?: Intl.NumberFormatOptions) {
        return realNumber.call(this, String(locale).startsWith('hy') ? 'en-US' : locale, options);
    });
}

describe('Armenian formatters match the full-ICU Armenian output', () => {
    const reference = (date: Date, options: Intl.DateTimeFormatOptions) => norm(date.toLocaleDateString('hy-AM', options));

    it('writes every month and the year as ICU does, in the given timezone', () => {
        for (let month = 0; month < 12; month++) {
            const date = new Date(Date.UTC(2026, month, 15, 10));
            expect(norm(armenianDate(date, 'Asia/Yerevan', true))).toBe(reference(date, { month: 'short', day: 'numeric', year: 'numeric', timeZone: 'Asia/Yerevan' }));
            expect(norm(armenianDate(date, 'Asia/Yerevan', false))).toBe(reference(date, { month: 'short', day: 'numeric', timeZone: 'Asia/Yerevan' }));
        }
    });

    it('uses the date in the zone, not in UTC, across midnight', () => {
        const date = new Date('2026-10-11T22:30:00Z'); // 12 Oct in Yerevan (UTC+4)
        expect(armenianDate(date, 'Asia/Yerevan', false)).toBe(armenianDate(new Date('2026-10-12T00:30:00Z'), 'UTC', false));
        expect(armenianDate(date, 'UTC', false)).not.toBe(armenianDate(date, 'Asia/Yerevan', false));
    });

    it('writes relative times as ICU does for every unit, past and future', () => {
        const rtf = new Intl.RelativeTimeFormat('hy-AM', { style: 'short', numeric: 'always' });
        const cases: Array<[number, Intl.RelativeTimeFormatUnit, number]> = [[-30, 'second', 30], [-120, 'minute', 2], [-3 * 3600, 'hour', 3], [-2 * 86400, 'day', 2], [45, 'second', 45], [5 * 60, 'minute', 5], [4 * 3600, 'hour', 4], [3 * 86400, 'day', 3]];
        for (const [seconds, unit, value] of cases) {
            expect(norm(armenianRelative(-seconds))).toBe(norm(rtf.format(seconds < 0 ? -value : value, unit)));
        }
    });

    it('abbreviates thousands, millions and billions as ICU does', () => {
        for (const value of [1500, 12_000, 999_999, 1_500_000, 18_400_000, 2_000_000_000, 7]) {
            expect(norm(armenianCompact(value))).toBe(norm(value.toLocaleString('hy-AM', { notation: 'compact', maximumFractionDigits: 1 })));
        }
    });
});

describe('in a browser without Armenian data (Chrome)', () => {
    afterEach(() => { vi.restoreAllMocks(); setDictionary('en', {}); });

    it('would print English if the app trusted Intl (the failure this guards against)', () => {
        withoutArmenianData();
        expect(new Date('2026-10-11T10:00:00Z').toLocaleDateString('hy-AM', { month: 'short', day: 'numeric', year: 'numeric', timeZone: 'UTC' })).toBe('Oct 11, 2026');
    });

    it('still writes dates, relative times and numbers in Armenian', () => {
        withoutArmenianData();
        setDictionary('hy', {});
        const now = new Date('2026-10-05T12:00:00Z');

        expect(formatDate('2026-10-11T10:00:00Z', 'Asia/Yerevan')).toBe('11 հոկ, 2026 թ.');
        expect(formatDate('2026-10-11', 'Pacific/Auckland')).toBe('11 հոկ, 2026 թ.');
        expect(formatDateTime('2026-10-11T10:05:00Z', 'Asia/Yerevan')).toBe('11 հոկ · 14:05');
        expect(formatRelative('2026-10-05T11:58:00Z', now)).toBe('2 ր առաջ');
        expect(formatRelative('2026-10-05T11:59:30Z', now)).toBe('30 վրկ առաջ');
        expect(formatRelative('2026-10-06T12:00:00Z', now)).toBe('1 օրից');
        expect(formatRelative('2026-10-05T15:00:00Z', now)).toBe('3 ժ-ից');
        expect(norm(formatNumber(1234567))).toBe('1 234 567');
        expect(norm(formatMoney({ amount: 123456789, currency: 'USD' }, currencies))).toBe('USD 1 234 567,89');
        expect(norm(compactNumber(1_500_000))).toBe('1,5 մլն');
        expect(norm(formatBasisPoints(480))).toBe('4,8%');
    });

    it('leaves English and Russian alone', () => {
        withoutArmenianData();
        setDictionary('ru', {});
        expect(formatDate('2026-10-11T10:00:00Z', 'Asia/Yerevan')).toBe('11 окт. 2026 г.');
        setDictionary('en', {});
        expect(formatDate('2026-10-11T10:00:00Z', 'Asia/Yerevan')).toBe('Oct 11, 2026');
    });
});
