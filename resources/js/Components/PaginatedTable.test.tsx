import { router } from '@inertiajs/core';
import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import PaginatedTable, { nextSort } from '@/Components/PaginatedTable';
import { setLocation } from '@/test/inertia';
import type { Paginated } from '@/types';

type Row = { id: number; name: string };

function paginator(overrides: Partial<Paginated<Row>> = {}): Paginated<Row> {
    return {
        data: [{ id: 11, name: 'Priya Mehta' }, { id: 12, name: 'Sun Li' }],
        current_page: 2, last_page: 2, per_page: 10, total: 12, from: 11, to: 12, path: '/admin/riders',
        links: [
            { url: '/admin/riders?filter%5Bstatus%5D=active&page=1', label: '&laquo; Previous', active: false },
            { url: '/admin/riders?filter%5Bstatus%5D=active&page=1', label: '1', active: false },
            { url: '/admin/riders?filter%5Bstatus%5D=active&page=2', label: '2', active: true },
            { url: null, label: 'Next &raquo;', active: false },
        ],
        ...overrides,
    };
}

const columns = [{ key: 'name', header: 'Name', render: (row: Row) => row.name }];

describe('PaginatedTable', () => {
    let get: ReturnType<typeof vi.spyOn>;
    beforeEach(() => { get = vi.spyOn(router, 'get').mockImplementation(() => {}); });
    afterEach(() => vi.restoreAllMocks());

    it('renders the server page and the range summary', () => {
        render(<PaginatedTable paginator={paginator()} columns={columns} rowKey={(r) => r.id} />);

        expect(screen.getByText('Priya Mehta')).toBeInTheDocument();
        expect(screen.getByText('Sun Li')).toBeInTheDocument();
        expect(screen.getByText('11–12')).toBeInTheDocument();
        expect(screen.getByText(/of 12/)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: '2' })).toHaveAttribute('aria-current', 'page');
    });

    it('navigates with the server link so filters stay in the URL', () => {
        render(<PaginatedTable paginator={paginator()} columns={columns} rowKey={(r) => r.id} />);

        fireEvent.click(screen.getByRole('button', { name: '1' }));
        expect(get).toHaveBeenLastCalledWith('/admin/riders?filter%5Bstatus%5D=active&page=1', {}, { preserveState: true, preserveScroll: true });

        fireEvent.click(screen.getByLabelText('Previous page'));
        expect(get).toHaveBeenCalledTimes(2);
    });

    it('disables next on the last page', () => {
        render(<PaginatedTable paginator={paginator()} columns={columns} rowKey={(r) => r.id} />);

        expect(screen.getByLabelText('Next page')).toBeDisabled();
        fireEvent.click(screen.getByLabelText('Next page'));
        expect(get).not.toHaveBeenCalled();
    });

    it('shows the empty state instead of a blank table', () => {
        render(<PaginatedTable paginator={paginator({ data: [], total: 0, from: null, to: null })} columns={columns} rowKey={(r) => r.id} empty={{ title: 'No riders found' }} />);

        expect(screen.getByText('No riders found')).toBeInTheDocument();
        expect(screen.getByText('0–0')).toBeInTheDocument();
    });

    it('supports row clicks', () => {
        const onRowClick = vi.fn();
        render(<PaginatedTable paginator={paginator()} columns={columns} rowKey={(r) => r.id} onRowClick={onRowClick} />);

        fireEvent.click(screen.getByText('Sun Li'));
        expect(onRowClick).toHaveBeenCalledWith({ id: 12, name: 'Sun Li' });
    });

    it('cycles a column sort: ascending, descending, off', () => {
        expect(nextSort(null, 'name')).toBe('name');
        expect(nextSort('name', 'name')).toBe('-name');
        expect(nextSort('-name', 'name')).toBeNull();
        expect(nextSort('-other', 'name')).toBe('name');
    });

    it('marks the sorted column for assistive technology and only sortable headers are buttons', () => {
        setLocation('/admin/riders?sort=-name');
        render(<PaginatedTable paginator={paginator()} rowKey={(r) => r.id} sort="-name" columns={[{ key: 'name', header: 'Name', sortKey: 'name', render: (r: Row) => r.name }, { key: 'plain', header: 'Plain', render: (r: Row) => r.id }]} />);

        expect(screen.getByRole('columnheader', { name: /Name/ })).toHaveAttribute('aria-sort', 'descending');
        expect(screen.getByRole('button', { name: /Name/ })).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Plain' })).toBeNull();
        expect(screen.getByRole('columnheader', { name: 'Plain' })).not.toHaveAttribute('aria-sort');
    });
});
