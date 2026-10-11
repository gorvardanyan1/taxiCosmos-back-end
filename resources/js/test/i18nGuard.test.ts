import fs from 'node:fs';
import path from 'node:path';
import { describe, expect, it } from 'vitest';
import { scanFile, scanText, sourceFiles, SOURCE_ROOT, usedKeys } from '@/test/i18nScan';

const dictionary = (locale: string): Record<string, string> => JSON.parse(fs.readFileSync(path.resolve(SOURCE_ROOT, `../../lang/${locale}.json`), 'utf8'));
const placeholders = (text: string): string[] => [...new Set([...text.matchAll(/:([A-Za-z_]\w*)/g)].map((m) => m[1].toLowerCase()))].sort();

/** The strings the server translates (PHP), which share the same dictionary files. */
function serverKeys(): Set<string> {
    const keys = new Set<string>();
    const walk = (dir: string) => {
        for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
            const full = path.join(dir, entry.name);
            if (entry.isDirectory()) walk(full);
            else if (entry.name.endsWith('.php')) {
                for (const m of fs.readFileSync(full, 'utf8').matchAll(/\b(?:__|trans_choice|trans)\(\s*(?:'((?:[^'\\]|\\.)*)'|"((?:[^"\\]|\\.)*)")/g)) {
                    const key = (m[1] ?? m[2]).replace(/\\'/g, "'");
                    if (!/^[a-z_]+\.[a-z_.]+$/.test(key)) keys.add(key);
                }
            }
        }
    };
    walk(path.resolve(SOURCE_ROOT, '../../app'));
    return keys;
}

describe('no hardcoded user-visible strings in the admin UI (P13-T11)', () => {
    it('finds none in any component, page, layout or helper', () => {
        const findings = sourceFiles().flatMap(scanFile).map((f) => `${f.file}:${f.line} [${f.rule}] ${f.text}`);

        expect(findings, 'Wrap visible text in t() / tc() / tn(), or mark module-level constants with tk()').toEqual([]);
    });

    it('scans every source file (the guard cannot silently see nothing)', () => {
        const files = sourceFiles().map((f) => path.relative(SOURCE_ROOT, f));

        expect(files.length).toBeGreaterThan(50);
        expect(files).toEqual(expect.arrayContaining(['Pages/Riders/Index.tsx', 'Components/Sidebar.tsx', 'lib/labels.ts', 'lib/navigation.tsx', 'Layouts/AdminLayout.tsx']));
    });
});

describe('the scanner itself catches what it should', () => {
    const scan = (code: string) => scanText('Probe.tsx', `import { t } from '@/lib/i18n';\nexport default function P() { ${code} }`);

    it('flags JSX text, visible attributes, ternaries, templates and prose in lookups', () => {
        expect(scan('return <p>Hello there</p>;').map((f) => f.rule)).toEqual(['jsx-text']);
        expect(scan('return <input placeholder="Search here" />;').map((f) => f.rule)).toEqual(['jsx-attribute']);
        expect(scan('return <button title={ok ? "Save it" : "Cancel it"} />;')).toHaveLength(2);
        expect(scan('return <p>{ok ? "Yes please" : t("No")}</p>;').map((f) => f.text)).toEqual(['Yes please']);
        expect(scan('return <p>{`Total ${n} items`}</p>;')).toHaveLength(1);
        expect(scan("const names = { a: 'Some visible name' }; return null;").map((f) => f.rule)).toEqual(['prose-literal']);
        expect(scan("return <Panel title='Fare rules' />;")).toHaveLength(1);
        expect(scan('return <p>Привет</p>;')).toHaveLength(1);
    });

    it('accepts translated text, identifiers, classes, symbols and brand names', () => {
        expect(scan("return <p className=\"mt-2 text-sm\">{t('Hello')}</p>;")).toEqual([]);
        expect(scan("return <a href=\"/admin/riders\" key={id} aria-label={t('Close')}>{n} · {x}</a>;")).toEqual([]);
        expect(scan("const status = row.status === 'completed' ? 'in_progress' : 'ok'; return <p>{status}</p>;")).toEqual([]);
        expect(scan('return <p>TaxiKosmos · AMD · ⌘K · 2FA</p>;')).toEqual([]);
        expect(scan("return <p>{money(5)}</p>;")).toEqual([]);
    });

    it('flags a server enum value printed as it is instead of through label()', () => {
        expect(scan('return <span>{zone.status}</span>;').map((f) => f.rule)).toEqual(['raw-enum']);
        expect(scan('return <p>{ticket.priority}</p>;').map((f) => f.rule)).toEqual(['raw-enum']);
        expect(scan('return <span>{label(zone.status)}</span>;')).toEqual([]);
        expect(scan('return <StatusBadge status={zone.status} />;')).toEqual([]);
        expect(scan('return <span className={zone.status === "active" ? "a" : "b"}>{t("x")}</span>;')).toEqual([]);
    });

    it('refuses t() at module level, where it would freeze English before the dictionary loads', () => {
        const findings = scanText('Probe.ts', "import { t } from '@/lib/i18n';\nexport const TITLE = t('Riders');");

        expect(findings.map((f) => f.rule)).toEqual(['module-level-t']);
        expect(scanText('Probe.ts', "import { tk } from '@/lib/i18n';\nexport const TITLE = tk('Riders');")).toEqual([]);
    });

    it('does not flag a string that is already inside a translation call', () => {
        expect(scan("return <p>{tc(':count result|:count results', n)}</p>;")).toEqual([]);
        expect(scan("return <p>{tn('Hello :name', { name: <b>x</b> })}</p>;")).toEqual([]);
    });
});

