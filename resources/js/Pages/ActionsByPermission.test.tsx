import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import DriversIndex from '@/Pages/Drivers/Index';
import DriverShow from '@/Pages/Drivers/Show';
import PayoutsIndex from '@/Pages/Payouts/Index';
import RidersIndex from '@/Pages/Riders/Index';
import RiderShow from '@/Pages/Riders/Show';
import SupportTicketsIndex from '@/Pages/SupportTickets/Index';
import TransactionsIndex from '@/Pages/Transactions/Index';
import TripShow from '@/Pages/Trips/Show';
import ZonesIndex from '@/Pages/Zones/Index';
import { grant, setLocation } from '@/test/inertia';
import type { DriverDetail, DriverRow, FareRule, Paginated, PayoutRow, RiderDetail, RiderRow, TicketDetail, TransactionRow, TripRow, Zone } from '@/types';

vi.mock('@inertiajs/react', async (importOriginal) => (await import('@/test/inertia')).inertiaMock(importOriginal));

/** P13-T2: a button the admin may not use is not on the page. The server refuses it too (PermissionMatrixTest). */
const paginator = <T,>(data: T[], path: string): Paginated<T> => ({ data, current_page: 1, last_page: 1, per_page: 10, total: data.length, from: 1, to: data.length, path, links: [] });
const amd = (amount: number) => ({ amount, currency: 'AMD' });
const button = (name: string | RegExp) => screen.queryByRole('button', { name });

beforeEach(() => setLocation('/admin'));
afterEach(() => vi.restoreAllMocks());

describe('Riders', () => {
    const rider: RiderRow = { id: 7, code: 'R-00007', name: 'Sun Li', phone: '+37491210443', email: 'sun@example.com', status: 'active', trips_count: 0, registered_at: '2026-01-12T10:00:00+00:00' };
    const listProps = { filters: {}, sort: null, statuses: ['active', 'suspended'], totalRegistered: 1, riders: paginator([rider], '/admin/riders'), actions: { export: null, suspend: '/admin/riders/{id}/suspend', reactivate: '/admin/riders/{id}/reactivate' } };
    const detail = {
        ...rider, raw: { name: 'Sun Li', email: 'sun@example.com', phone: '+37491210443', locale: 'en' }, locale: 'en', suspension_reason: null, last_login_at: null,
        stats: { total_spent: amd(0), cancellation_rate_bp: 0, open_tickets: 0 }, deletion_scheduled_for: null, payment_methods: [], records: { trips: [], payments: [], tickets: [], ratings: [], activity: [] },
    } as RiderDetail;
    const showProps = { rider: detail, tab: 'trips', tabs: ['trips'], locales: ['en'], actions: { edit: '/admin/riders/7', suspend: '/admin/riders/7/suspend', reactivate: '/admin/riders/7/reactivate' } };

    it('the list menu offers Suspend only with riders.suspend (View Profile stays)', () => {
        grant(['riders.view']);
        const without = render(<RidersIndex {...listProps} />);
        fireEvent.click(screen.getByRole('button', { name: 'Actions for Sun Li' }));
        expect(button(/View Profile/)).toBeInTheDocument();
        expect(button(/Suspend/)).toBeNull();
        without.unmount();

        grant(['riders.view', 'riders.suspend']);
        render(<RidersIndex {...listProps} />);
        fireEvent.click(screen.getByRole('button', { name: 'Actions for Sun Li' }));
        expect(button(/Suspend/)).toBeInTheDocument();
    });

    it('the detail page shows Edit only with riders.edit and Suspend only with riders.suspend', () => {
        grant(['riders.view']);
        const none = render(<RiderShow {...showProps} />);
        expect(button('Edit')).toBeNull();
        expect(button('Suspend')).toBeNull();
        none.unmount();

        grant(['riders.view', 'riders.edit']);
        const editOnly = render(<RiderShow {...showProps} />);
        expect(button('Edit')).toBeInTheDocument();
        expect(button('Suspend')).toBeNull();
        editOnly.unmount();

        grant(['riders.view', 'riders.suspend']);
        render(<RiderShow {...showProps} />);
        expect(button('Edit')).toBeNull();
        expect(button('Suspend')).toBeInTheDocument();
    });

    it('a suspended rider cannot be reactivated by an admin without riders.suspend', () => {
        grant(['riders.view', 'riders.edit']);
        render(<RiderShow {...showProps} rider={{ ...detail, status: 'suspended' }} />);

        expect(button('Reactivate')).toBeNull();
    });
});

