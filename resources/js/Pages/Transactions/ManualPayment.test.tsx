import { router } from '@inertiajs/core';
import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import TransactionsIndex from '@/Pages/Transactions/Index';
import { page, sharedProps } from '@/test/inertia';
import type { Paginated, TransactionRow } from '@/types';

vi.mock('@inertiajs/react', async (importOriginal) => (await import('@/test/inertia')).inertiaMock(importOriginal));

const transactions: Paginated<TransactionRow> = { data: [], current_page: 1, last_page: 1, per_page: 10, total: 0, from: null, to: null, path: '/admin/transactions', links: [] };
const props = (recordManual: string | null) => ({
    summary: { volume_today: { amount: 0, currency: 'AMD' }, transactions_count: 0, pending_payouts: { amount: 0, currency: 'AMD' }, refunds_today: { amount: 0, currency: 'AMD' } },
    transactions, filters: {}, selected: null, gateways: ['manual'],
    actions: { recordManual, refund: null, confirmManual: null, rejectManual: null },
});

describe('Record manual payment form', () => {
    beforeEach(() => { page.props = sharedProps(); });
    afterEach(() => vi.restoreAllMocks());

    const open = () => fireEvent.click(screen.getByRole('button', { name: /Record Manual Payment/ }));
    const fill = (amount: string, currency = 'AMD', note = 'Paid cash at office') => {
        fireEvent.change(screen.getByLabelText(/Amount/), { target: { value: amount } });
        fireEvent.change(screen.getByLabelText('Currency'), { target: { value: currency } });
        fireEvent.change(screen.getByLabelText(/Trip or rider/), { target: { value: 'TK-8F3K2' } });
        fireEvent.change(screen.getByLabelText(/Note \/ Reason/), { target: { value: note } });
    };
    const submit = () => screen.getByRole('button', { name: 'Record' });

    it('posts integer minor units with the currency and an idempotency key', () => {
        const visit = vi.spyOn(router, 'visit').mockImplementation(() => {});
        render(<TransactionsIndex {...props('/admin/transactions/manual')} />);
        open();
        fill('18,500');
        fireEvent.click(submit());

        expect(visit).toHaveBeenCalledTimes(1);
        const [url, options] = visit.mock.calls[0];
        expect(url).toBe('/admin/transactions/manual');
        expect(options?.method).toBe('post');
        const data = options?.data as Record<string, unknown>;
        expect(data).toMatchObject({ amount: 18500, currency: 'AMD', reference: 'TK-8F3K2', method: 'cash', note: 'Paid cash at office' });
        expect(typeof data.amount).toBe('number');
        expect(String(data.idempotency_key)).toMatch(/.{16,}/);
    });

    it('uses the selected currency’s decimals', () => {
        const visit = vi.spyOn(router, 'visit').mockImplementation(() => {});
        render(<TransactionsIndex {...props('/admin/transactions/manual')} />);
        open();
        fill('12.5', 'USD');
        fireEvent.click(submit());

        expect((visit.mock.calls[0][1]?.data as Record<string, unknown>).amount).toBe(1250);
    });

    it('rejects an invalid amount next to the field and keeps submit disabled', () => {
        render(<TransactionsIndex {...props('/admin/transactions/manual')} />);
        open();
        fill('12.5'); // AMD has no minor digits

        expect(screen.getByRole('alert')).toHaveTextContent('Enter a valid amount.');
        expect(submit()).toBeDisabled();
    });

    it('requires a note', () => {
        render(<TransactionsIndex {...props('/admin/transactions/manual')} />);
        open();
        fill('500', 'AMD', '  ');

        expect(submit()).toBeDisabled();
    });

    it('stays disabled until the endpoint exists', () => {
        render(<TransactionsIndex {...props(null)} />);
        open();
        fill('500');

        expect(submit()).toBeDisabled();
    });

    it('uses a fresh idempotency key each time the form is opened', () => {
        const visit = vi.spyOn(router, 'visit').mockImplementation(() => {});
        render(<TransactionsIndex {...props('/admin/transactions/manual')} />);
        open(); fill('100'); fireEvent.click(submit());
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        open(); fill('100'); fireEvent.click(submit());

        const keys = visit.mock.calls.map((call) => (call[1]?.data as Record<string, string>).idempotency_key);
        expect(keys).toHaveLength(2);
        expect(keys[0]).not.toBe(keys[1]);
    });
});
