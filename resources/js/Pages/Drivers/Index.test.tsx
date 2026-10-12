import { router } from '@inertiajs/core';
import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import DriversIndex from '@/Pages/Drivers/Index';
import { page, setLocation, sharedProps } from '@/test/inertia';
import type { DriverRow, Paginated } from '@/types';

vi.mock('@inertiajs/react', async (importOriginal) => (await import('@/test/inertia')).inertiaMock(importOriginal));

const driver = (overrides: Partial<DriverRow>): DriverRow => ({
    id: 7, code: 'D-0007', name: 'Gor Hakobyan', phone: '+37491440221', vehicle: { label: 'Toyota Camry', year: 2020 }, verification_status: 'approved', availability: 'online', status: 'active',
    rating: '4.50', trips_count: 12, earnings: { amount: 200000, currency: 'AMD' }, ...overrides,
});
const paginator = (data: DriverRow[]): Paginated<DriverRow> => ({ data, current_page: 1, last_page: 1, per_page: 10, total: data.length, from: 1, to: data.length, path: '/admin/drivers', links: [] });
const actions = { export: null, create: null, suspend: '/admin/drivers/{id}/suspend', reactivate: '/admin/drivers/{id}/reactivate' };
const base = {
    filters: {}, sort: null, verificationStatuses: ['pending', 'approved', 'rejected', 'expired'], availabilities: ['offline', 'online', 'on_trip'],
    vehicleClasses: ['economy' as const, 'comfort' as const, 'business' as const], zones: [{ id: 3, name: 'Yerevan' }, { id: 4, name: 'Gyumri' }], totalRegistered: 2, actions,
};

