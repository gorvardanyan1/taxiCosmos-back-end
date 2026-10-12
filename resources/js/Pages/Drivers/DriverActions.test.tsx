import { router } from '@inertiajs/core';
import { act, fireEvent, render, screen, within } from '@testing-library/react';
import type { ComponentProps } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import DriverShow from '@/Pages/Drivers/Show';
import { page, sharedProps } from '@/test/inertia';
import type { DriverDetail, Vehicle } from '@/types';

vi.mock('@inertiajs/react', async (importOriginal) => (await import('@/test/inertia')).inertiaMock(importOriginal));

const amd = (amount: number) => ({ amount, currency: 'AMD' });
const vehicle = (overrides: Partial<Vehicle>): Vehicle => ({ id: 31, make: 'Toyota', model: 'Camry', year: 2020, color: 'White', plate_number: '11AA111', vehicle_class: 'comfort', status: 'active', is_primary: true, ...overrides });
const driver = (overrides: Partial<DriverDetail> = {}): DriverDetail => ({
    id: 9, code: 'D-0009', name: 'Gor Hakobyan', phone: '+37491440221', vehicle: { label: 'Toyota Camry', year: 2020 }, verification_status: 'approved', availability: 'online', status: 'active',
    rating: null, trips_count: 2, earnings: amd(300000), raw: { name: 'Gor Hakobyan', email: 'gor@example.com', phone: '+37491440221', license_expiry: '2028-05-01', home_zone_id: 3 },
    license_last4: '4242', home_zone: 'Yerevan', suspension_reason: null, documents: [], vehicles: [vehicle({}), vehicle({ id: 32, make: 'Kia', model: 'Forte', plate_number: '22BB222', is_primary: false }), vehicle({ id: 33, make: 'Skoda', model: 'Octavia', plate_number: '33CC333', is_primary: false, status: 'pending_review' })],
    trip_history: [], bank_accounts: [{ id: 5, bank_name: 'Ameriabank', account_last4: '4821', is_default: true }],
    earnings_detail: { balance: amd(300000), debt_limit_exceeded: false, summary: { week: amd(100000), month: amd(300000), all_time: amd(300000) }, ledger: [{ id: 1, type: 'trip_earning', reference: 'TK-AAAA1111', amount: amd(120000), occurred_at: '2026-10-10T10:00:00+00:00' }], ledger_pending: true },
    ...overrides,
});

const tabs = ['profile', 'documents', 'vehicles', 'earnings', 'trip-history', 'bank-accounts'];
type Actions = ComponentProps<typeof DriverShow>['actions'];
const allActions: Actions = {
    approveDocument: null, rejectDocument: null, edit: '/admin/drivers/9', suspend: '/admin/drivers/9/suspend', reactivate: '/admin/drivers/9/reactivate',
    addVehicle: '/admin/drivers/9/vehicles', updateVehicle: '/admin/drivers/9/vehicles/{id}', setPrimaryVehicle: '/admin/drivers/9/vehicles/{id}/set-primary',
    addAdjustment: null, recordCashSettlement: null, revealBankAccount: '/admin/drivers/9/bank-accounts/{id}/reveal',
};
const zones = [{ id: 3, name: 'Yerevan' }, { id: 4, name: 'Gyumri' }];

function renderShow(tab: string, overrides: Partial<DriverDetail> = {}, actions = allActions) {
    return render(<DriverShow driver={driver(overrides)} tab={tab} tabs={tabs} zones={zones} vehicleClasses={['economy', 'comfort', 'business']} actions={actions} />);
}

