import { router } from '@inertiajs/core';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import FilterBar from '@/Components/FilterBar';
import { setLocation } from '@/test/inertia';

describe('FilterBar', () => {
    let get: ReturnType<typeof vi.spyOn>;

    beforeEach(() => {
        vi.useFakeTimers();
        get = vi.spyOn(router, 'get').mockImplementation(() => {});
        setLocation('/admin/riders?page=3&sort=name');
    });
    afterEach(() => {
        vi.useRealTimers();
        vi.restoreAllMocks();
    });

    const selects = [{ key: 'status', allLabel: 'All Statuses', options: [{ value: 'active', label: 'Active' }, { value: 'suspended', label: 'Suspended' }] }];

    it('puts the search term in the URL after typing pauses, keeps the sort and resets the page', () => {
        render(<FilterBar filters={{}} selects={selects} />);

        fireEvent.change(screen.getByLabelText('Search'), { target: { value: ' sun ' } });
        expect(get).not.toHaveBeenCalled();

        act(() => vi.advanceTimersByTime(400));

        expect(get).toHaveBeenCalledTimes(1);
        expect(get).toHaveBeenCalledWith('/admin/riders?sort=name&filter%5Bsearch%5D=sun', {}, { preserveState: true, preserveScroll: true });
    });

    it('submits immediately on Enter', () => {
        render(<FilterBar filters={{}} />);
        const input = screen.getByLabelText('Search');

        fireEvent.change(input, { target: { value: 'oliver' } });
        fireEvent.keyDown(input, { key: 'Enter' });

        expect(get).toHaveBeenCalledWith('/admin/riders?sort=name&filter%5Bsearch%5D=oliver', {}, expect.anything());
    });

    it('applies a select filter immediately and shows the current value from the URL', () => {
        render(<FilterBar filters={{ status: 'active' }} selects={selects} />);
        const select = screen.getByLabelText('All Statuses') as HTMLSelectElement;
        expect(select.value).toBe('active');

        fireEvent.change(select, { target: { value: 'suspended' } });

        expect(get).toHaveBeenCalledWith('/admin/riders?sort=name&filter%5Bstatus%5D=suspended', {}, expect.anything());
    });

    it('clears every active filter but keeps unrelated params', () => {
        setLocation('/admin/riders?filter%5Bstatus%5D=active&filter%5Bsearch%5D=sun&sort=name');
        render(<FilterBar filters={{ status: 'active', search: 'sun' }} selects={selects} />);

        fireEvent.click(screen.getByText('Clear filters'));

        expect(get).toHaveBeenCalledWith('/admin/riders?sort=name', {}, expect.anything());
    });

    it('hides "Clear filters" when nothing is filtered and does not navigate on mount', () => {
        render(<FilterBar filters={{}} selects={selects} />);
        act(() => vi.advanceTimersByTime(1000));

        expect(screen.queryByText('Clear filters')).toBeNull();
        expect(get).not.toHaveBeenCalled();
    });
});