describe('Drivers', () => {
    const row: DriverRow = { id: 7, code: 'D-0007', name: 'Gor Hakobyan', phone: '+37491440221', vehicle: null, verification_status: 'approved', availability: 'online', status: 'active', rating: '4.50', trips_count: 1, earnings: amd(1000) };
    const listProps = {
        filters: {}, sort: null, verificationStatuses: ['pending'], availabilities: ['online'], vehicleClasses: ['economy' as const], zones: [], totalRegistered: 1, drivers: paginator([row], '/admin/drivers'),
        actions: { export: null, create: null, suspend: '/admin/drivers/{id}/suspend', reactivate: '/admin/drivers/{id}/reactivate' },
    };
    const driver = {
        id: 9, code: 'DRV-0009', name: 'Gor Hakobyan', verification_status: 'pending', phone: '+374 99 661 009', vehicle: null, trips_count: 0, rating: null, earnings: amd(0), availability: 'offline', status: 'active',
        raw: { name: 'Gor Hakobyan', email: null, phone: '+374 99 661 009', license_expiry: null, home_zone_id: null }, license_last4: null, home_zone: null, suspension_reason: null,
        documents: [{ id: 1, type: 'license', status: 'pending', expires_at: null, rejection_reason: null }],
        vehicles: [{ id: 4, make: 'Toyota', model: 'Camry', year: 2020, plate_number: '10AA100', color: 'White', vehicle_class: 'economy', status: 'active', is_primary: false }],
        bank_accounts: [{ id: 5, bank_name: 'Ameria', account_last4: '1234', is_default: true }], trip_history: [],
        earnings_detail: { balance: amd(0), debt_limit_exceeded: false, summary: { week: amd(0), month: amd(0), all_time: amd(0) }, ledger: [] },
    } as unknown as DriverDetail;
    const urls = {
        edit: '/admin/drivers/9', suspend: '/admin/drivers/9/suspend', reactivate: '/admin/drivers/9/reactivate', approveDocument: '/admin/drivers/9/documents/{id}/approve', rejectDocument: '/admin/drivers/9/documents/{id}/reject',
        addVehicle: '/admin/drivers/9/vehicles', updateVehicle: '/admin/drivers/9/vehicles/{id}', setPrimaryVehicle: '/admin/drivers/9/vehicles/{id}/set-primary', addAdjustment: null, recordCashSettlement: null, revealBankAccount: '/admin/drivers/9/bank-accounts/{id}/reveal',
    };
    const show = (tab: string) => render(<DriverShow driver={driver} tab={tab} tabs={['profile', 'documents', 'vehicles', 'bank-accounts']} zones={[]} vehicleClasses={['economy']} actions={urls} />);

    it('the list menu offers Suspend only with drivers.suspend, and "+ Add Driver" only with drivers.edit', () => {
        grant(['drivers.view']);
        const without = render(<DriversIndex {...listProps} />);
        expect(button(/Add Driver/)).toBeNull();
        fireEvent.click(screen.getByRole('button', { name: 'Actions for Gor Hakobyan' }));
        expect(button(/View Detail/)).toBeInTheDocument();
        expect(button(/Suspend/)).toBeNull();
        without.unmount();

        grant(['drivers.view', 'drivers.suspend', 'drivers.edit']);
        render(<DriversIndex {...listProps} />);
        expect(button(/Add Driver/)).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Actions for Gor Hakobyan' }));
        expect(button(/Suspend/)).toBeInTheDocument();
    });

    it('the header shows Edit with drivers.edit and Suspend with drivers.suspend, each on its own', () => {
        grant(['drivers.view']);
        const none = show('profile');
        expect(button('Edit')).toBeNull();
        expect(button('Suspend')).toBeNull();
        none.unmount();

        grant(['drivers.view', 'drivers.edit']);
        const editOnly = show('profile');
        expect(button('Edit')).toBeInTheDocument();
        expect(button('Suspend')).toBeNull();
        editOnly.unmount();

        grant(['drivers.view', 'drivers.suspend']);
        show('profile');
        expect(button('Edit')).toBeNull();
        expect(button('Suspend')).toBeInTheDocument();
    });

    it('document review (Approve / Reject) needs drivers.verify', () => {
        grant(['drivers.view', 'drivers.edit', 'drivers.suspend']);
        const without = show('documents');
        expect(button(/Approve/)).toBeNull();
        expect(button(/Reject/)).toBeNull();
        without.unmount();

        grant(['drivers.view', 'drivers.verify']);
        show('documents');
        expect(button(/Approve/)).toBeInTheDocument();
        expect(button(/Reject/)).toBeInTheDocument();
    });

    it('vehicle writes (add, edit, set as primary) need drivers.edit', () => {
        grant(['drivers.view', 'drivers.verify', 'drivers.suspend']);
        const without = show('vehicles');
        expect(screen.getByText('Toyota Camry · 2020')).toBeInTheDocument();
        expect(button(/Add vehicle/)).toBeNull();
        expect(button('Edit Toyota Camry')).toBeNull();
        expect(button('Set as primary')).toBeNull();
        without.unmount();

        grant(['drivers.view', 'drivers.edit']);
        show('vehicles');
        expect(button(/Add vehicle/)).toBeInTheDocument();
        expect(button('Edit Toyota Camry')).toBeInTheDocument();
        expect(button('Set as primary')).toBeInTheDocument();
    });

    it('revealing a bank account number needs drivers.reveal_bank_account', () => {
        grant(['drivers.view', 'drivers.edit', 'drivers.verify', 'drivers.suspend']);
        const without = show('bank-accounts');
        expect(screen.getByText('•••• 1234')).toBeInTheDocument();
        expect(button(/Reveal/)).toBeNull();
        without.unmount();

        grant(['drivers.view', 'drivers.reveal_bank_account']);
        show('bank-accounts');
        expect(button(/Reveal/)).toBeInTheDocument();
    });
});

