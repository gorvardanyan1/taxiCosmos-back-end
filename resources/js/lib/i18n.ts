import { Fragment, createElement, type ReactNode } from 'react';

/**
 * Admin UI translations (P13-T11). The dictionaries are Laravel's JSON translation files
 * (lang/ru.json, lang/hy.json): the English text is the key, so the same strings serve the server
 * (`__('…')`) and the browser (`t('…')`). English needs no file: a missing key falls back to itself.
 *
 * The active dictionary is loaded once, before the app renders (app.tsx). Changing language reloads
 * the page, so components can call t() directly.
 *
 * - t('Rider suspended.')                  → translated text
 * - t('Suspend :name', { name })           → Laravel-style :placeholders
 * - tc(':count result|:count results', n)  → plural forms in the language's order (ru has three)
 * - tn('Suspend :name now?', { name: <b>…</b> }) → a whole sentence with React nodes in its placeholders
 * - tk('Riders')                           → marks a module-level constant; translate it where it is rendered
 */

export const LOCALES = ['en', 'ru', 'hy'] as const;
export type Locale = (typeof LOCALES)[number];

/** Each language by its own name: shown in the language pickers, never translated. */
export const LOCALE_NAMES: Record<Locale, string> = { en: 'English', ru: 'Русский', hy: 'Հայերեն' };

const INTL_LOCALES: Record<Locale, string> = { en: 'en-US', ru: 'ru-RU', hy: 'hy-AM' };

/** CLDR plural categories in the order the translation's |-separated forms are written. */
const PLURAL_ORDER: Record<Locale, Intl.LDMLPluralRule[]> = {
    en: ['one', 'other'],
    hy: ['one', 'other'],
    ru: ['one', 'few', 'many', 'other'],
};

const loaders = import.meta.glob<{ default: Record<string, string> }>('../../../lang/{ru,hy}.json');

let locale: Locale = 'en';
let dictionary: Record<string, string> = {};

export const isLocale = (value: unknown): value is Locale => typeof value === 'string' && (LOCALES as readonly string[]).includes(value);

export async function loadLocale(requested: unknown): Promise<void> {
    const next: Locale = isLocale(requested) ? requested : 'en';
    const load = loaders[`../../../lang/${next}.json`];
    setDictionary(next, load ? (await load()).default : {});
}

/** Install a dictionary directly (boot code and tests). */
export function setDictionary(next: Locale, entries: Record<string, string>): void {
    locale = next;
    dictionary = entries;
    if (typeof document !== 'undefined') document.documentElement.lang = INTL_LOCALES[next].split('-')[0];
}

export const currentLocale = (): Locale => locale;

/** The BCP 47 tag for Intl formatting (dates, numbers, plural rules). */
export const intlLocale = (): string => INTL_LOCALES[locale];

type Replacements = Record<string, string | number | null | undefined>;

function replace(text: string, replacements: Replacements = {}): string {
    return text.replace(/:([A-Za-z_]\w*)/g, (match, name: string) => (name in replacements ? String(replacements[name] ?? '') : match));
}

export function t(key: string, replacements?: Replacements): string {
    return replace(dictionary[key] || key, replacements);
}

export function tc(key: string, count: number, replacements: Replacements = {}): string {
    const forms = (dictionary[key] || key).split('|');
    const category = new Intl.PluralRules(intlLocale()).select(count);
    const index = PLURAL_ORDER[locale].indexOf(category);
    const form = forms[Math.min(index === -1 ? forms.length - 1 : index, forms.length - 1)];

    return replace(form, { count: count.toLocaleString(intlLocale()), ...replacements });
}

/**
 * Translate a whole sentence whose placeholders are React nodes (a bold name, a link), so translators
 * see the full sentence and can move the placeholder where their grammar needs it.
 */
export function tn(key: string, nodes: Record<string, ReactNode>): ReactNode {
    const parts = (dictionary[key] || key).split(/(:[A-Za-z_]\w*)/);

    return createElement(Fragment, null, ...parts.map((part, index) => {
        const name = part.startsWith(':') ? part.slice(1) : null;
        return createElement(Fragment, { key: index }, name !== null && name in nodes ? nodes[name] : part);
    }));
}

/** Marks text for translation without translating it yet (module-level constants). */
export const tk = (key: string): string => key;
