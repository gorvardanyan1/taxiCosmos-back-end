import { render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { currentLocale, intlLocale, isLocale, loadLocale, LOCALE_NAMES, LOCALES, setDictionary, t, tc, tk, tn } from '@/lib/i18n';

afterEach(() => setDictionary('en', {}));

describe('t()', () => {
    it('returns the English text when there is no dictionary or no entry', () => {
        expect(t('Riders')).toBe('Riders');
        setDictionary('ru', { Riders: 'Пассажиры' });
        expect(t('Drivers')).toBe('Drivers');
    });

    it('translates from the loaded dictionary', () => {
        setDictionary('ru', { Riders: 'Пассажиры' });
        expect(t('Riders')).toBe('Пассажиры');
    });

    it('fills :placeholders in the translated text, wherever the language puts them', () => {
        setDictionary('ru', { 'Refund :code': 'Возврат :code', 'Edit :name': 'Редактировать: :name' });
        expect(t('Refund :code', { code: 'TX-1' })).toBe('Возврат TX-1');
        expect(t('Edit :name', { name: 'Анна' })).toBe('Редактировать: Анна');
        expect(t('Hello :name :other', { name: 'A' })).toBe('Hello A :other');
        expect(t('Total :n', { n: 0 })).toBe('Total 0');
        expect(t('Name :n', { n: null })).toBe('Name ');
    });

    it('does not treat a time or a URL as a placeholder', () => {
        expect(t('Open at 10:30 or https://x.test')).toBe('Open at 10:30 or https://x.test');
    });

    it('falls back to the key for an empty translation', () => {
        setDictionary('hy', { Riders: '' });
        expect(t('Riders')).toBe('Riders');
    });

    it('tk() marks a key without translating it', () => {
        setDictionary('ru', { Riders: 'Пассажиры' });
        expect(tk('Riders')).toBe('Riders');
        expect(t(tk('Riders'))).toBe('Пассажиры');
    });
});

describe('tc() plurals', () => {
    const key = ':count driver|:count drivers';

    it('uses one/other in English', () => {
        expect(tc(key, 1)).toBe('1 driver');
        expect(tc(key, 0)).toBe('0 drivers');
        expect(tc(key, 2)).toBe('2 drivers');
    });

    it('uses the three Russian forms (one, few, many) for every number class', () => {
        setDictionary('ru', { [key]: ':count водитель|:count водителя|:count водителей' });
        const out = [0, 1, 2, 4, 5, 11, 12, 14, 21, 22, 25, 101, 111].map((n) => tc(key, n));
        expect(out).toEqual([
            '0 водителей', '1 водитель', '2 водителя', '4 водителя', '5 водителей', '11 водителей', '12 водителей',
            '14 водителей', '21 водитель', '22 водителя', '25 водителей', '101 водитель', '111 водителей',
        ]);
    });

    it('uses the Russian "other" form for fractions by falling back to the last form', () => {
        setDictionary('ru', { [key]: ':count водитель|:count водителя|:count водителей' });
        expect(tc(key, 1.5)).toBe('1,5 водителей');
    });

    it('uses one/other in Armenian, where 0 and 1 are both "one"', () => {
        setDictionary('hy', { [key]: ':count վարորդ|:count վարորդներ' });
        expect(tc(key, 1)).toBe('1 վարորդ');
        expect(tc(key, 5)).toBe('5 վարորդներ');
    });

    it('formats the count with the language\'s digits grouping', () => {
        setDictionary('ru', { [key]: ':count водитель|:count водителя|:count водителей' });
        expect(tc(key, 1234)).toBe('1\u00a0234 водителя'); // ends in 4: the "few" form
        setDictionary('en', {});
        expect(tc(key, 1234)).toBe('1,234 drivers');
    });

    it('passes extra replacements through', () => {
        expect(tc('Overdue :count day by :who|Overdue :count days by :who', 3, { who: 'ops' })).toBe('Overdue 3 days by ops');
    });
});

describe('tn() sentences with React nodes', () => {
    it('puts the node where the translation puts the placeholder', () => {
        setDictionary('ru', { 'This lets :name book trips again.': 'Это позволит пассажиру :name снова заказывать поездки.' });
        const { container } = render(<p>{tn('This lets :name book trips again.', { name: <strong>Анна</strong> })}</p>);

        expect(container.textContent).toBe('Это позволит пассажиру Анна снова заказывать поездки.');
        expect(screen.getByText('Анна').tagName).toBe('STRONG');
    });

    it('works when the placeholder comes first, and leaves unknown placeholders alone', () => {
        setDictionary('hy', { 'Cancel :trip immediately?': ':trip-ը անմիջապես չեղարկե՞լ' });
        const { container } = render(<p>{tn('Cancel :trip immediately?', { trip: <b>TK-1</b> })}</p>);
        expect(container.textContent).toBe('TK-1-ը անմիջապես չեղարկե՞լ');

        const other = render(<p>{tn('Hi :who :other', { who: 'A' })}</p>);
        expect(other.container.textContent).toBe('Hi A :other');
    });
});

describe('locales', () => {
    it('knows English, Russian and Armenian, each by its own name', () => {
        expect([...LOCALES]).toEqual(['en', 'ru', 'hy']);
        expect(LOCALE_NAMES).toEqual({ en: 'English', ru: 'Русский', hy: 'Հայերեն' });
        expect(isLocale('ru')).toBe(true);
        expect(isLocale('de')).toBe(false);
        expect(isLocale(undefined)).toBe(false);
    });

    it('loads a dictionary file, sets the document language and the Intl locale', async () => {
        await loadLocale('ru');
        expect(currentLocale()).toBe('ru');
        expect(intlLocale()).toBe('ru-RU');
        expect(document.documentElement.lang).toBe('ru');
        expect(t('Riders')).toBe('Пассажиры');

        await loadLocale('hy');
        expect(intlLocale()).toBe('hy-AM');
        expect(t('Riders')).toBe('Ուղևորներ');
    });

    it('treats English, unknown and missing locales as English with no dictionary', async () => {
        await loadLocale('ru');
        for (const value of ['en', 'de', undefined, null, 42]) {
            await loadLocale(value);
            expect(currentLocale()).toBe('en');
            expect(t('Riders')).toBe('Riders');
        }
    });
});
