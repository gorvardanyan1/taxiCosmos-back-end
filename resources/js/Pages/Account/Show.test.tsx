import { router } from '@inertiajs/core';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import AccountShow from '@/Pages/Account/Show';
import { setDictionary } from '@/lib/i18n';
import { page, sharedProps } from '@/test/inertia';

vi.mock('@inertiajs/react', async (importOriginal) => (await import('@/test/inertia')).inertiaMock(importOriginal));

const locales = [{ code: 'en', name: 'English' }, { code: 'hy', name: 'Հայերեն' }, { code: 'ru', name: 'Русский' }];
const actions = { updateProfile: null, updatePassword: null, enableTwoFactor: null, disableTwoFactor: null, regenerateRecoveryCodes: null, logoutOtherSessions: null, updateNotifications: null, updateLocale: '/admin/account/locale' };
const props = {
    tab: 'profile', tabs: ['profile', 'security', 'notifications'], profile: { name: 'Sky', email: 'sky@x.test', locale: 'en', timezone: 'Asia/Yerevan' },
    two_factor_enabled: false, two_factor_required: false, recovery_codes_left: 0, new_recovery_codes: null, sessions: [], notification_preferences: [], locales, actions,
};

describe('My Account → language', () => {
    beforeEach(() => { page.props = sharedProps(); });
    afterEach(() => { vi.restoreAllMocks(); setDictionary('en', {}); });

    it('lists the languages by their own names with the current one selected, and Save is off until it changes', () => {
        render(<AccountShow {...props} />);

        const select = screen.getByLabelText('Admin panel language') as HTMLSelectElement;
        expect(Array.from(select.options).map((o) => [o.value, o.text])).toEqual([['en', 'English'], ['hy', 'Հայերեն'], ['ru', 'Русский']]);
        expect(select.value).toBe('en');
        expect(screen.getByRole('button', { name: 'Save language' })).toBeDisabled();
    });

    it('sends the chosen language to the server', () => {
        const patch = vi.spyOn(router, 'visit').mockImplementation(() => {});
        render(<AccountShow {...props} />);

        fireEvent.change(screen.getByLabelText('Admin panel language'), { target: { value: 'ru' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save language' }));

        expect(patch).toHaveBeenCalledTimes(1);
        const [url, options] = patch.mock.calls[0];
        expect(url).toBe('/admin/account/locale');
        expect(options).toMatchObject({ method: 'patch', data: { locale: 'ru' } });
    });

    it('sends one request for a double-click', () => {
        const patch = vi.spyOn(router, 'visit').mockImplementation(() => {});
        render(<AccountShow {...props} />);

        fireEvent.change(screen.getByLabelText('Admin panel language'), { target: { value: 'hy' } });
        const save = screen.getByRole('button', { name: 'Save language' });
        fireEvent.click(save);
        fireEvent.click(save);

        expect(patch).toHaveBeenCalledTimes(1);
    });

    it('shows the server\'s error next to the field', () => {
        vi.spyOn(router, 'visit').mockImplementation((_url, options) => {
            options?.onError?.({ locale: 'The selected locale is invalid.' });
            options?.onFinish?.({} as never);
        });
        render(<AccountShow {...props} />);

        fireEvent.change(screen.getByLabelText('Admin panel language'), { target: { value: 'ru' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save language' }));

        expect(screen.getByRole('alert')).toHaveTextContent('The selected locale is invalid.');
    });

    it('is disabled when the server gave no endpoint', () => {
        render(<AccountShow {...props} actions={{ ...actions, updateLocale: null }} />);

        fireEvent.change(screen.getByLabelText('Admin panel language'), { target: { value: 'ru' } });
        expect(screen.getByRole('button', { name: 'Save language' })).toBeDisabled();
    });

    it('renders the page in Russian when that is the loaded language', () => {
        setDictionary('ru', {
            'My Account': 'Мой аккаунт', Language: 'Язык', 'Admin panel language': 'Язык панели администратора', 'Save language': 'Сохранить язык',
            'The language of the admin panel, its messages and the emails we send you.': 'Язык панели администратора, её сообщений и писем, которые мы вам отправляем.',
        });
        render(<AccountShow {...props} profile={{ ...props.profile, locale: 'ru' }} />);

        expect(screen.getByRole('heading', { name: 'Язык' })).toBeInTheDocument();
        expect(screen.getByLabelText('Язык панели администратора')).toHaveValue('ru');
        expect(screen.getByRole('button', { name: 'Сохранить язык' })).toBeDisabled();
    });
});

describe('My Account → two-factor authentication', () => {
    beforeEach(() => { page.props = sharedProps(); });
    afterEach(() => vi.restoreAllMocks());

    const security = { ...props, tab: 'security' };
    const on = { two_factor_enabled: true, recovery_codes_left: 6 };
    const urls = { enableTwoFactor: null, disableTwoFactor: '/admin/account/two-factor', regenerateRecoveryCodes: '/admin/account/two-factor/recovery-codes' };

    it('offers Enable while it is off, with no recovery codes and no Disable', () => {
        render(<AccountShow {...security} actions={{ ...actions, enableTwoFactor: '/two-factor-setup' }} />);

        expect(screen.getByText('Disabled')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Enable two-factor' })).toBeEnabled();
        expect(screen.queryByRole('button', { name: 'Regenerate recovery codes' })).toBeNull();
        expect(screen.queryByRole('button', { name: 'Disable two-factor' })).toBeNull();
    });

    it('asks for the password before starting the setup', () => {
        const visit = vi.spyOn(router, 'visit').mockImplementation(() => {});
        render(<AccountShow {...security} actions={{ ...actions, enableTwoFactor: '/two-factor-setup' }} />);

        fireEvent.click(screen.getByRole('button', { name: 'Enable two-factor' }));
        const dialog = screen.getByRole('dialog', { name: 'Enable two-factor authentication' });
        fireEvent.change(within(dialog).getByLabelText(/Current password/), { target: { value: 'secret-pass' } });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Continue' }));

        expect(visit.mock.calls[0][0]).toBe('/two-factor-setup');
        expect(visit.mock.calls[0][1]).toMatchObject({ method: 'post', data: { password: 'secret-pass' } });
    });

    it('shows the state and how many recovery codes are left, with the plural', () => {
        const { rerender } = render(<AccountShow {...security} {...on} recovery_codes_left={1} actions={{ ...actions, ...urls }} />);
        expect(screen.getByText('Enabled')).toBeInTheDocument();
        expect(screen.getByText('1 recovery code left')).toBeInTheDocument();

        rerender(<AccountShow {...security} {...on} actions={{ ...actions, ...urls }} />);
        expect(screen.getByText('6 recovery codes left')).toBeInTheDocument();
    });

    it('disables it by DELETE with the password', () => {
        const visit = vi.spyOn(router, 'visit').mockImplementation(() => {});
        render(<AccountShow {...security} {...on} actions={{ ...actions, ...urls }} />);

        fireEvent.click(screen.getByRole('button', { name: 'Disable two-factor' }));
        const dialog = screen.getByRole('dialog', { name: 'Disable two-factor authentication' });
        fireEvent.change(within(dialog).getByLabelText(/Current password/), { target: { value: 'secret-pass' } });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Disable two-factor' }));

        expect(visit.mock.calls[0][0]).toBe('/admin/account/two-factor');
        expect(visit.mock.calls[0][1]).toMatchObject({ method: 'delete', data: { password: 'secret-pass' } });
    });

    it('has no Disable button for a role that must have it, and says so', () => {
        render(<AccountShow {...security} {...on} two_factor_required actions={{ ...actions, ...urls, disableTwoFactor: null }} />);

        expect(screen.queryByRole('button', { name: 'Disable two-factor' })).toBeNull();
        expect(screen.getByText('Your role requires two-factor authentication.')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Regenerate recovery codes' })).toBeEnabled();
    });

    it('regenerates the recovery codes behind the password', () => {
        const visit = vi.spyOn(router, 'visit').mockImplementation(() => {});
        render(<AccountShow {...security} {...on} actions={{ ...actions, ...urls }} />);

        fireEvent.click(screen.getAllByRole('button', { name: 'Regenerate recovery codes' })[0]);
        const dialog = screen.getByRole('dialog', { name: 'Regenerate recovery codes' });
        fireEvent.change(within(dialog).getByLabelText(/Current password/), { target: { value: 'secret-pass' } });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Regenerate recovery codes' }));

        expect(visit.mock.calls[0][0]).toBe('/admin/account/two-factor/recovery-codes');
        expect(visit.mock.calls[0][1]).toMatchObject({ method: 'post', data: { password: 'secret-pass' } });
    });

    it('shows the new recovery codes once, in a dialog that can be closed', () => {
        const codes = ['AAAAAAAAAA-BBBBBBBBBB', 'CCCCCCCCCC-DDDDDDDDDD'];
        render(<AccountShow {...security} {...on} new_recovery_codes={codes} actions={{ ...actions, ...urls }} />);

        const dialog = screen.getByRole('dialog', { name: 'Your new recovery codes' });
        codes.forEach((code) => expect(within(dialog).getByText(code)).toBeInTheDocument());
        expect(within(dialog).getByText(/this is the only time they are shown/)).toBeInTheDocument();

        fireEvent.click(within(dialog).getByRole('button', { name: 'Done' }));
        expect(screen.queryByRole('dialog', { name: 'Your new recovery codes' })).toBeNull();
    });

    it('shows no codes dialog when there are none', () => {
        render(<AccountShow {...security} {...on} actions={{ ...actions, ...urls }} />);

        expect(screen.queryByRole('dialog')).toBeNull();
    });
});
