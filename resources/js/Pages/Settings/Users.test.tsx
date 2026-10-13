import { router } from '@inertiajs/core';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import SettingsUsers from '@/Pages/Settings/Users';
import { page, sharedProps } from '@/test/inertia';
import type { AdminUserRow, Paginated } from '@/types';

vi.mock('@inertiajs/react', async (importOriginal) => (await import('@/test/inertia')).inertiaMock(importOriginal));

const user = (over: Partial<AdminUserRow>): AdminUserRow => ({ id: 2, name: 'Sam Support', email: 'sam@x.test', roles: ['support'], status: 'active', last_login_at: null, two_factor_enabled: true, ...over });
const paginator = (data: AdminUserRow[]): Paginated<AdminUserRow> => ({ data, current_page: 1, last_page: 1, per_page: 10, total: data.length, from: 1, to: data.length, path: '/admin/settings/users', links: [] });
const base = { roles: [{ name: 'support', label: 'Support' }, { name: 'super_admin', label: 'Super Admin' }], matrix: {}, actions: { invite: null, remove: null, resetTwoFactor: '/admin/settings/users/{id}/two-factor/reset' } };

describe('Settings → Users → two-factor', () => {
    beforeEach(() => { page.props = sharedProps({ auth: { user: { id: 1, name: 'Sue Super', email: 'sue@x.test', role: 'super_admin', roleLabel: 'Super Admin' }, permissions: ['admins.manage'] } }); });
    afterEach(() => vi.restoreAllMocks());

    it('shows who has two-factor on', () => {
        render(<SettingsUsers {...base} users={paginator([user({}), user({ id: 3, name: 'Dan Dispatch', email: 'dan@x.test', two_factor_enabled: false })])} />);

        expect(screen.getAllByText('Enabled')).toHaveLength(1);
        expect(screen.getAllByText('Disabled')).toHaveLength(1);
    });

    it('resets another admin\'s two-factor with the super admin\'s password and a reason', () => {
        const visit = vi.spyOn(router, 'visit').mockImplementation(() => {});
        render(<SettingsUsers {...base} users={paginator([user({})])} />);

        fireEvent.click(screen.getByRole('button', { name: 'Reset two-factor for Sam Support' }));
        const dialog = screen.getByRole('dialog', { name: 'Reset two-factor authentication' });
        const confirm = within(dialog).getByRole('button', { name: 'Reset two-factor' });
        expect(confirm).toBeDisabled();

        fireEvent.change(within(dialog).getByLabelText(/Current password/), { target: { value: 'secret-pass' } });
        fireEvent.change(within(dialog).getByLabelText(/Reason/), { target: { value: 'Lost phone' } });
        fireEvent.click(confirm);

        expect(visit).toHaveBeenCalledTimes(1);
        expect(visit.mock.calls[0][0]).toBe('/admin/settings/users/2/two-factor/reset');
        expect(visit.mock.calls[0][1]).toMatchObject({ method: 'post', data: { password: 'secret-pass', reason: 'Lost phone' } });
    });

    it('offers no reset for yourself, for someone without two-factor, or without the action', () => {
        const { rerender } = render(<SettingsUsers {...base} users={paginator([user({ id: 1, name: 'Sue Super' }), user({ id: 3, name: 'Dan Dispatch', two_factor_enabled: false })])} />);
        expect(screen.queryByRole('button', { name: /Reset two-factor for/ })).toBeNull();

        rerender(<SettingsUsers {...base} actions={{ ...base.actions, resetTwoFactor: null }} users={paginator([user({})])} />);
        expect(screen.queryByRole('button', { name: /Reset two-factor for/ })).toBeNull();
    });
});