describe('Trip admin actions', () => {
    const trip: TripRow = { id: 4, code: 'TK-58200', rider: 'Ana', driver: 'Gor', pickup_address: 'A', dropoff_address: 'B', status: 'in_progress', fare: amd(2500), requested_at: '2026-10-05T14:16:00Z' };
    const actions = { forceCancel: '/admin/trips/4/force-cancel', adjustFare: '/admin/trips/4/adjust-fare', reassignDriver: '/admin/trips/4/reassign-driver' };

    it('Force Cancel needs trips.force_cancel; Adjust Fare needs trips.adjust_fare', () => {
        grant(['trips.view', 'trips.force_cancel']);
        const dispatcher = render(<TripShow trip={trip} actions={actions} />);
        expect(button('Force Cancel')).toBeInTheDocument();
        expect(button('Reassign Driver')).toBeInTheDocument();
        expect(button('Adjust Fare')).toBeNull();
        dispatcher.unmount();

        grant(['trips.view', 'trips.adjust_fare']);
        render(<TripShow trip={trip} actions={actions} />);
        expect(button('Force Cancel')).toBeNull();
        expect(button('Reassign Driver')).toBeNull();
        expect(button('Adjust Fare')).toBeInTheDocument();
    });

    it('shows no Admin Actions panel at all to someone with neither permission', () => {
        grant(['trips.view']);
        render(<TripShow trip={trip} actions={actions} />);

        expect(screen.queryByText('Admin Actions')).toBeNull();
        expect(button('Force Cancel')).toBeNull();
        expect(button('Adjust Fare')).toBeNull();
        expect(button('Reassign Driver')).toBeNull();
    });
});

