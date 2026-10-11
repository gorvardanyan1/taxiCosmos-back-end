import '../css/app.css';
import { createInertiaApp } from '@inertiajs/react';
import type { ComponentType, ReactNode } from 'react';
import { createRoot } from 'react-dom/client';
import AdminLayout from '@/Layouts/AdminLayout';
import { loadLocale, t } from '@/lib/i18n';

type PageModule = { default: ComponentType & { layout?: ((page: ReactNode) => ReactNode) | null } };

// The language chosen on the server (admin preference, sign-in choice or browser) is in the first
// page's props; its dictionary loads before anything renders, so every t() call is translated.
const firstPage = JSON.parse(document.getElementById('app')?.dataset.page ?? '{}') as { props?: { app?: { locale?: string }; locale?: string } };

loadLocale(firstPage.props?.app?.locale ?? firstPage.props?.locale).then(() => createInertiaApp({
    title: (title) => (title ? `${title} · TaxiKosmos Admin` : t('TaxiKosmos Admin')),
    resolve: async (name) => {
        // Lazy: each page (and heavy libs like recharts) loads in its own chunk.
        const pages = import.meta.glob<PageModule>('./Pages/**/*.tsx');
        const load = pages[`./Pages/${name}.tsx`];
        if (!load) throw new Error(`Unknown Inertia page: ${name}`);
        const page = await load();

        // Admin pages share the persistent AdminLayout; auth and error pages bring their own shell.
        if (page.default.layout === undefined && !name.startsWith('Auth/') && !name.startsWith('Errors/')) {
            page.default.layout = (content: ReactNode) => <AdminLayout>{content}</AdminLayout>;
        }

        return page;
    },
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
    progress: { color: '#6366f1' },
}));
