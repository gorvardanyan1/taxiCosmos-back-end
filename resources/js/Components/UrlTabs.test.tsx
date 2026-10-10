import { router } from '@inertiajs/core';
import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import UrlTabs from '@/Components/UrlTabs';
import { setLocation } from '@/test/inertia';

describe('UrlTabs', () => {
    let get: ReturnType<typeof vi.spyOn>;
    beforeEach(() => {
        get = vi.spyOn(router, 'get').mockImplementation(() => {});
        setLocation('/admin/drivers/8');
    });
    afterEach(() => vi.restoreAllMocks());

    it('marks the active tab from props and switches tabs through the URL', () => {
        render(<UrlTabs tabs={['profile', 'documents', 'bank-accounts']} active="profile" labels={{ 'bank-accounts': 'Bank accounts' }} />);

        expect(screen.getByRole('tab', { name: 'Profile' })).toHaveAttribute('aria-selected', 'true');
        expect(screen.getByRole('tab', { name: 'Bank accounts' })).toHaveAttribute('aria-selected', 'false');

        fireEvent.click(screen.getByRole('tab', { name: 'Documents' }));
        expect(get).toHaveBeenCalledWith('/admin/drivers/8?tab=documents', {}, { preserveState: true, preserveScroll: true });
    });

    it('does not navigate when the active tab is clicked again', () => {
        render(<UrlTabs tabs={['profile', 'documents']} active="profile" />);

        fireEvent.click(screen.getByRole('tab', { name: 'Profile' }));
        expect(get).not.toHaveBeenCalled();
    });
});