describe('Payments', () => {
    const completed: TransactionRow = {
        id: 12, code: 'TXN-1', trip_code: 'TK-1', gateway: 'stripe', type: 'capture', amount: amd(12500), status: 'completed', created_at: '2026-10-05T14:16:00Z',
        gateway_reference: null, rider: 'R', payment_method: 'Visa', parent_code: null, failure_reason: null, metadata: {},
    };
    const pending: TransactionRow = { ...completed, id: 13, code: 'TXN-2', gateway: 'manual', type: 'charge', status: 'pending' };
    const props = {
        summary: { volume_today: amd(1), transactions_count: 2, pending_payouts: amd(1), refunds_today: amd(1) }, transactions: paginator([completed, pending], '/admin/transactions'), filters: {}, selected: null, gateways: ['stripe'],
        actions: { recordManual: '/admin/transactions/manual', refund: '/admin/transactions/{id}/refund', confirmManual: '/admin/transactions/{id}/confirm', rejectManual: '/admin/transactions/{id}/reject' },
    };

    it('Refund needs payments.refund; Record Manual Payment, Confirm and Reject need payments.manual', () => {
        grant(['payments.view']);
        const viewer = render(<TransactionsIndex {...props} />);
        expect(button(/Record Manual Payment/)).toBeNull();
        expect(button(/Refund/)).toBeNull();
        expect(button('Confirm')).toBeNull();
        expect(button('Reject')).toBeNull();
        viewer.unmount();

        grant(['payments.view', 'payments.refund']);
        const refunder = render(<TransactionsIndex {...props} />);
        expect(button(/Refund/)).toBeInTheDocument();
        expect(button(/Record Manual Payment/)).toBeNull();
        expect(button('Confirm')).toBeNull();
        refunder.unmount();

        grant(['payments.view', 'payments.manual']);
        render(<TransactionsIndex {...props} />);
        expect(button(/Record Manual Payment/)).toBeInTheDocument();
        expect(button('Confirm')).toBeInTheDocument();
        expect(button('Reject')).toBeInTheDocument();
        expect(button(/Refund/)).toBeNull();
    });
});

describe('Payouts', () => {
    const payout: PayoutRow = { id: 3, driver: 'Gor', bank_account: { bank_name: 'Ameria', account_last4: '1234' }, period_start: '2026-10-05', period_end: '2026-10-11', amount: amd(50000), status: 'pending' };
    const props = {
        periods: [], summary: { drivers_in_batch: 1, total_amount: amd(50000), skipped_count: 0 }, payouts: paginator([payout], '/admin/payouts'), filters: {}, statuses: ['pending'], skipped: [],
        actions: { exportBankCsv: '/admin/payouts/export', approveBatch: '/admin/payouts/approve-batch', approve: '/admin/payouts/{id}/approve', reject: '/admin/payouts/{id}/reject' },
    };

    it('Approve batch, Approve and Reject need payouts.approve; viewing and exporting need only payouts.view', () => {
        grant(['payouts.view']);
        const viewer = render(<PayoutsIndex {...props} />);
        expect(button(/Export CSV for bank/)).toBeInTheDocument();
        expect(button('Approve batch')).toBeNull();
        expect(button('Approve')).toBeNull();
        expect(button('Reject')).toBeNull();
        viewer.unmount();

        grant(['payouts.view', 'payouts.approve']);
        render(<PayoutsIndex {...props} />);
        expect(button('Approve batch')).toBeInTheDocument();
        expect(button('Approve')).toBeInTheDocument();
        expect(button('Reject')).toBeInTheDocument();
    });
});