describe('Driver detail actions', () => {
    let visit: ReturnType<typeof vi.spyOn>;
    beforeEach(() => { page.props = sharedProps(); visit = vi.spyOn(router, 'visit').mockImplementation(() => {}); });
    afterEach(() => vi.restoreAllMocks());

    describe('edit', () => {
        it('starts from the current values, never shows the licence number, and sends only what changed with the reason', () => {
            renderShow('profile');

            fireEvent.click(screen.getByRole('button', { name: 'Edit' }));
            const dialog = screen.getByRole('dialog', { name: 'Edit Gor Hakobyan' });
            expect(within(dialog).getByLabelText('Name')).toHaveValue('Gor Hakobyan');
            expect(within(dialog).getByLabelText('Phone')).toHaveValue('+37491440221');
            expect(within(dialog).getByLabelText('License number')).toHaveValue('');
            expect(within(dialog).getByLabelText('License number')).toHaveAttribute('placeholder', 'Current: •••• 4242 (leave empty to keep it)');
            expect(within(dialog).getByLabelText('License expiry')).toHaveValue('2028-05-01');
            expect(within(dialog).getByLabelText('Home zone')).toHaveValue('3');
            const save = within(dialog).getByRole('button', { name: 'Save changes' });
            expect(save).toBeDisabled();

            fireEvent.change(within(dialog).getByLabelText('Name'), { target: { value: 'Gor H.' } });
            fireEvent.change(within(dialog).getByLabelText('License number'), { target: { value: 'AM-DL 5555' } });
            fireEvent.change(within(dialog).getByLabelText('Home zone'), { target: { value: '4' } });
            expect(save).toBeDisabled();
            fireEvent.change(within(dialog).getByLabelText(/Reason/), { target: { value: 'Driver renewed their licence' } });
            fireEvent.click(save);

            expect(visit).toHaveBeenCalledTimes(1);
            expect(visit.mock.calls[0][0]).toBe('/admin/drivers/9');
            expect(visit.mock.calls[0][1]).toMatchObject({ method: 'patch', data: { name: 'Gor H.', license_number: 'AM-DL 5555', home_zone_id: 4, reason: 'Driver renewed their licence' } });
            expect(Object.keys((visit.mock.calls[0][1] as { data: object }).data).sort()).toEqual(['home_zone_id', 'license_number', 'name', 'reason']);
        });

        it('sends null to clear the email and the home zone', () => {
            renderShow('profile');

            fireEvent.click(screen.getByRole('button', { name: 'Edit' }));
            const dialog = screen.getByRole('dialog');
            fireEvent.change(within(dialog).getByLabelText('Email'), { target: { value: '' } });
            fireEvent.change(within(dialog).getByLabelText('Home zone'), { target: { value: '' } });
            fireEvent.change(within(dialog).getByLabelText(/Reason/), { target: { value: 'Left out' } });
            fireEvent.click(within(dialog).getByRole('button', { name: 'Save changes' }));

            expect(visit.mock.calls[0][1]).toMatchObject({ data: { email: null, home_zone_id: null, reason: 'Left out' } });
        });

        it('shows the server\'s error under the field it belongs to', () => {
            renderShow('profile');
            fireEvent.click(screen.getByRole('button', { name: 'Edit' }));
            const dialog = screen.getByRole('dialog');
            fireEvent.change(within(dialog).getByLabelText('Phone'), { target: { value: '+37499000111' } });
            fireEvent.change(within(dialog).getByLabelText(/Reason/), { target: { value: 'Fix' } });
            fireEvent.click(within(dialog).getByRole('button', { name: 'Save changes' }));

            act(() => (visit.mock.calls[0][1] as { onError: (errors: Record<string, string>) => void }).onError({ phone: 'Another account already uses this phone number.' }));

            expect(within(dialog).getByLabelText('Phone').closest('div')).toHaveTextContent('Another account already uses this phone number.');
        });

        it('is disabled without the permission', () => {
            renderShow('profile', {}, { ...allActions, edit: null });

            expect(screen.getByRole('button', { name: 'Edit' })).toBeDisabled();
        });
    });

    describe('suspend and reactivate', () => {
        it('suspends with a required reason', () => {
            renderShow('profile');

            fireEvent.click(screen.getByRole('button', { name: 'Suspend' }));
            const confirm = screen.getByRole('button', { name: 'Confirm Suspension' });
            expect(confirm).toBeDisabled();
            fireEvent.change(screen.getByLabelText(/Reason/), { target: { value: 'Repeated complaints' } });
            fireEvent.click(confirm);

            expect(visit.mock.calls[0][0]).toBe('/admin/drivers/9/suspend');
            expect(visit.mock.calls[0][1]).toMatchObject({ method: 'post', data: { reason: 'Repeated complaints' } });
        });

        it('shows why a suspended driver is suspended and offers reactivation', () => {
            renderShow('profile', { status: 'suspended', suspension_reason: 'Repeated complaints' });

            expect(screen.getByText('Suspended: Repeated complaints')).toBeInTheDocument();
            fireEvent.click(screen.getByRole('button', { name: 'Reactivate' }));
            fireEvent.change(screen.getByLabelText(/Reason/), { target: { value: 'Appeal accepted' } });
            fireEvent.click(screen.getByRole('button', { name: 'Confirm Reactivation' }));

            expect(visit.mock.calls[0][0]).toBe('/admin/drivers/9/reactivate');
        });

        it('offers neither without the permission or for an account that is neither active nor suspended', () => {
            const { unmount } = renderShow('profile', {}, { ...allActions, suspend: null, reactivate: null });
            expect(screen.queryByRole('button', { name: /^(Suspend|Reactivate)$/ })).toBeNull();
            unmount();

            renderShow('profile', { status: 'deactivated' });
            expect(screen.queryByRole('button', { name: /^(Suspend|Reactivate)$/ })).toBeNull();
        });
    });

    describe('vehicles', () => {
        it('adds a vehicle with every field and the year as a number', () => {
            renderShow('vehicles');

            fireEvent.click(screen.getByRole('button', { name: '+ Add vehicle' }));
            const dialog = screen.getByRole('dialog', { name: 'Add vehicle' });
            fireEvent.change(within(dialog).getByLabelText(/^Make/), { target: { value: 'Kia' } });
            fireEvent.change(within(dialog).getByLabelText(/^Model/), { target: { value: 'Forte' } });
            fireEvent.change(within(dialog).getByLabelText(/^Year/), { target: { value: '2022' } });
            fireEvent.change(within(dialog).getByLabelText(/^Color/), { target: { value: 'Blue' } });
            fireEvent.change(within(dialog).getByLabelText(/^Plate number/), { target: { value: '44 dd 444' } });
            fireEvent.change(within(dialog).getByLabelText(/^Vehicle class/), { target: { value: 'business' } });
            fireEvent.click(within(dialog).getByRole('button', { name: 'Add vehicle' }));

            expect(visit.mock.calls[0][0]).toBe('/admin/drivers/9/vehicles');
            expect(visit.mock.calls[0][1]).toMatchObject({ method: 'post', data: { make: 'Kia', model: 'Forte', year: 2022, color: 'Blue', plate_number: '44 DD 444', vehicle_class: 'business', status: 'active' } });
        });

        it('edits a vehicle: starts from its values and sends only what changed to that vehicle\'s URL', () => {
            renderShow('vehicles');

            fireEvent.click(screen.getByRole('button', { name: 'Edit Toyota Camry' }));
            const dialog = screen.getByRole('dialog', { name: 'Edit Toyota Camry' });
            expect(within(dialog).getByLabelText(/^Plate number/)).toHaveValue('11AA111');
            expect(within(dialog).getByLabelText(/^Year/)).toHaveValue(2020);
            fireEvent.change(within(dialog).getByLabelText(/^Color/), { target: { value: 'Red' } });
            fireEvent.change(within(dialog).getByLabelText(/^Status/), { target: { value: 'inactive' } });
            fireEvent.click(within(dialog).getByRole('button', { name: 'Save changes' }));

            expect(visit.mock.calls[0][0]).toBe('/admin/drivers/9/vehicles/31');
            expect(visit.mock.calls[0][1]).toMatchObject({ method: 'patch', data: { color: 'Red', status: 'inactive' } });
            expect(Object.keys((visit.mock.calls[0][1] as { data: object }).data).sort()).toEqual(['color', 'status']);
        });

        it('shows the server\'s plate error under the field', () => {
            renderShow('vehicles');
            fireEvent.click(screen.getByRole('button', { name: '+ Add vehicle' }));
            fireEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Add vehicle' }));

            act(() => (visit.mock.calls[0][1] as { onError: (errors: Record<string, string>) => void }).onError({ plate_number: 'Another vehicle already has this plate number.' }));

            expect(screen.getByLabelText(/^Plate number/).closest('div')).toHaveTextContent('Another vehicle already has this plate number.');
        });

        it('offers "Set as primary" only for active vehicles that are not primary, and posts to that vehicle', () => {
            renderShow('vehicles');

            expect(screen.getAllByRole('button', { name: 'Set as primary' })).toHaveLength(1);
            fireEvent.click(screen.getByRole('button', { name: 'Set as primary' }));

            expect(visit.mock.calls[0][0]).toBe('/admin/drivers/9/vehicles/32/set-primary');
            expect(visit.mock.calls[0][1]).toMatchObject({ method: 'post' });
            expect(screen.getByText('Primary')).toBeInTheDocument();
            expect(screen.getByText('Pending review')).toBeInTheDocument();
        });

        it('disables every vehicle write without the permission and says when there are none', () => {
            const { unmount } = renderShow('vehicles', {}, { ...allActions, addVehicle: null, updateVehicle: null, setPrimaryVehicle: null });
            expect(screen.getByRole('button', { name: '+ Add vehicle' })).toBeDisabled();
            expect(screen.getByRole('button', { name: 'Edit Toyota Camry' })).toBeDisabled();
            expect(screen.getByRole('button', { name: 'Set as primary' })).toBeDisabled();
            unmount();

            renderShow('vehicles', { vehicles: [] });
            expect(screen.getByText('No vehicles yet')).toBeInTheDocument();
        });
    });

    describe('earnings, profile and bank accounts', () => {
        it('shows the balance, the summaries, the ledger and says the ledger is still pending', () => {
            renderShow('earnings');

            expect(screen.getByText('Current balance').nextElementSibling).toHaveTextContent('AMD 300,000');
            expect(screen.getByText(/Showing earnings from completed trips/)).toBeInTheDocument();
            expect(screen.getByText('TK-AAAA1111')).toBeInTheDocument();
            expect(screen.getByText('+ AMD 120,000')).toBeInTheDocument();
            expect(screen.getByRole('button', { name: 'Add adjustment' })).toBeDisabled();
        });

        it('shows availability, the masked licence with its expiry, and the home zone on the profile', () => {
            renderShow('profile');

            expect(screen.getByText('Availability').nextElementSibling).toHaveTextContent('Online');
            expect(screen.getByText('License').nextElementSibling).toHaveTextContent('•••• 4242 · May 1, 2028');
            expect(screen.getByText('Home zone').nextElementSibling).toHaveTextContent('Yerevan');
        });

        it('shows the masked account and reveals through the server only when allowed', () => {
            const { unmount } = renderShow('bank-accounts');
            expect(screen.getByText('•••• 4821')).toBeInTheDocument();
            expect(screen.getByRole('button', { name: /Reveal account/ })).toBeEnabled();
            unmount();

            renderShow('bank-accounts', {}, { ...allActions, revealBankAccount: null });
            expect(screen.getByRole('button', { name: /Reveal account/ })).toBeDisabled();
        });
    });
});
