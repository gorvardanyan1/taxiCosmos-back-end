import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import DriverShow from '@/Pages/Drivers/Show';
import { page, sharedProps } from '@/test/inertia';
import type { DriverDetail } from '@/types';

vi.mock('@inertiajs/react', async (importOriginal) => (await import('@/test/inertia')).inertiaMock(importOriginal));

const zero = { amount: 0, currency: 'AMD' };
/** What DriverDetailPresenter sends for a database driver with no vehicle, trips or bank account yet. */
const realDriver: DriverDetail = {
    id: 26, code: 'D-0026', name: 'QA Driver One', phone: '+37491440221', vehicle: null, verification_status: 'pending',
    rating: null, trips_count: 0, earnings: zero, documents: [], vehicles: [], trip_history: [], bank_accounts: [],
    earnings_detail: { balance: zero, debt_limit_exceeded: false, summary: { week: zero, month: zero, all_time: zero }, ledger: [] },
};

const tabs = ['profile', 'documents', 'vehicles', 'earnings', 'trip-history', 'bank-accounts'];
const actions = { approveDocument: null, rejectDocument: null, addVehicle: null, setPrimaryVehicle: null, addAdjustment: null, recordCashSettlement: null, revealBankAccount: null };

describe('Driver detail for a driver without vehicle, trips or accounts', () => {
    beforeEach(() => { page.props = sharedProps(); });

    it.each(tabs)('renders the %s tab instead of crashing', (tab) => {
        render(<DriverShow driver={realDriver} tab={tab} tabs={tabs} actions={actions} />);

        expect(screen.getByRole('heading', { name: 'QA Driver One' })).toBeInTheDocument();
        expect(screen.getByText('D-0026')).toBeInTheDocument();
    });

    it('shows a dash for a missing vehicle and rating on the profile', () => {
        render(<DriverShow driver={realDriver} tab="profile" tabs={tabs} actions={actions} />);

        expect(screen.getByText('+37491440221')).toBeInTheDocument();
        expect(screen.getByText('Vehicle').nextElementSibling).toHaveTextContent('—');
        expect(screen.getByText('Rating').nextElementSibling).toHaveTextContent('—');
    });

    it('shows the primary vehicle when there is one', () => {
        render(<DriverShow driver={{ ...realDriver, vehicle: { label: 'Toyota Camry', year: 2020 } }} tab="profile" tabs={tabs} actions={actions} />);

        expect(screen.getByText('Toyota Camry ’20')).toBeInTheDocument();
    });
});
