import { router } from '@inertiajs/core';
import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import ActivityLogIndex from '@/Pages/ActivityLog/Index';
import { page, setLocation, sharedProps } from '@/test/inertia';
import type { ActivityEntry, Paginated } from '@/types';

vi.mock('@inertiajs/react', async (importOriginal) => (await import('@/test/inertia')).inertiaMock(importOriginal));

const entry = (overrides: Partial<ActivityEntry>): ActivityEntry => ({
    id: 1, occurred_at: '2026-10-05T14:20:00+00:00', actor: { id: 7, name: 'Morgan Webb', role: 'finance' }, action: 'driver.document.rejected',
    target_type: 'driver_document', target_id: 3, target: 'D-3041 / Insurance', reason: 'Expired policy', ip: '10.10.4.21', user_agent: 'QA',
    before: { status: 'pending', document_number: 'changed' }, after: { status: 'rejected', document_number: 'changed' }, ...overrides,
});

const paginator = (data: ActivityEntry[]): Paginated<ActivityEntry> => ({ data, current_page: 1, last_page: 1, per_page: 10, total: data.length, from: 1, to: data.length, path: '/admin/activity-logs', links: [] });

const base = {
    filters: {}, targetTypes: ['driver', 'driver_document'], actors: [{ id: 7, name: 'Morgan Webb' }], actionNames: ['driver.document.rejected', 'auth.login'],
    expandedId: null, expanded: null, actions: { export: '/admin/activity-logs/export?filter%5Baction%5D=driver' },
};

describe('Activity Log page', () => {
    let get: ReturnType<typeof vi.spyOn>;
    beforeEach(() => { page.props = sharedProps(); get = vi.spyOn(router, 'get').mockImplementation(() => {}); setLocation('/admin/activity-logs?page=2'); });
    afterEach(() => vi.restoreAllMocks());

    it('lists entries with actor, action, target, reason and IP; system and failed-login actors without a role', () => {
        render(<ActivityLogIndex {...base} entries={paginator([entry({}), entry({ id: 2, actor: { id: null, name: 'System', role: null }, action: 'auth.login_failed', target: 'nobody@example.com', target_type: null, reason: null })])} />);

        expect(screen.getByText('Morgan Webb · Finance')).toBeInTheDocument();
        expect(screen.getByRole('cell', { name: 'driver.document.rejected' })).toBeInTheDocument();
        expect(screen.getByText('D-3041 / Insurance')).toBeInTheDocument();
        expect(screen.getByText('Expired policy')).toBeInTheDocument();
        expect(screen.getAllByText('10.10.4.21')).toHaveLength(2);
        expect(screen.getByText('System')).toBeInTheDocument();
        expect(screen.getByRole('cell', { name: 'auth.login_failed' })).toBeInTheDocument();
    });

    it('shows the empty state', () => {
        render(<ActivityLogIndex {...base} entries={paginator([])} />);

        expect(screen.getByText('No activity recorded')).toBeInTheDocument();
    });

    it('filters by actor, action and target type through the URL and resets the page', () => {
        render(<ActivityLogIndex {...base} entries={paginator([entry({})])} />);

        fireEvent.change(screen.getByLabelText('All actors'), { target: { value: '7' } });
        expect(get).toHaveBeenLastCalledWith('/admin/activity-logs?filter%5Bactor%5D=7', {}, { preserveState: true, preserveScroll: true });

        fireEvent.change(screen.getByLabelText('All actions'), { target: { value: 'auth.login' } });
        expect(get).toHaveBeenLastCalledWith('/admin/activity-logs?filter%5Baction%5D=auth.login', {}, { preserveState: true, preserveScroll: true });

        fireEvent.change(screen.getByLabelText('All target types'), { target: { value: 'driver_document' } });
        expect(get).toHaveBeenLastCalledWith('/admin/activity-logs?filter%5Btarget_type%5D=driver_document', {}, { preserveState: true, preserveScroll: true });
    });

    it('filters by date range and target id', () => {
        render(<ActivityLogIndex {...base} entries={paginator([entry({})])} />);

        fireEvent.change(screen.getByLabelText('From date'), { target: { value: '2026-10-01' } });
        expect(get).toHaveBeenLastCalledWith('/admin/activity-logs?filter%5Bfrom%5D=2026-10-01', {}, { preserveState: true, preserveScroll: true });

        fireEvent.change(screen.getByLabelText('To date'), { target: { value: '2026-10-05' } });
        expect(get).toHaveBeenLastCalledWith('/admin/activity-logs?filter%5Bto%5D=2026-10-05', {}, { preserveState: true, preserveScroll: true });

        const id = screen.getByLabelText('Target ID');
        fireEvent.change(id, { target: { value: '42' } });
        fireEvent.keyDown(id, { key: 'Enter' });
        expect(get).toHaveBeenLastCalledWith('/admin/activity-logs?filter%5Btarget_id%5D=42', {}, { preserveState: true, preserveScroll: true });
    });

    it('shows the active filters in the inputs and can clear them', () => {
        render(<ActivityLogIndex {...base} filters={{ from: '2026-10-01', actor: '7' }} entries={paginator([entry({})])} />);

        expect(screen.getByLabelText('From date')).toHaveValue('2026-10-01');
        expect(screen.getByLabelText('All actors')).toHaveValue('7');

        fireEvent.click(screen.getByRole('button', { name: 'Clear filters' }));
        expect(get).toHaveBeenLastCalledWith('/admin/activity-logs', {}, { preserveState: true, preserveScroll: true });
    });

    it('opens an entry from the table and shows its before/after diff, with secrets only as "changed"', () => {
        const rejected = entry({});
        render(<ActivityLogIndex {...base} entries={paginator([rejected])} expandedId={1} expanded={rejected} />);

        const diff = screen.getByLabelText('Changes for driver.document.rejected');
        expect(diff).toHaveTextContent('status: "pending"');
        expect(diff).toHaveTextContent('status: "rejected"');
        expect(diff.textContent).toContain('document_number: "changed"');

        // Clicking the open entry again closes it (the entry parameter leaves the URL; the page stays).
        fireEvent.click(screen.getByText('Expired policy'));
        expect(get).toHaveBeenLastCalledWith('/admin/activity-logs?page=2', {}, { preserveState: true, preserveScroll: true });
    });

    it('expands an entry that is not on the current page', () => {
        const elsewhere = entry({ id: 99, action: 'driver.verification.changed', before: { verification_status: 'pending' }, after: { verification_status: 'approved' } });
        render(<ActivityLogIndex {...base} entries={paginator([entry({})])} expandedId={99} expanded={elsewhere} />);

        expect(screen.getByLabelText('Changes for driver.verification.changed')).toHaveTextContent('verification_status: "approved"');
    });

    it('exports the current filter through the URL the server prepared', () => {
        render(<ActivityLogIndex {...base} entries={paginator([entry({})])} />);

        // A plain link: the export is a GET file download, not an Inertia form post.
        const link = screen.getByRole('link', { name: /Export CSV/ });
        expect(link).toHaveAttribute('href', '/admin/activity-logs/export?filter%5Baction%5D=driver');
        expect(link).toHaveAttribute('download');
    });
});
