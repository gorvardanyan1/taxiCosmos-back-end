import { router } from '@inertiajs/core';
import { fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import TwoFactorSetup from '@/Pages/Auth/TwoFactorSetup';

vi.mock('@inertiajs/react', async (importOriginal) => (await import('@/test/inertia')).inertiaMock(importOriginal));

const setup = { qr_svg: '<svg data-testid="qr"></svg>', secret: 'JBSWY3DPEHPK3PXP', recovery_codes: ['AAAAAAAAAA-BBBBBBBBBB', 'CCCCCCCCCC-DDDDDDDDDD'] };
const urls = { startUrl: '/two-factor-setup', submitUrl: '/two-factor-setup/confirm' };

describe('Two-factor setup', () => {
    afterEach(() => vi.restoreAllMocks());

    it('first asks for the password and shows no secret yet', () => {
        const visit = vi.spyOn(router, 'visit').mockImplementation(() => {});
        render(<TwoFactorSetup setup={null} required={false} {...urls} />);

        expect(screen.queryByText('JBSWY3DPEHPK3PXP')).toBeNull();
        expect(screen.queryByLabelText('Authentication code')).toBeNull();
        expect(screen.getByRole('button', { name: 'Generate QR code' })).toBeDisabled();

        fireEvent.change(screen.getByLabelText('Current password'), { target: { value: 'secret-pass' } });
        fireEvent.click(screen.getByRole('button', { name: 'Generate QR code' }));

        expect(visit).toHaveBeenCalledTimes(1);
        expect(visit.mock.calls[0][0]).toBe('/two-factor-setup');
        expect(visit.mock.calls[0][1]).toMatchObject({ method: 'post', data: { password: 'secret-pass' } });
    });

    it('shows a wrong password next to the field and empties it', () => {
        vi.spyOn(router, 'visit').mockImplementation((_url, options) => {
            options?.onError?.({ password: 'The password is incorrect.' });
            options?.onFinish?.({} as never);
        });
        render(<TwoFactorSetup setup={null} required={false} {...urls} />);

        fireEvent.change(screen.getByLabelText('Current password'), { target: { value: 'wrong-pass' } });
        fireEvent.click(screen.getByRole('button', { name: 'Generate QR code' }));

        expect(screen.getByRole('alert')).toHaveTextContent('The password is incorrect.');
        expect((screen.getByLabelText('Current password') as HTMLInputElement).value).toBe('');
    });

    it('says why it is shown: required for the role, or optional', () => {
        const { rerender } = render(<TwoFactorSetup setup={null} required {...urls} />);
        expect(screen.getByText('Your role requires two-factor authentication.')).toBeInTheDocument();

        rerender(<TwoFactorSetup setup={null} required={false} {...urls} />);
        expect(screen.queryByText('Your role requires two-factor authentication.')).toBeNull();
        expect(screen.getByText('Protect your account with a code from an authenticator app.')).toBeInTheDocument();
    });

    it('while pending shows the QR code, the secret and the recovery codes, and takes the code', () => {
        const visit = vi.spyOn(router, 'visit').mockImplementation(() => {});
        render(<TwoFactorSetup setup={setup} required={false} {...urls} />);

        expect(screen.getByTestId('qr')).toBeInTheDocument();
        expect(screen.getByText('JBSWY3DPEHPK3PXP')).toBeInTheDocument();
        setup.recovery_codes.forEach((code) => expect(screen.getByText(code)).toBeInTheDocument());
        expect(screen.queryByLabelText('Current password')).toBeNull();

        fireEvent.change(screen.getByLabelText('Authentication code'), { target: { value: '12ab3456' } });
        expect((screen.getByLabelText('Authentication code') as HTMLInputElement).value).toBe('123456');
        fireEvent.click(screen.getByLabelText('I have saved these codes'));
        fireEvent.click(screen.getByRole('button', { name: 'Enable two-factor' }));

        expect(visit.mock.calls[0][0]).toBe('/two-factor-setup/confirm');
        expect(visit.mock.calls[0][1]).toMatchObject({ method: 'post', data: { code: '123456', confirmed_saved_codes: true } });
    });

    it('shows a wrong code next to the field', () => {
        vi.spyOn(router, 'visit').mockImplementation((_url, options) => {
            options?.onError?.({ code: 'The provided two factor authentication code was invalid.' });
            options?.onFinish?.({} as never);
        });
        render(<TwoFactorSetup setup={setup} required={false} {...urls} />);

        fireEvent.change(screen.getByLabelText('Authentication code'), { target: { value: '000000' } });
        fireEvent.click(screen.getByRole('button', { name: 'Enable two-factor' }));

        expect(screen.getByRole('alert')).toHaveTextContent('The provided two factor authentication code was invalid.');
    });

    it('offers a way out: Sign out', () => {
        const visit = vi.spyOn(router, 'visit').mockImplementation(() => {});
        render(<TwoFactorSetup setup={null} required {...urls} />);

        fireEvent.click(screen.getByRole('button', { name: 'Sign out' }));

        expect(visit.mock.calls[0][0]).toBe('/logout');
        expect(visit.mock.calls[0][1]).toMatchObject({ method: 'post' });
    });

    // QA regression: a code pasted the way an authenticator app shows it ("123 456") was cut to five digits.
    it.each([['123 456'], [' 123456'], ['123-456'], ['1234567']])('takes the six digits of a pasted %j', async (pasted) => {
        const user = userEvent.setup();
        render(<TwoFactorSetup setup={setup} required={false} {...urls} />);

        await user.click(screen.getByLabelText('Authentication code'));
        await user.paste(pasted);

        expect((screen.getByLabelText('Authentication code') as HTMLInputElement).value).toBe('123456');
    });
});
