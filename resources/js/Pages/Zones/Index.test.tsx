import { router } from '@inertiajs/core';
import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import ZonesIndex from '@/Pages/Zones/Index';
import { page, setLocation, sharedProps } from '@/test/inertia';
import type { Zone } from '@/types';

vi.mock('@inertiajs/react', async (importOriginal) => (await import('@/test/inertia')).inertiaMock(importOriginal));

const zone = (overrides: Partial<Zone>): Zone => ({ id: 1, name: 'Downtown Core', code: 'DOWNTOWN', status: 'active', polygon_valid: true, timezone: 'Asia/Yerevan', currency: 'AMD', priority: 5, points: 5, ...overrides });
const polygon = { type: 'Polygon' as const, coordinates: [[[44.4, 40.1], [44.6, 40.1], [44.6, 40.3], [44.4, 40.3], [44.4, 40.1]]] };
const actions = { createZone: '/admin/zones', updateZone: '/admin/zones/{id}', deactivateZone: '/admin/zones/{id}/deactivate', activateZone: '/admin/zones/{id}/activate', updateFareRule: null, drawPolygon: null };
const base = { zones: [zone({}), zone({ id: 2, name: 'Harbor', code: 'HARBOR', status: 'inactive', priority: 1 })], selectedZoneId: 1, selectedPolygon: polygon, fareRules: [], timezones: ['Asia/Yerevan', 'UTC'], currencies: ['AMD', 'USD'], actions };

describe('Zones page', () => {
    let visit: ReturnType<typeof vi.spyOn>;
    beforeEach(() => { page.props = sharedProps(); visit = vi.spyOn(router, 'visit').mockImplementation(() => {}); setLocation('/admin/zones'); });
    afterEach(() => vi.restoreAllMocks());

    it('lists zones with code and status and describes the selected one', () => {
        render(<ZonesIndex {...base} />);

        expect(screen.getByRole('button', { name: /Downtown Core/ })).toHaveAttribute('aria-pressed', 'true');
        expect(screen.getByRole('button', { name: /Harbor/ })).toHaveAttribute('aria-pressed', 'false');
        expect(screen.getByText('HARBOR')).toBeInTheDocument();
        expect(screen.getByText('inactive')).toBeInTheDocument();
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
});
