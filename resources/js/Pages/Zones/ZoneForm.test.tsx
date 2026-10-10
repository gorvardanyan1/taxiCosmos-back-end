import { router } from '@inertiajs/core';
import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import ZoneForm from '@/Pages/Zones/ZoneForm';
import { page, sharedProps } from '@/test/inertia';
import type { Zone } from '@/types';

vi.mock('@inertiajs/react', async (importOriginal) => (await import('@/test/inertia')).inertiaMock(importOriginal));

const zone: Zone = { id: 9, name: 'Downtown', code: 'DOWNTOWN', status: 'active', polygon_valid: true, timezone: 'Europe/Berlin', currency: 'EUR', priority: 4, points: 5 };
const polygon = { type: 'Polygon' as const, coordinates: [[[44.4, 40.1], [44.6, 40.1], [44.6, 40.3], [44.4, 40.1]]] };
const props = { open: true, onClose: vi.fn(), zone: null, polygon: null, timezones: ['Asia/Yerevan', 'Europe/Berlin', 'UTC'], currencies: ['AMD', 'EUR', 'USD'], url: '/admin/zones' };

describe('Zone form', () => {
    beforeEach(() => { page.props = sharedProps(); props.onClose = vi.fn(); });
    afterEach(() => vi.restoreAllMocks());

    it('creates a zone: posts every field, the priority as a number, and upper-cases the code', () => {
        const visit = vi.spyOn(router, 'visit').mockImplementation(() => {});
        render(<ZoneForm {...props} />);

        fireEvent.change(screen.getByLabelText(/Name/), { target: { value: 'Airport' } });
        fireEvent.change(screen.getByLabelText(/Code/), { target: { value: 'airport-1' } });
        fireEvent.change(screen.getByLabelText(/Timezone/), { target: { value: 'UTC' } });
        fireEvent.change(screen.getByLabelText(/Currency/), { target: { value: 'USD' } });
        fireEvent.change(screen.getByLabelText(/Priority/), { target: { value: '7' } });
        fireEvent.change(screen.getByLabelText(/Polygon/), { target: { value: '{"type":"Polygon"}' } });
        expect(screen.getByLabelText(/Code/)).toHaveValue('AIRPORT-1');
        fireEvent.click(screen.getByRole('button', { name: 'Create zone' }));

        expect(visit).toHaveBeenCalledTimes(1);
        const [url, options] = visit.mock.calls[0];
        expect(url).toBe('/admin/zones');
        expect(options).toMatchObject({ method: 'post', data: { name: 'Airport', code: 'AIRPORT-1', timezone: 'UTC', currency: 'USD', priority: 7, polygon: '{"type":"Polygon"}' } });
        expect(typeof (options?.data as { priority: unknown }).priority).toBe('number');
    });

    it('starts a new zone with sensible defaults', () => {
        render(<ZoneForm {...props} />);

        expect(screen.getByLabelText(/Timezone/)).toHaveValue('Asia/Yerevan');
        expect(screen.getByLabelText(/Currency/)).toHaveValue('AMD');
        expect(screen.getByLabelText(/Priority/)).toHaveValue(0);
        expect(screen.getByLabelText(/Polygon/)).toHaveValue('');
    });

    it('edits a zone: prefilled from it, the code is fixed and not sent, saved with PATCH', () => {
        const visit = vi.spyOn(router, 'visit').mockImplementation(() => {});
        render(<ZoneForm {...props} zone={zone} polygon={polygon} url="/admin/zones/9" />);

        expect(screen.getByRole('dialog', { name: 'Edit Downtown' })).toBeInTheDocument();
        expect(screen.getByLabelText(/Name/)).toHaveValue('Downtown');
        expect(screen.getByLabelText(/Code/)).toBeDisabled();
        expect(screen.getByLabelText(/Code/)).toHaveValue('DOWNTOWN');
        expect(screen.getByLabelText(/Timezone/)).toHaveValue('Europe/Berlin');
        expect(screen.getByLabelText(/Currency/)).toHaveValue('EUR');
        expect(screen.getByLabelText(/Priority/)).toHaveValue(4);
        expect(JSON.parse((screen.getByLabelText(/Polygon/) as HTMLTextAreaElement).value)).toEqual(polygon);

        fireEvent.change(screen.getByLabelText(/Name/), { target: { value: 'Downtown Core' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));

        const [url, options] = visit.mock.calls[0];
        expect(url).toBe('/admin/zones/9');
        expect(options?.method).toBe('patch');
        const data = options?.data as Record<string, unknown>;
        expect(data).toMatchObject({ name: 'Downtown Core', timezone: 'Europe/Berlin', currency: 'EUR', priority: 4 });
        expect(data).not.toHaveProperty('code');
    });

    it('shows the server errors next to their fields, the polygon one included', () => {
        vi.spyOn(router, 'visit').mockImplementation((_url, options) => {
            options?.onError?.({ polygon: 'Polygon intersects itself.', code: 'The code has already been taken.', timezone: 'The timezone must be valid.' });
            options?.onFinish?.({} as never);
        });
        render(<ZoneForm {...props} />);

        fireEvent.click(screen.getByRole('button', { name: 'Create zone' }));

        const alerts = screen.getAllByRole('alert').map((a) => a.textContent);
        expect(alerts).toEqual(expect.arrayContaining(['Polygon intersects itself.', 'The code has already been taken.', 'The timezone must be valid.']));
        expect(screen.getByLabelText(/Polygon/).closest('div')).toHaveTextContent('Polygon intersects itself.');
        expect(props.onClose).not.toHaveBeenCalled();
    });

    it('closes after a successful save', () => {
        vi.spyOn(router, 'visit').mockImplementation((_url, options) => { options?.onSuccess?.({} as never); });
        render(<ZoneForm {...props} />);

        fireEvent.click(screen.getByRole('button', { name: 'Create zone' }));

        expect(props.onClose).toHaveBeenCalledTimes(1);
    });

    it('cannot be submitted while the endpoint is missing', () => {
        const visit = vi.spyOn(router, 'visit').mockImplementation(() => {});
        render(<ZoneForm {...props} url={null} />);

        expect(screen.getByRole('button', { name: 'Create zone' })).toBeDisabled();
        expect(visit).not.toHaveBeenCalled();
    });

    it('renders nothing while closed', () => {
        render(<ZoneForm {...props} open={false} />);

        expect(screen.queryByRole('dialog')).toBeNull();
    });
});
