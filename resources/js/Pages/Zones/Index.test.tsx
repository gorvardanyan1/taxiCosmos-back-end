import { router } from '@inertiajs/core';
import { act, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import ZonesIndex from '@/Pages/Zones/Index';
import { page, setLocation, sharedProps } from '@/test/inertia';
import type { FareRule, Zone } from '@/types';

vi.mock('@inertiajs/react', async (importOriginal) => (await import('@/test/inertia')).inertiaMock(importOriginal));

const zone = (overrides: Partial<Zone>): Zone => ({ id: 1, name: 'Downtown Core', code: 'DOWNTOWN', status: 'active', polygon_valid: true, timezone: 'Asia/Yerevan', currency: 'AMD', priority: 5, points: 5, ...overrides });
const polygon = { type: 'Polygon' as const, coordinates: [[[44.4, 40.1], [44.6, 40.1], [44.6, 40.3], [44.4, 40.3], [44.4, 40.1]]] };
const actions = { createZone: '/admin/zones', updateZone: '/admin/zones/{id}', deactivateZone: '/admin/zones/{id}/deactivate', activateZone: '/admin/zones/{id}/activate', saveFareRule: '/admin/zones/{id}/fare-rules', drawPolygon: null };
const amd = (amount: number) => ({ amount, currency: 'AMD' });
const rule = (overrides: Partial<FareRule>): FareRule => ({
    id: 11, zone_id: 1, vehicle_class: 'economy', base_fare: amd(500), per_km: amd(150), per_minute: amd(20), min_fare: amd(800), waiting_free_minutes: 3, waiting_per_minute: amd(30),
    cancellation_fee: amd(500), cancellation_free_seconds: 120, booking_fee: amd(0), tax_rate_bps: 0, tax_inclusive: false,
    effective_from: '2026-10-01T08:00:00+00:00', effective_to: null, created_by: null, ...overrides,
});
const base = { zones: [zone({}), zone({ id: 2, name: 'Harbor', code: 'HARBOR', status: 'inactive', priority: 1 })], selectedZoneId: 1, selectedPolygon: polygon, fareRules: [] as FareRule[], fareHistory: null, vehicleClasses: ['economy' as const, 'comfort' as const, 'business' as const], timezones: ['Asia/Yerevan', 'UTC'], currencies: ['AMD', 'USD'], actions };

describe('Zones page', () => {
    let visit: ReturnType<typeof vi.spyOn>;
    beforeEach(() => { page.props = sharedProps(); visit = vi.spyOn(router, 'visit').mockImplementation(() => {}); setLocation('/admin/zones'); });
    afterEach(() => vi.restoreAllMocks());

    it('lists zones with code and status and describes the selected one', () => {
        render(<ZonesIndex {...base} />);

        expect(screen.getByRole('button', { name: /Downtown Core/ })).toHaveAttribute('aria-pressed', 'true');
        expect(screen.getByRole('button', { name: /Harbor/ })).toHaveAttribute('aria-pressed', 'false');
        expect(screen.getByText('HARBOR')).toBeInTheDocument();
        expect(screen.getByText('Inactive')).toBeInTheDocument();
        expect(screen.getByText('Active')).toBeInTheDocument();
        expect(screen.getByText('Asia/Yerevan · AMD · priority 5 · 5 points')).toBeInTheDocument();
    });

    it('draws the real polygon of the selected zone', () => {
        const { container } = render(<ZonesIndex {...base} />);

        const shape = screen.getByRole('img', { name: 'Downtown Core service area' });
        const polygons = container.querySelectorAll('svg polygon');
        expect(shape).toBeInTheDocument();
        expect(polygons).toHaveLength(1);
        expect(polygons[0].getAttribute('points')?.split(' ')).toHaveLength(5);
    });

    it('selects another zone through the URL', () => {
        const get = vi.spyOn(router, 'get').mockImplementation(() => {});
        render(<ZonesIndex {...base} />);

        fireEvent.click(screen.getByRole('button', { name: /Harbor/ }));

        expect(get).toHaveBeenCalledWith('/admin/zones?zone=2', {}, { preserveState: true, preserveScroll: true });
    });

    it('creates a zone through the form opened by Add Zone', () => {
        render(<ZonesIndex {...base} />);

        fireEvent.click(screen.getByRole('button', { name: '+ Add Zone' }));
        expect(screen.getByRole('dialog', { name: 'Add zone' })).toBeInTheDocument();
        fireEvent.change(screen.getByLabelText(/Name/), { target: { value: 'Airport' } });
        fireEvent.click(screen.getByRole('button', { name: 'Create zone' }));

        expect(visit.mock.calls[0][0]).toBe('/admin/zones');
        expect(visit.mock.calls[0][1]).toMatchObject({ method: 'post' });
    });

    it('edits the selected zone through the toolbar', () => {
        render(<ZonesIndex {...base} />);

        fireEvent.click(screen.getByRole('button', { name: 'Edit zone' }));
        expect(screen.getByRole('dialog', { name: 'Edit Downtown Core' })).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));

        expect(visit.mock.calls[0][0]).toBe('/admin/zones/1');
        expect(visit.mock.calls[0][1]).toMatchObject({ method: 'patch' });
    });

    it('deactivates the selected zone only after confirming', () => {
        const confirm = vi.spyOn(window, 'confirm');
        render(<ZonesIndex {...base} />);

        confirm.mockReturnValue(false);
        fireEvent.click(screen.getByLabelText('Deactivate zone').closest('button')!);
        expect(visit).not.toHaveBeenCalled();

        confirm.mockReturnValue(true);
        fireEvent.click(screen.getByLabelText('Deactivate zone').closest('button')!);
        expect(confirm).toHaveBeenLastCalledWith(expect.stringContaining('Deactivate Downtown Core?'));
        expect(visit.mock.calls[0][0]).toBe('/admin/zones/1/deactivate');
        expect(visit.mock.calls[0][1]).toMatchObject({ method: 'post' });
    });

    it('offers reactivation instead of deactivation for an inactive zone', () => {
        vi.spyOn(window, 'confirm').mockReturnValue(true);
        render(<ZonesIndex {...base} selectedZoneId={2} />);

        expect(screen.queryByLabelText('Deactivate zone')).toBeNull();
        fireEvent.click(screen.getByLabelText('Reactivate zone').closest('button')!);

        expect(visit.mock.calls[0][0]).toBe('/admin/zones/2/activate');
    });

    it('shows an empty state and still lets the admin add the first zone', () => {
        render(<ZonesIndex {...base} zones={[]} selectedZoneId={null} selectedPolygon={null} />);

        expect(screen.getByText('No zones yet')).toBeInTheDocument();
        expect(screen.queryByRole('img')).toBeNull();
        fireEvent.click(screen.getByRole('button', { name: '+ Add Zone' }));
        expect(screen.getByRole('dialog', { name: 'Add zone' })).toBeInTheDocument();
    });

    it('disables writing while the endpoints are not available', () => {
        render(<ZonesIndex {...base} actions={{ ...actions, createZone: null, updateZone: null }} />);

        expect(screen.getByRole('button', { name: '+ Add Zone' })).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Edit zone' })).toBeDisabled();
    });

    it('says when a zone has no fare rules yet', () => {
        render(<ZonesIndex {...base} />);

        expect(screen.getByText('No fare rules for this zone.')).toBeInTheDocument();
    });

    describe('fare rules: editing creates a new version', () => {
        const economy = rule({});
        const comfort = rule({ id: 12, vehicle_class: 'comfort', base_fare: amd(700), per_km: amd(200), per_minute: amd(30), min_fare: amd(1000), effective_from: '2026-10-05T10:30:00+00:00' });

        it('shows the prices in force with when they started and that they are current', () => {
            render(<ZonesIndex {...base} fareRules={[economy, comfort]} />);

            const row = screen.getByText('Economy').closest('tr')!;
            expect(row).toHaveTextContent('AMD 500');
            expect(row).toHaveTextContent('AMD 150');
            expect(row).toHaveTextContent('AMD 20');
            expect(row).toHaveTextContent('AMD 800');
            expect(row).toHaveTextContent('Oct 1 · 08:00');
            expect(row).toHaveTextContent('Current');
            expect(screen.getByText('Comfort').closest('tr')).toHaveTextContent('AMD 1,000');
            expect(screen.getByText('2 vehicle classes')).toBeInTheDocument();
            expect(screen.getByRole('columnheader', { name: 'Effective from' })).toBeInTheDocument();
            expect(screen.getByRole('columnheader', { name: 'Effective to' })).toBeInTheDocument();
        });

        it('has no inline price inputs: the pencil opens a form that starts from the prices in force', () => {
            render(<ZonesIndex {...base} fareRules={[economy]} />);

            expect(screen.queryByRole('textbox', { name: /Base Fare/ })).toBeNull();
            fireEvent.click(screen.getByRole('button', { name: 'New version of Economy' }));

            expect(screen.getByRole('dialog', { name: 'New version of Economy' })).toBeInTheDocument();
            expect(screen.getByLabelText(/^Base Fare/)).toHaveValue('500');
            expect(screen.getByLabelText(/^Per km/)).toHaveValue('150');
            expect(screen.getByLabelText(/^Min Fare/)).toHaveValue('800');
            expect(screen.getByLabelText(/^Free waiting/)).toHaveValue(3);
            expect(screen.getByLabelText(/^Free cancellation window/)).toHaveValue(120);
            expect(screen.getByLabelText(/^Vehicle class/)).toBeDisabled();
            expect(screen.getByText(/starts a new version from now/)).toBeInTheDocument();
        });

        it('posts the whole snapshot in minor units as one request', () => {
            render(<ZonesIndex {...base} fareRules={[economy]} />);

            fireEvent.click(screen.getByRole('button', { name: 'New version of Economy' }));
            fireEvent.change(screen.getByLabelText(/^Base Fare/), { target: { value: '650' } });
            fireEvent.change(screen.getByLabelText(/^Tax rate/), { target: { value: '1800' } });
            fireEvent.click(screen.getByLabelText('Prices already include tax'));
            fireEvent.click(screen.getByRole('button', { name: 'Save new version' }));

            expect(visit).toHaveBeenCalledTimes(1);
            expect(visit.mock.calls[0][0]).toBe('/admin/zones/1/fare-rules');
            expect(visit.mock.calls[0][1]).toMatchObject({ method: 'post', data: {
                vehicle_class: 'economy', base_fare: 650, per_km: 150, per_minute: 20, min_fare: 800, waiting_free_minutes: 3, waiting_per_minute: 30,
                cancellation_fee: 500, cancellation_free_seconds: 120, booking_fee: 0, tax_rate_bps: 1800, tax_inclusive: true,
            } });
        });

        it('turns typed amounts into minor units of the currency without floats', () => {
            page.props = sharedProps();
            render(<ZonesIndex {...base} zones={[zone({ currency: 'USD' })]} fareRules={[rule({ base_fare: { amount: 250, currency: 'USD' }, per_km: { amount: 133, currency: 'USD' }, per_minute: { amount: 0, currency: 'USD' }, min_fare: { amount: 0, currency: 'USD' }, waiting_per_minute: { amount: 0, currency: 'USD' }, cancellation_fee: { amount: 0, currency: 'USD' }, booking_fee: { amount: 0, currency: 'USD' } })]} />);

            fireEvent.click(screen.getByRole('button', { name: 'New version of Economy' }));
            expect(screen.getByLabelText(/^Base Fare/)).toHaveValue('2.5');
            fireEvent.change(screen.getByLabelText(/^Base Fare/), { target: { value: '12.34' } });
            fireEvent.change(screen.getByLabelText(/^Per km/), { target: { value: '0.07' } });
            fireEvent.click(screen.getByRole('button', { name: 'Save new version' }));

            expect(visit.mock.calls[0][1]).toMatchObject({ data: { base_fare: 1234, per_km: 7 } });
        });

        it('refuses a price that is not an amount and sends nothing', () => {
            render(<ZonesIndex {...base} fareRules={[economy]} />);

            fireEvent.click(screen.getByRole('button', { name: 'New version of Economy' }));
            fireEvent.change(screen.getByLabelText(/^Base Fare/), { target: { value: '12.5' } }); // AMD has no decimals
            fireEvent.change(screen.getByLabelText(/^Per km/), { target: { value: 'abc' } });
            fireEvent.click(screen.getByRole('button', { name: 'Save new version' }));

            expect(screen.getAllByText('Enter a valid amount.')).toHaveLength(2);
            expect(visit).not.toHaveBeenCalled();
        });

        it('adds the first rule of a class that has none, offering only the missing classes', () => {
            render(<ZonesIndex {...base} fareRules={[economy]} />);

            fireEvent.click(screen.getByRole('button', { name: /Add fare rule/ }));
            const dialog = screen.getByRole('dialog', { name: 'Add fare rule' });
            expect(dialog).toBeInTheDocument();
            expect(screen.getAllByRole('option').map((o) => o.textContent)).toEqual(['Comfort', 'Business']);
            fireEvent.change(screen.getByLabelText(/^Base Fare/), { target: { value: '900' } });
            fireEvent.change(screen.getByLabelText(/^Per km/), { target: { value: '200' } });
            fireEvent.change(screen.getByLabelText(/^Per min/), { target: { value: '30' } });
            fireEvent.change(screen.getByLabelText(/^Min Fare/), { target: { value: '1000' } });
            fireEvent.click(within(dialog).getByRole('button', { name: 'Add fare rule' }));

            expect(visit.mock.calls[0][1]).toMatchObject({ method: 'post', data: { vehicle_class: 'comfort', base_fare: 900, per_km: 200, per_minute: 30, min_fare: 1000, waiting_free_minutes: 3 } });
        });

        it('cannot add a rule when every class has one', () => {
            render(<ZonesIndex {...base} fareRules={[economy, comfort, rule({ id: 13, vehicle_class: 'business' })]} />);

            expect(screen.getByRole('button', { name: /Add fare rule/ })).toBeDisabled();
        });

        it('shows the server\'s error under the field it belongs to', () => {
            render(<ZonesIndex {...base} fareRules={[economy]} />);
            fireEvent.click(screen.getByRole('button', { name: 'New version of Economy' }));
            fireEvent.click(screen.getByRole('button', { name: 'Save new version' }));

            const options = visit.mock.calls[0][1] as { onError: (errors: Record<string, string>) => void };
            act(() => options.onError({ tax_rate_bps: 'The tax rate must not be greater than 10000.' }));

            expect(screen.getByLabelText(/^Tax rate/).closest('div')).toHaveTextContent('The tax rate must not be greater than 10000.');
        });

        it('disables saving prices for an admin without fares.manage', () => {
            render(<ZonesIndex {...base} fareRules={[economy]} actions={{ ...actions, saveFareRule: null }} />);

            expect(screen.getByRole('button', { name: 'New version of Economy' })).toBeDisabled();
            expect(screen.getByRole('button', { name: /Add fare rule/ })).toBeDisabled();
            expect(screen.getByRole('button', { name: 'History of Economy' })).toBeEnabled();
        });

        it('opens the history of a class through the URL and lists every version, newest first', () => {
            const get = vi.spyOn(router, 'get').mockImplementation(() => {});
            const { rerender } = render(<ZonesIndex {...base} fareRules={[economy]} />);

            fireEvent.click(screen.getByRole('button', { name: 'History of Economy' }));
            expect(get).toHaveBeenCalledWith('/admin/zones?history=economy', {}, { preserveState: true, preserveScroll: true });

            const versions = [
                rule({ id: 11, base_fare: amd(650), effective_from: '2026-10-10T09:00:00+00:00', effective_to: null, created_by: 'Zed Zoner' }),
                rule({ id: 10, base_fare: amd(500), effective_from: '2026-10-01T08:00:00+00:00', effective_to: '2026-10-10T09:00:00+00:00', created_by: 'Ann Admin' }),
            ];
            rerender(<ZonesIndex {...base} fareRules={[economy]} fareHistory={{ vehicle_class: 'economy', versions }} />);

            const dialog = screen.getByRole('dialog', { name: 'History of Economy' });
            const rows = within(dialog).getAllByRole('row').slice(1);
            expect(rows).toHaveLength(2);
            expect(rows[0]).toHaveTextContent('Oct 10 · 09:00');
            expect(rows[0]).toHaveTextContent('Current');
            expect(rows[0]).toHaveTextContent('AMD 650');
            expect(rows[0]).toHaveTextContent('Zed Zoner');
            expect(rows[1]).toHaveTextContent('Oct 1 · 08:00');
            expect(rows[1]).toHaveTextContent('Oct 10 · 09:00');
            expect(rows[1]).toHaveTextContent('AMD 500');
            expect(rows[1]).toHaveTextContent('Ann Admin');
            expect(within(dialog).queryByRole('button', { name: /Edit|Save/ })).toBeNull();

            fireEvent.click(within(dialog).getByRole('button', { name: 'Close' }));
            expect(get).toHaveBeenLastCalledWith('/admin/zones', {}, { preserveState: true, preserveScroll: true });
        });
    });
});