describe('Drivers list', () => {
    let get: ReturnType<typeof vi.spyOn>;
    let visit: ReturnType<typeof vi.spyOn>;
    beforeEach(() => { page.props = sharedProps(); get = vi.spyOn(router, 'get').mockImplementation(() => {}); visit = vi.spyOn(router, 'visit').mockImplementation(() => {}); setLocation('/admin/drivers'); });
    afterEach(() => vi.restoreAllMocks());

    it('shows each driver with vehicle, verification, availability, rating, trips and earnings from minor units', () => {
        render(<DriversIndex {...base} drivers={paginator([driver({}), driver({ id: 8, code: 'D-0008', name: 'Ana Petrosyan', vehicle: null, availability: 'offline', verification_status: 'pending', rating: null, trips_count: 0, earnings: { amount: 0, currency: 'AMD' } })])} />);

        const gor = screen.getByText('Gor Hakobyan').closest('tr')!;
        expect(gor).toHaveTextContent('D-0007');
        expect(gor).toHaveTextContent('Toyota Camry ’20');
        expect(gor).toHaveTextContent('Approved');
        expect(gor).toHaveTextContent('Online');
        expect(gor).toHaveTextContent('AMD 200,000');
        const ana = screen.getByText('Ana Petrosyan').closest('tr')!;
        expect(ana).toHaveTextContent('—');
        expect(ana).toHaveTextContent('Pending');
        expect(screen.getByText('2 drivers')).toBeInTheDocument();
    });

    it('marks a suspended driver next to the verification status', () => {
        render(<DriversIndex {...base} drivers={paginator([driver({ status: 'suspended' })])} />);

        expect(screen.getByText('Gor Hakobyan').closest('tr')).toHaveTextContent('Suspended');
    });

    it('filters by every extra filter through the URL', () => {
        render(<DriversIndex {...base} drivers={paginator([driver({})])} />);
        const filter = (name: RegExp | string, value: string, query: string) => {
            fireEvent.change(screen.getByLabelText(name), { target: { value } });
            expect(get).toHaveBeenLastCalledWith(`/admin/drivers?${query}`, {}, { preserveState: true, preserveScroll: true });
        };

        filter('All Statuses', 'approved', 'filter%5Bverification_status%5D=approved');
        filter('Any availability', 'on_trip', 'filter%5Bavailability%5D=on_trip');
        filter('All zones', '3', 'filter%5Bzone%5D=3');
        filter('All vehicle classes', 'comfort', 'filter%5Bvehicle_class%5D=comfort');
        filter('Documents expiring', '30', 'filter%5Bdocuments_expiring%5D=30');
    });

    it('offers zones, vehicle classes and expiry windows as choices', () => {
        render(<DriversIndex {...base} drivers={paginator([driver({})])} />);

        expect([...screen.getByLabelText('All zones').querySelectorAll('option')].map((o) => o.textContent)).toEqual(['All zones', 'Yerevan', 'Gyumri']);
        expect([...screen.getByLabelText('All vehicle classes').querySelectorAll('option')].map((o) => o.textContent)).toEqual(['All vehicle classes', 'Economy', 'Comfort', 'Business']);
        expect([...screen.getByLabelText('Documents expiring').querySelectorAll('option')].map((o) => o.textContent)).toEqual([
            'Any document expiry', 'Documents expiring within 7 days', 'Documents expiring within 14 days', 'Documents expiring within 30 days', 'Documents expiring within 60 days', 'Documents expiring within 90 days',
        ]);
    });

    it('shows the active filters and sorts by clicking a header', () => {
        render(<DriversIndex {...base} filters={{ availability: 'online', documents_expiring: '14' }} drivers={paginator([driver({})])} />);

        expect(screen.getByLabelText('Any availability')).toHaveValue('online');
        expect(screen.getByLabelText('Documents expiring')).toHaveValue('14');
        fireEvent.click(screen.getByRole('button', { name: 'Trips' }));
        expect(get).toHaveBeenLastCalledWith(expect.stringContaining('sort=trips_count'), {}, expect.anything());
        fireEvent.click(screen.getByRole('button', { name: 'Rating' }));
        expect(get).toHaveBeenLastCalledWith(expect.stringContaining('sort=rating'), {}, expect.anything());
    });

    it('suspends from the row menu with a required reason', () => {
        render(<DriversIndex {...base} drivers={paginator([driver({})])} />);

        fireEvent.click(screen.getByLabelText('Actions for Gor Hakobyan'));
        fireEvent.click(screen.getByRole('button', { name: /Suspend/ }));
        const confirm = screen.getByRole('button', { name: 'Confirm Suspension' });
        expect(confirm).toBeDisabled();
        expect(screen.getByText(/A driver on a trip cannot be suspended/)).toBeInTheDocument();
        fireEvent.change(screen.getByLabelText(/Reason/), { target: { value: 'Repeated complaints' } });
        fireEvent.click(confirm);

        expect(visit.mock.calls[0][0]).toBe('/admin/drivers/7/suspend');
        expect(visit.mock.calls[0][1]).toMatchObject({ method: 'post', data: { reason: 'Repeated complaints' } });
    });

    it('reactivates a suspended driver through the same menu', () => {
        render(<DriversIndex {...base} drivers={paginator([driver({ id: 8, name: 'Ana', status: 'suspended' })])} />);

        fireEvent.click(screen.getByLabelText('Actions for Ana'));
        fireEvent.click(screen.getByRole('button', { name: /Reactivate/ }));
        expect(screen.getByRole('dialog', { name: 'Reactivate Ana' })).toBeInTheDocument();
        fireEvent.change(screen.getByLabelText(/Reason/), { target: { value: 'Appeal accepted' } });
        fireEvent.click(screen.getByRole('button', { name: 'Confirm Reactivation' }));

        expect(visit.mock.calls[0][0]).toBe('/admin/drivers/8/reactivate');
    });

    it('offers no suspend or reactivate without the permission, or for accounts that are neither active nor suspended', () => {
        const { unmount } = render(<DriversIndex {...base} actions={{ ...actions, suspend: null, reactivate: null }} drivers={paginator([driver({})])} />);
        fireEvent.click(screen.getByLabelText('Actions for Gor Hakobyan'));
        expect(screen.getByRole('button', { name: /View Detail/ })).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /Suspend|Reactivate/ })).toBeNull();
        unmount();

        render(<DriversIndex {...base} drivers={paginator([driver({ status: 'deactivated' })])} />);
        fireEvent.click(screen.getByLabelText('Actions for Gor Hakobyan'));
        expect(screen.queryByRole('button', { name: /Suspend|Reactivate/ })).toBeNull();
    });

    it('says when no driver matches', () => {
        render(<DriversIndex {...base} drivers={paginator([])} />);

        expect(screen.getByText('No drivers found')).toBeInTheDocument();
    });
});
