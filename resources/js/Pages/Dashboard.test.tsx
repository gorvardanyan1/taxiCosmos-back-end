import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Dashboard from '@/Pages/Dashboard';
import { grant, setLocation } from '@/test/inertia';

vi.mock('@inertiajs/react', async (importOriginal) => (await import('@/test/inertia')).inertiaMock(importOriginal));

const props = {
    as_of: '2026-10-05T14:42:00+00:00', zones: [], filters: { zone: null }, metrics: [], revenue_trend: { change_bp: 0, points: [] }, trip_status: [], map: { active_count: 0, pins: [] }, activity: [],
};
const everyAlert = { stuck_trips: 3, failed_payments: 5, urgent_tickets: 1 };
const viewLinks = () => screen.queryAllByRole('link', { name: /View →/ }).map((link) => link.getAttribute('href'));

describe('Dashboard alert cards by permission', () => {
    beforeEach(() => setLocation('/admin'));

    it('shows each alert only to an admin who may open the page it links to', () => {
        grant(['dashboard.view', 'trips.view']);
        const dispatcher = render(<Dashboard {...props} alerts={everyAlert} />);
        expect(screen.getByText('Stuck trips')).toBeInTheDocument();
        expect(screen.queryByText('Failed payments')).toBeNull();
        expect(screen.queryByText('Urgent tickets')).toBeNull();
        expect(viewLinks()).toEqual(['/admin/trips?filter[status]=in_progress']);
        dispatcher.unmount();

        grant(['dashboard.view', 'trips.view', 'payments.view']);
        const finance = render(<Dashboard {...props} alerts={everyAlert} />);
        expect(screen.getByText('Failed payments')).toBeInTheDocument();
        expect(screen.queryByText('Urgent tickets')).toBeNull();
        finance.unmount();

        grant(['dashboard.view', 'trips.view', 'payments.view', 'support.manage']);
        render(<Dashboard {...props} alerts={everyAlert} />);
        expect(viewLinks()).toHaveLength(3);
        expect(screen.getByTestId('dashboard-alerts')).toBeInTheDocument();
    });

    it('shows no alert row at all to an admin who may open none of the pages', () => {
        grant(['dashboard.view']);
        render(<Dashboard {...props} alerts={everyAlert} />);

        expect(screen.queryByText('Stuck trips')).toBeNull();
        expect(screen.queryByText('Failed payments')).toBeNull();
        expect(viewLinks()).toEqual([]);
        expect(screen.queryByTestId('dashboard-alerts')).toBeNull();
    });

    it('shows no card for an alert the server left out, even when the permission is held', () => {
        grant(['dashboard.view', 'trips.view', 'payments.view']);
        render(<Dashboard {...props} alerts={{ stuck_trips: 3 }} />);

        expect(screen.getByText('Stuck trips')).toBeInTheDocument();
        expect(screen.queryByText('Failed payments')).toBeNull();
    });
});
