import '../css/app.css';
import { createInertiaApp } from '@inertiajs/react';
import type { ComponentType, ReactNode } from 'react';
import { createRoot } from 'react-dom/client';
import AdminLayout from '@/Layouts/AdminLayout';

type PageModule = { default: ComponentType & { layout?: ((page: ReactNode) => ReactNode) | null } };

createInertiaApp({
    title: (title) => (title ? `${title} · TaxiKosmos Admin` : 'TaxiKosmos Admin'),
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
});
