import { render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import PaginatedTable from '@/Components/PaginatedTable';
import Sidebar from '@/Components/Sidebar';
import StatusBadge from '@/Components/StatusBadge';
import UrlTabs from '@/Components/UrlTabs';
import ErrorShow from '@/Pages/Errors/Show';
import RidersIndex from '@/Pages/Riders/Index';
import { loadLocale, setDictionary } from '@/lib/i18n';
import { label } from '@/lib/labels';
import { pageLabel } from '@/lib/navigation';
import { PERMISSIONS } from '@/lib/permissions';
import { page, setLocation, sharedProps } from '@/test/inertia';
import type { Paginated, RiderRow } from '@/types';

vi.mock('@inertiajs/react', async (importOriginal) => (await import('@/test/inertia')).inertiaMock(importOriginal));

const rider: RiderRow = { id: 7, code: 'R-00007', name: 'Sun Li', phone: '+37491210443', email: 'sun@example.com', status: 'suspended', trips_count: 3, registered_at: '2026-01-12T10:00:00+00:00' };
const paginator = (data: RiderRow[], total = data.length): Paginated<RiderRow> => ({ data, current_page: 1, last_page: 1, per_page: 10, total, from: data.length ? 1 : null, to: data.length || null, path: '/admin/riders', links: [] });
const base = { filters: {}, sort: null, statuses: ['active', 'suspended'], totalRegistered: 21, actions: { export: null, suspend: '/s/{id}', reactivate: '/r/{id}' } };

describe.each([
    ['ru', { title: 'Пассажиры', description: '21 зарегистрированный аккаунт', result: '1 результат', status: 'Заблокирован', all: 'Все статусы', empty: 'Пассажиры не найдены', rider: 'Пассажир', registered: 'Регистрация' }],
    ['hy', { title: 'Ուղևորներ', description: '21 գրանցված հաշիվ', result: '1 արդյունք', status: 'Արգելափակված', all: 'Բոլոր կարգավիճակները', empty: 'Ուղևորներ չեն գտնվել', rider: 'Ուղևոր', registered: 'Գրանցվել է' }],
] as const)('the Riders page in %s', (locale, text) => {
    beforeEach(async () => {
        await loadLocale(locale);
        page.props = sharedProps({ app: { ...sharedProps().app, locale } });
        setLocation('/admin/riders');
    });
    afterEach(() => setDictionary('en', {}));

    it('has no English left in the header, filters, columns, row status and result count', () => {
        render(<RidersIndex {...base} riders={paginator([rider])} />);

        expect(screen.getByRole('heading', { name: text.title })).toBeInTheDocument();
        expect(screen.getByText(text.description)).toBeInTheDocument();
        expect(screen.getByText(text.result)).toBeInTheDocument();
        expect(screen.getByLabelText(text.all)).toBeInTheDocument();
        expect(within(screen.getByRole('table')).getByText(text.status)).toBeInTheDocument();
        expect(screen.getByRole('columnheader', { name: new RegExp(text.registered, 'i') })).toBeInTheDocument();
        for (const english of ['Riders', 'Suspended', 'All Statuses', 'Registered', 'registered accounts']) {
            expect(document.body.textContent).not.toContain(english);
        }
    });

    it('translates the empty state', () => {
        render(<RidersIndex {...base} riders={paginator([])} />);

        expect(screen.getByText(text.empty)).toBeInTheDocument();
    });
});

describe('shared components in Russian and Armenian', () => {
    afterEach(() => setDictionary('en', {}));

    it('writes the pagination range as one sentence in the language', async () => {
        await loadLocale('ru');
        render(<PaginatedTable paginator={{ ...paginator([rider], 21), from: 1, to: 10 }} rowKey={(r) => r.id} columns={[{ key: 'n', header: 'x', render: (r) => r.name }]} />);
        expect(screen.getByText(/Показано/).textContent).toBe('Показано 1–10 из 21');

        await loadLocale('hy');
        render(<PaginatedTable paginator={{ ...paginator([rider], 21), from: 1, to: 10 }} rowKey={(r) => r.id} columns={[{ key: 'n', header: 'x', render: (r) => r.name }]} />);
        expect(screen.getAllByText(/21/).some((el) => el.textContent === 'Ցուցադրվում է 1–10՝ 21-ից')).toBe(true);
    });

    it('translates status badges, including values with their own label', async () => {
        await loadLocale('ru');
        render(<><StatusBadge status="in_progress" /><StatusBadge status="no_driver_found" /><StatusBadge status="active" label="Включено" /></>);

        expect(screen.getByText('В процессе')).toBeInTheDocument();
        expect(screen.getByText('Водитель не найден')).toBeInTheDocument();
        expect(screen.getByText('Включено')).toBeInTheDocument();
    });

    it('translates tabs from labels and from enum names', async () => {
        await loadLocale('ru');
        render(<UrlTabs tabs={['trips', 'payment-methods', 'trip-history']} active="trips" labels={{ 'trip-history': 'Trip History' }} />);

        expect(screen.getAllByRole('tab').map((tab) => tab.textContent)).toEqual(['Поездки', 'Способы оплаты', 'История поездок']);
    });

    it('translates the sidebar groups and items and the page title in the top bar', async () => {
        await loadLocale('hy');
        page.props = sharedProps({ auth: { user: { id: 1, name: 'Sky', email: 's@x.test', role: 'super_admin', roleLabel: 'Super admin' }, permissions: [...PERMISSIONS] } });
        render(<Sidebar collapsed={false} />);

        const nav = screen.getByRole('navigation', { name: 'Գլխավոր' });
        expect(within(nav).getByText('Ուղևորներ')).toBeInTheDocument();
        expect(within(nav).getByText('Գործառնություններ')).toBeInTheDocument();
        expect(within(nav).queryByText('Riders')).toBeNull();
        expect(pageLabel('/admin/zones')).toBe('Գոտիներ և սակագներ');
        expect(pageLabel('/admin/account')).toBe('Իմ հաշիվը');
    });

    it('translates the error pages', async () => {
        await loadLocale('ru');
        render(<ErrorShow status={403} />);

        expect(screen.getByRole('heading', { name: 'У вас нет доступа' })).toBeInTheDocument();
        expect(screen.getByText('Попросите администратора выдать нужное разрешение.')).toBeInTheDocument();
    });

    it('translates enum labels and keeps unknown values readable', async () => {
        await loadLocale('hy');
        expect(label('suspended')).toBe('Արգելափակված');
        expect(label('vehicle_registration')).toBe('Մեքենայի գրանցման վկայական');
        expect(label('some_new_status')).toBe('Some new status');
        expect(label(null)).toBe('—');
    });
});
