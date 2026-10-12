import { router } from '@inertiajs/core';
import { act, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import CommissionRulesIndex from '@/Pages/CommissionRules/Index';
import { page, setLocation, sharedProps } from '@/test/inertia';
import type { CommissionRule, Paginated } from '@/types';

vi.mock('@inertiajs/react', async (importOriginal) => (await import('@/test/inertia')).inertiaMock(importOriginal));

const amd = (amount: number) => ({ amount, currency: 'AMD' });
const rule = (overrides: Partial<CommissionRule>): CommissionRule => ({
    id: 3, zone_id: 1, zone: 'Downtown Core', vehicle_class: 'economy', type: 'percentage', rate_bp: 1800, fixed_fee: null, minimum: amd(500), maximum: amd(8000),
    applies_to_surge: true, effective_from: '2026-10-01T08:00:00+00:00', effective_to: null, created_by: null, ...overrides,
});
const airport = rule({ id: 2, zone_id: 2, zone: 'Airport Zone', vehicle_class: null, type: 'percentage_plus_fixed', rate_bp: 1600, fixed_fee: amd(300), minimum: amd(800), maximum: null });
const north = rule({ id: 1, zone_id: 3, zone: 'Yerevan North', vehicle_class: 'business', type: 'fixed', rate_bp: null, fixed_fee: amd(2500), minimum: null, maximum: null, applies_to_surge: false, effective_from: '2026-09-01T08:00:00+00:00' });
const paginator = (data: CommissionRule[]): Paginated<CommissionRule> => ({ data, current_page: 1, last_page: 1, per_page: 10, total: data.length, from: 1, to: data.length, path: '/admin/commission-rules', links: [] });
const base = {
    rules: paginator([rule({}), airport, north]), filters: {}, sort: null, previewRule: rule({}),
    previewRules: [{ id: 3, zone: 'Downtown Core', vehicle_class: 'economy' as const }, { id: 2, zone: 'Airport Zone', vehicle_class: null }, { id: 1, zone: 'Yerevan North', vehicle_class: 'business' as const }],
    previewFare: amd(12500), history: null,
    zones: [{ id: 1, name: 'Downtown Core', currency: 'AMD' }, { id: 2, name: 'Airport Zone', currency: 'AMD' }],
    vehicleClasses: ['economy' as const, 'comfort' as const, 'business' as const], types: ['percentage' as const, 'fixed' as const, 'percentage_plus_fixed' as const],
    actions: { createVersion: '/admin/commission-rules' },
};

describe('Commission page', () => {
    let visit: ReturnType<typeof vi.spyOn>;
    let get: ReturnType<typeof vi.spyOn>;
    beforeEach(() => { page.props = sharedProps(); visit = vi.spyOn(router, 'visit').mockImplementation(() => {}); get = vi.spyOn(router, 'get').mockImplementation(() => {}); setLocation('/admin/commission-rules'); });
    afterEach(() => vi.restoreAllMocks());

    it('lists the rules with their terms, "every class" for a zone-wide rule, and when they started', () => {
        render(<CommissionRulesIndex {...base} />);

        const downtown = screen.getByText('Downtown Core').closest('tr')!;
        expect(downtown).toHaveTextContent('Economy');
        expect(downtown).toHaveTextContent('Percentage');
        expect(downtown).toHaveTextContent('18%');
        expect(downtown).toHaveTextContent('AMD 500 / AMD 8,000');
        expect(downtown).toHaveTextContent('Yes');
        expect(downtown).toHaveTextContent('Oct 1, 2026');
        const airportRow = screen.getByText('Airport Zone').closest('tr')!;
        expect(airportRow).toHaveTextContent('Every class');
        expect(airportRow).toHaveTextContent('Percentage + fixed');
        expect(airportRow).toHaveTextContent('16%');
        expect(airportRow).toHaveTextContent('AMD 300');
        expect(airportRow).toHaveTextContent('AMD 800 / —');
        const northRow = screen.getByText('Yerevan North').closest('tr')!;
        expect(northRow).toHaveTextContent('AMD 2,500');
        expect(northRow).toHaveTextContent('No');
    });

    it('previews the commission for a trip fare with integer maths', () => {
        render(<CommissionRulesIndex {...base} />);

        // 18 % of 12,500 = 2,250; the driver keeps 10,250
        expect(screen.getByTestId('preview-commission')).toHaveTextContent('AMD 2,250');
        expect(screen.getByTestId('preview-earnings')).toHaveTextContent('AMD 10,250');

        fireEvent.change(screen.getByLabelText('Trip fare'), { target: { value: '1000' } });
        // 18 % of 1,000 = 180, lifted to the 500 minimum
        expect(screen.getByTestId('preview-commission')).toHaveTextContent('AMD 500');
        expect(screen.getByTestId('preview-earnings')).toHaveTextContent('AMD 500');

        fireEvent.change(screen.getByLabelText('Trip fare'), { target: { value: 'abc' } });
        expect(screen.getByText('Enter a valid fare.')).toBeInTheDocument();
        expect(screen.queryByTestId('preview-commission')).toBeNull();
    });

    it('previews another rule through the URL', () => {
        render(<CommissionRulesIndex {...base} />);

        fireEvent.change(screen.getByLabelText('Rule to preview'), { target: { value: '2' } });

        expect(get).toHaveBeenCalledWith('/admin/commission-rules?preview=2', {}, { preserveState: true, preserveScroll: true });
    });

    it('says there is nothing to preview when no rule exists', () => {
        render(<CommissionRulesIndex {...base} rules={paginator([])} previewRule={null} previewRules={[]} />);

        expect(screen.getByText('No commission rules')).toBeInTheDocument();
        expect(screen.getByText('No rule to preview.')).toBeInTheDocument();
    });

    it('filters by vehicle class through the URL, with an option for the zone-wide rules', () => {
        render(<CommissionRulesIndex {...base} />);

        const select = screen.getByRole('combobox', { name: /All classes/ });
        expect([...select.querySelectorAll('option')].map((o) => o.textContent)).toEqual(['All classes', 'Every class', 'Economy', 'Comfort', 'Business']);
        fireEvent.change(select, { target: { value: 'all' } });

        expect(get).toHaveBeenLastCalledWith('/admin/commission-rules?filter%5Bvehicle_class%5D=all', {}, { preserveState: true, preserveScroll: true });
    });

    describe('saving creates a new version', () => {
        it('opens the form from "New version" with the first zone and only the terms of the type', () => {
            render(<CommissionRulesIndex {...base} />);

            fireEvent.click(screen.getByRole('button', { name: 'New version' }));
            const dialog = screen.getByRole('dialog', { name: 'New commission rule' });
            expect(within(dialog).getByLabelText(/^Zone/)).toHaveValue('1');
            expect(within(dialog).getByLabelText(/^Vehicle class/)).toHaveValue('');
            expect(within(dialog).getByLabelText(/^Rate/)).toBeInTheDocument();
            expect(within(dialog).queryByLabelText(/^Fixed amount/)).toBeNull();

            fireEvent.change(within(dialog).getByLabelText(/^Type/), { target: { value: 'fixed' } });
            expect(within(dialog).queryByLabelText(/^Rate/)).toBeNull();
            expect(within(dialog).getByLabelText(/^Fixed amount/)).toBeInTheDocument();

            fireEvent.change(within(dialog).getByLabelText(/^Type/), { target: { value: 'percentage_plus_fixed' } });
            expect(within(dialog).getByLabelText(/^Rate/)).toBeInTheDocument();
            expect(within(dialog).getByLabelText(/^Fixed amount/)).toBeInTheDocument();
        });

        it('posts the terms as basis points and minor units', () => {
            render(<CommissionRulesIndex {...base} />);

            fireEvent.click(screen.getByRole('button', { name: 'New version' }));
            const dialog = screen.getByRole('dialog');
            fireEvent.change(within(dialog).getByLabelText(/^Zone/), { target: { value: '2' } });
            fireEvent.change(within(dialog).getByLabelText(/^Vehicle class/), { target: { value: 'comfort' } });
            fireEvent.change(within(dialog).getByLabelText(/^Type/), { target: { value: 'percentage_plus_fixed' } });
            fireEvent.change(within(dialog).getByLabelText(/^Rate/), { target: { value: '17.5' } });
            fireEvent.change(within(dialog).getByLabelText(/^Fixed amount/), { target: { value: '300' } });
            fireEvent.change(within(dialog).getByLabelText(/^Minimum commission/), { target: { value: '800' } });
            fireEvent.change(within(dialog).getByLabelText(/^Maximum commission/), { target: { value: '9000' } });
            fireEvent.click(within(dialog).getByLabelText('Commission applies to the surge part of the fare'));
            fireEvent.click(within(dialog).getByRole('button', { name: 'Save new version' }));

            expect(visit).toHaveBeenCalledTimes(1);
            expect(visit.mock.calls[0][0]).toBe('/admin/commission-rules');
            expect(visit.mock.calls[0][1]).toMatchObject({ method: 'post', data: {
                zone_id: 2, vehicle_class: 'comfort', type: 'percentage_plus_fixed', rate_bps: 1750, fixed_amount: 300, min_commission: 800, max_commission: 9000, applies_to_surge: false,
            } });
        });

        it('sends an empty maximum as no limit, an empty class as every class, and zero for the terms a type does not use', () => {
            render(<CommissionRulesIndex {...base} />);

            fireEvent.click(screen.getByRole('button', { name: 'New version' }));
            const dialog = screen.getByRole('dialog');
            fireEvent.change(within(dialog).getByLabelText(/^Type/), { target: { value: 'fixed' } });
            fireEvent.change(within(dialog).getByLabelText(/^Fixed amount/), { target: { value: '2500' } });
            fireEvent.click(within(dialog).getByRole('button', { name: 'Save new version' }));

            expect(visit.mock.calls[0][1]).toMatchObject({ data: { vehicle_class: null, type: 'fixed', rate_bps: 0, fixed_amount: 2500, min_commission: 0, max_commission: null, applies_to_surge: true } });
        });

        it('refuses a bad rate or amount next to the field and sends nothing', () => {
            render(<CommissionRulesIndex {...base} />);

            fireEvent.click(screen.getByRole('button', { name: 'New version' }));
            const dialog = screen.getByRole('dialog');
            fireEvent.click(within(dialog).getByRole('button', { name: 'Save new version' }));
            expect(within(dialog).getByText('Enter a percentage from 0 to 100, like 18 or 17.5.')).toBeInTheDocument();

            fireEvent.change(within(dialog).getByLabelText(/^Rate/), { target: { value: '101' } });
            fireEvent.change(within(dialog).getByLabelText(/^Minimum commission/), { target: { value: '12.5' } }); // AMD has no decimals
            fireEvent.change(within(dialog).getByLabelText(/^Maximum commission/), { target: { value: 'abc' } });
            fireEvent.click(within(dialog).getByRole('button', { name: 'Save new version' }));

            expect(within(dialog).getByLabelText(/^Rate/).closest('div')).toHaveTextContent('Enter a percentage');
            expect(within(dialog).getByLabelText(/^Minimum commission/).closest('div')).toHaveTextContent('Enter a valid amount.');
            expect(within(dialog).getByLabelText(/^Maximum commission/).closest('div')).toHaveTextContent('Enter a valid amount.');
            expect(visit).not.toHaveBeenCalled();
        });

        it('starts a new version of an existing rule from its row, with its zone and class fixed and its terms filled in', () => {
            render(<CommissionRulesIndex {...base} />);

            fireEvent.click(screen.getByRole('button', { name: 'New version of the Downtown Core rule' }));
            const dialog = screen.getByRole('dialog', { name: 'New version of the Downtown Core rule' });
            expect(within(dialog).getByLabelText(/^Zone/)).toBeDisabled();
            expect(within(dialog).getByLabelText(/^Vehicle class/)).toBeDisabled();
            expect(within(dialog).getByLabelText(/^Vehicle class/)).toHaveValue('economy');
            expect(within(dialog).getByLabelText(/^Rate/)).toHaveValue('18');
            expect(within(dialog).getByLabelText(/^Minimum commission/)).toHaveValue('500');
            expect(within(dialog).getByLabelText(/^Maximum commission/)).toHaveValue('8000');
            expect(within(dialog).getByLabelText('Commission applies to the surge part of the fare')).toBeChecked();
            expect(within(dialog).getByText(/Trips already completed keep the commission/)).toBeInTheDocument();

            fireEvent.change(within(dialog).getByLabelText(/^Rate/), { target: { value: '22' } });
            fireEvent.click(within(dialog).getByRole('button', { name: 'Save new version' }));

            expect(visit.mock.calls[0][1]).toMatchObject({ method: 'post', data: { zone_id: 1, vehicle_class: 'economy', type: 'percentage', rate_bps: 2200, min_commission: 500, max_commission: 8000 } });
        });

        it('shows the server\'s error under the field it belongs to', () => {
            render(<CommissionRulesIndex {...base} />);
            fireEvent.click(screen.getByRole('button', { name: 'New version of the Downtown Core rule' }));
            fireEvent.click(screen.getByRole('button', { name: 'Save new version' }));

            const options = visit.mock.calls[0][1] as { onError: (errors: Record<string, string>) => void };
            act(() => options.onError({ max_commission: 'The maximum commission must be greater than or equal to 500.' }));

            expect(screen.getByLabelText(/^Maximum commission/).closest('div')).toHaveTextContent('must be greater than or equal to 500');
        });

        it('cannot save without the endpoint or without a zone', () => {
            const { unmount } = render(<CommissionRulesIndex {...base} actions={{ createVersion: null }} />);
            expect(screen.getByRole('button', { name: 'New version' })).toBeDisabled();
            expect(screen.getByRole('button', { name: 'New version of the Downtown Core rule' })).toBeDisabled();
            unmount();

            render(<CommissionRulesIndex {...base} zones={[]} />);
            expect(screen.getByRole('button', { name: 'New version' })).toBeDisabled();
        });
    });

    describe('history', () => {
        it('opens the history of a rule through the URL and lists every version, newest first', () => {
            const { rerender } = render(<CommissionRulesIndex {...base} />);

            fireEvent.click(screen.getByRole('button', { name: 'History of the Downtown Core rule' }));
            expect(get).toHaveBeenCalledWith('/admin/commission-rules?history=3', {}, { preserveState: true, preserveScroll: true });

            const versions = [
                rule({ id: 3, rate_bp: 2200, effective_from: '2026-10-10T09:00:00+00:00', effective_to: null, created_by: 'Zed Zoner' }),
                rule({ id: 9, rate_bp: 1800, effective_from: '2026-10-01T08:00:00+00:00', effective_to: '2026-10-10T09:00:00+00:00', created_by: 'Ann Admin', applies_to_surge: false }),
            ];
            rerender(<CommissionRulesIndex {...base} history={{ zone: 'Downtown Core', vehicle_class: 'economy', versions }} />);

            const dialog = screen.getByRole('dialog', { name: 'History of the Downtown Core rule' });
            const rows = within(dialog).getAllByRole('row').slice(1);
            expect(rows).toHaveLength(2);
            expect(rows[0]).toHaveTextContent('Oct 10 · 09:00');
            expect(rows[0]).toHaveTextContent('Current');
            expect(rows[0]).toHaveTextContent('22%');
            expect(rows[0]).toHaveTextContent('Zed Zoner');
            expect(rows[1]).toHaveTextContent('Oct 1 · 08:00');
            expect(rows[1]).toHaveTextContent('Oct 10 · 09:00');
            expect(rows[1]).toHaveTextContent('18%');
            expect(rows[1]).toHaveTextContent('No');
            expect(rows[1]).toHaveTextContent('Ann Admin');
            expect(within(dialog).queryByRole('button', { name: /Save/ })).toBeNull();

            fireEvent.click(within(dialog).getByRole('button', { name: 'Close' }));
            expect(get).toHaveBeenLastCalledWith('/admin/commission-rules', {}, { preserveState: true, preserveScroll: true });
        });
    });
});
