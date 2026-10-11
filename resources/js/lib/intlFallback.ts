/**
 * Armenian formatting that does not depend on the browser. Google Chrome ships no Armenian locale data
 * for Intl (`Intl.DateTimeFormat.supportedLocalesOf(['hy-AM'])` is empty), so dates, numbers and relative
 * times for `hy` would silently come out in English. Where the browser has the data it is used; otherwise
 * these formatters give the same text (the tests compare them with a full-ICU `hy-AM` formatter).
 */

/** True when this browser can format `tag` (it falls back to English when it cannot). */
export const hasIntlData = (tag: string): boolean => Intl.DateTimeFormat.supportedLocalesOf([tag]).length > 0;

/** Abbreviated months, as CLDR writes them for Armenian. */
const MONTHS = ['հնվ', 'փտվ', 'մրտ', 'ապր', 'մյս', 'հնս', 'հլս', 'օգս', 'սեպ', 'հոկ', 'նոյ', 'դեկ'] as const;

/** "11 հոկ" or "11 հոկ, 2026 թ." — the day and month as they are in the given timezone. */
export function armenianDate(date: Date, timeZone: string, withYear: boolean): string {
    const parts = new Intl.DateTimeFormat('en-US', { timeZone, day: 'numeric', month: 'numeric', year: 'numeric' }).formatToParts(date);
    const get = (type: string) => Number(parts.find((part) => part.type === type)?.value);

    return `${get('day')} ${MONTHS[get('month') - 1]}${withYear ? `, ${get('year')} թ.` : ''}`;
}

/** "2 ր առաջ" / "3 օրից": `seconds` is positive for the past. */
export function armenianRelative(seconds: number): string {
    const abs = Math.abs(seconds);
    const [value, unit]: [number, string] = abs < 60 ? [abs, 'վրկ'] : abs < 3600 ? [Math.floor(abs / 60), 'ր'] : abs < 86400 ? [Math.floor(abs / 3600), 'ժ'] : [Math.floor(abs / 86400), 'օր'];

    if (seconds >= 0) return `${value} ${unit} առաջ`;

    return unit === 'օր' ? `${value} օրից` : `${value} ${unit}-ից`;
}

/** Digits grouped with a no-break space and a decimal comma, which is what Armenian uses (and Russian shares). */
export const ARMENIAN_NUMBER_LOCALE = 'ru-RU';

const UNITS: ReadonlyArray<readonly [number, string]> = [[1e9, 'մլրդ'], [1e6, 'մլն'], [1e3, 'հզր']];

/** 1500 → "1,5 հզր", 1500000 → "1,5 մլն" (one decimal, rounded up into the next unit like Intl does). */
export function armenianCompact(value: number): string {
    const rounded = (n: number) => Math.round(n * 10) / 10;
    const format = (n: number) => n.toLocaleString(ARMENIAN_NUMBER_LOCALE, { maximumFractionDigits: 1 });

    for (let i = 0; i < UNITS.length; i++) {
        const [divisor, name] = UNITS[i];
        if (value < divisor) continue;

        const scaled = rounded(value / divisor);
        if (scaled >= 1000 && i > 0) return `${format(rounded(value / UNITS[i - 1][0]))} ${UNITS[i - 1][1]}`;

        return `${format(scaled)} ${name}`;
    }

    return format(value);
}