describe('translations cover everything the UI uses', () => {
    const used = [...usedKeys().entries()].filter(([key]) => !key.startsWith('<non-literal'));

    it('finds the keys (the guard cannot silently see nothing)', () => {
        expect(used.length).toBeGreaterThan(500);
    });

    it('only calls t() with a variable key at the known lookups, whose values are all declared with tk()', () => {
        const dynamic = [...usedKeys().entries()]
            .filter(([key]) => key.startsWith('<non-literal'))
            .map(([key, where]) => `${where.split(':')[0]} ${key.replace('<non-literal key> ', '')}`)
            .sort();

        expect(dynamic).toEqual([
            'Components/Sidebar.tsx group.label',
            'Components/SettingsTabs.tsx tab.label',
            'Components/StatusBadge.tsx labels[status]',
            'Components/UrlTabs.tsx labels[tab]',
            'Pages/Dashboard.tsx alert.label',
            'Pages/Dashboard.tsx style.title',
            'Pages/Errors/Show.tsx state.message',
            'Pages/Errors/Show.tsx state.title',
            'Pages/Reports/Index.tsx metricLabels[m]',
            'Pages/Reports/Index.tsx metricLabels[metric]',
            'Pages/Trips/Show.tsx step.desc',
            'Pages/Trips/Show.tsx step.label',
            'Pages/Zones/Index.tsx UNAVAILABLE_HINT',
            'lib/labels.ts LABELS[value]',
            'lib/navigation.tsx extra.label',
            'lib/navigation.tsx item.label',
        ].sort());
    });

    for (const locale of ['ru', 'hy']) {
        it(`${locale}: every key the UI uses has a translation`, () => {
            const dict = dictionary(locale);
            const missing = used.filter(([key]) => !(key in dict)).map(([key, where]) => `${where} ${JSON.stringify(key)}`);

            expect(missing).toEqual([]);
        });

        it(`${locale}: no dictionary entry is unused by the UI and the server`, () => {
            const known = new Set([...used.map(([key]) => key), ...serverKeys()]);
            const unused = Object.keys(dictionary(locale)).filter((key) => !known.has(key));

            expect(unused).toEqual([]);
        });

        it(`${locale}: placeholders match the English text and plurals have the right number of forms`, () => {
            const forms = locale === 'ru' ? 3 : 2;

            for (const [key, value] of Object.entries(dictionary(locale))) {
                expect(placeholders(value), key).toEqual(placeholders(key));
                if (key.includes('|')) expect(value.split('|'), key).toHaveLength(forms);
                else expect(value, key).not.toContain('|');
            }
        });
    }

    it('uses the same plural keys for every count call (:count with a | pair)', () => {
        for (const [key] of used) {
            if (key.includes(':count')) expect(key.includes('|') || /^:count /.test(key), key).toBe(true);
        }
    });
});
