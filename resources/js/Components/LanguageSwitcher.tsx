import { usePage } from '@inertiajs/react';
import { currentLocale, isLocale, LOCALE_NAMES, t } from '@/lib/i18n';

/**
 * Language links for the sign-in screens (before an admin has a saved preference). Each is a plain
 * link with ?lang=: the server remembers the choice for the session and the page reloads in it.
 */
export default function LanguageSwitcher() {
    const { props } = usePage();
    const locales = (props.app?.locales ?? []).filter(isLocale);

    return (
        <nav aria-label={t('Language')} className="mt-6 flex justify-center gap-3 text-xs">
            {locales.map((code) => (
                <a
                    key={code}
                    href={`?lang=${code}`}
                    lang={code}
                    aria-current={code === currentLocale() ? 'true' : undefined}
                    className={code === currentLocale() ? 'font-bold text-indigo-600' : 'text-slate-400 hover:text-slate-600'}
                >
                    {LOCALE_NAMES[code]}
                </a>
            ))}
        </nav>
    );
}
