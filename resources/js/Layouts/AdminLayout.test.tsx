import { router } from '@inertiajs/core';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import AdminLayout from '@/Layouts/AdminLayout';
import { page, sharedProps } from '@/test/inertia';

vi.mock('@inertiajs/react', async (importOriginal) => (await import('@/test/inertia')).inertiaMock(importOriginal));

describe('AdminLayout', () => {
    beforeEach(() => {
        page.props = sharedProps();
        page.url = '/admin/drivers/8?tab=documents';
    });

    it('shows the signed-in admin’s real name and role in the sidebar and top bar', () => {
        page.props = sharedProps({ auth: { user: { id: 7, name: 'Morgan Webb', email: 'morgan@x.test', role: 'finance', roleLabel: 'Finance' }, permissions: [] } });
        render(<AdminLayout><p>content</p></AdminLayout>);

        const sidebar = screen.getByRole('complementary');
        // Footer card: initials, name and role label (the "Finance" nav group heading is separate).
        const footer = within(sidebar).getByText('Morgan Webb').closest('div.flex') as HTMLElement;
        expect(within(footer).getByText('MW')).toBeInTheDocument();
        expect(within(footer).getByText('Finance')).toBeInTheDocument();
        expect(screen.getAllByText('Morgan Webb')).toHaveLength(2);
        expect(screen.queryByText(/Jordan Avery/)).toBeNull();
    });

    it('has no template-only "Back to public website" link and links the queue monitor to Horizon', () => {
        render(<AdminLayout><p>content</p></AdminLayout>);

        expect(screen.queryByText(/Back to public website/)).toBeNull();
        expect(screen.getByText('Queue Monitor').closest('a')).toHaveAttribute('href', '/horizon');
    });

    it('marks the nav item for the current URL (including nested pages) and shows the breadcrumb', () => {
        render(<AdminLayout><p>content</p></AdminLayout>);

        expect(screen.getByRole('link', { name: /Drivers/ })).toHaveAttribute('aria-current', 'page');
        expect(screen.getByRole('link', { name: /Dashboard/ })).not.toHaveAttribute('aria-current');
        expect(screen.getAllByText('Drivers').length).toBeGreaterThanOrEqual(2);
    });

    it('shows sidebar badges from shared navigation props', () => {
        render(<AdminLayout><p>content</p></AdminLayout>);

        expect(within(screen.getByRole('link', { name: /Drivers/ })).getByText('4')).toBeInTheDocument();
    });

    it('turns Laravel flash messages into toasts', () => {
        page.props = sharedProps({ flash: { success: 'Rider suspended.', error: 'Gateway timeout.' } });
        render(<AdminLayout><p>content</p></AdminLayout>);

        const toasts = screen.getAllByRole('status');
        expect(toasts.map((t) => t.textContent)).toEqual(['Rider suspended.', 'Gateway timeout.']);
    });

    it('shows no toast without flash messages', () => {
        render(<AdminLayout><p>content</p></AdminLayout>);

        expect(screen.queryByRole('status')).toBeNull();
    });

    it('signs out with a POST to /logout from the profile menu', () => {
        const visit = vi.spyOn(router, 'visit').mockImplementation(() => {});
        render(<AdminLayout><p>content</p></AdminLayout>);

        fireEvent.click(within(screen.getByRole('banner')).getByText('Test Admin'));
        fireEvent.click(screen.getByRole('button', { name: /Sign out/ }));

        expect(visit).toHaveBeenCalledWith('/logout', expect.objectContaining({ method: 'post' }));
        visit.mockRestore();
    });
});