describe('Support tickets', () => {
    const ticket: TicketDetail = {
        id: 1, code: 'TKT-1', subject: 'Overcharged', category: 'fare', priority: 'urgent', status: 'open', assignee: null, requester: { name: 'Ana', type: 'rider', code: 'R-1' }, created_at: '2026-10-05T14:16:00Z', last_message_at: '2026-10-05T14:16:00Z',
        resolution: null, resolved_at: null, messages: [{ id: 1, kind: 'requester', author: 'Ana', body: 'Too much', created_at: '2026-10-05T14:16:00Z' }], linked_trip: { id: 4, code: 'TK-58200' },
    };
    const props = {
        tickets: paginator([ticket], '/admin/support-tickets'), filters: {}, selected: ticket, statuses: [], categories: [], priorities: [], assignees: [], me: 1,
        actions: { assign: null, resolve: null, reply: null, reopen: null, refund: '/admin/support-tickets/{id}/refund', adjustFare: '/admin/support-tickets/{id}/adjust-fare' },
    };

    it('"Issue refund" from a ticket needs payments.refund on top of support.manage', () => {
        grant(['support.manage']);
        const support = render(<SupportTicketsIndex {...props} />);
        expect(screen.getByText('TK-58200')).toBeInTheDocument();
        expect(button(/Issue refund/)).toBeNull();
        expect(button(/Adjust fare/)).toBeNull();
        support.unmount();

        grant(['support.manage', 'payments.refund', 'trips.adjust_fare']);
        render(<SupportTicketsIndex {...props} />);
        expect(button(/Issue refund/)).toBeInTheDocument();
        expect(button(/Adjust fare/)).toBeInTheDocument();
    });
});

describe('Zones & fares', () => {
    const zone: Zone = { id: 1, name: 'Downtown', code: 'DOWNTOWN', status: 'active', polygon_valid: true, timezone: 'Asia/Yerevan', currency: 'AMD', currency_locked: false, priority: 5, points: 5 };
    const rule: FareRule = {
        id: 11, zone_id: 1, vehicle_class: 'economy', base_fare: amd(500), per_km: amd(150), per_minute: amd(20), min_fare: amd(800), waiting_free_minutes: 3, waiting_per_minute: amd(30),
        cancellation_fee: amd(500), cancellation_free_seconds: 120, booking_fee: amd(0), tax_rate_bps: 0, tax_inclusive: false, effective_from: '2026-10-01T08:00:00+00:00', effective_to: null, created_by: null,
    };
    const props = {
        zones: [zone], selectedZoneId: 1, selectedPolygon: { type: 'Polygon' as const, coordinates: [[[44.4, 40.1], [44.6, 40.1], [44.6, 40.3], [44.4, 40.3], [44.4, 40.1]]] }, fareRules: [rule], fareHistory: null,
        vehicleClasses: ['economy' as const, 'comfort' as const], timezones: ['Asia/Yerevan'], currencies: ['AMD'],
        actions: { createZone: '/admin/zones', updateZone: '/admin/zones/{id}', deactivateZone: '/admin/zones/{id}/deactivate', activateZone: '/admin/zones/{id}/activate', saveFareRule: '/admin/zones/{id}/fare-rules', drawPolygon: null },
    };

    it('the fare buttons need fares.manage even for an admin who manages zones', () => {
        grant(['zones.manage']);
        const zonesOnly = render(<ZonesIndex {...props} />);
        expect(screen.getByRole('button', { name: /Downtown/ })).toBeInTheDocument();
        expect(button(/Add fare rule/)).toBeNull();
        expect(button('New version of Economy')).toBeNull();
        zonesOnly.unmount();

        grant(['zones.manage', 'fares.manage']);
        render(<ZonesIndex {...props} />);
        expect(button(/Add fare rule/)).toBeInTheDocument();
        expect(button('New version of Economy')).toBeInTheDocument();
    });
});
