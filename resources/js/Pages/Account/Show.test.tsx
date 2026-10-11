import { router } from '@inertiajs/core';
import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import AccountShow from '@/Pages/Account/Show';
import { setDictionary } from '@/lib/i18n';
import { page, sharedProps } from '@/test/inertia';

vi.mock('@inertiajs/react', async (importOriginal) => (await import('@/test/inertia')).inertiaMock(importOriginal));

const locales = [{ code: 'en', name: 'English' }, { code: 'hy', name: 'Հայերեն' }, { code: 'ru', name: 'Русский' }];
const actions = { updateProfile: null, updatePassword: null, regenerateRecoveryCodes: null, logoutOtherSessions: null, updateNotifications: null, updateLocale: '/admin/account/locale' };
const props = {
    tab: 'profile', tabs: ['profile', 'security', 'notifications'], profile: { name: 'Sky', email: 'sky@x.test', locale: 'en', timezone: 'Asia/Yerevan' },
    two_factor_enabled: false, sessions: [], notification_preferences: [], locales, actions,
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
