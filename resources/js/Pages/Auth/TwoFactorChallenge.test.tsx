import { router } from '@inertiajs/core';
import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import TwoFactorChallenge from '@/Pages/Auth/TwoFactorChallenge';

vi.mock('@inertiajs/react', async (importOriginal) => (await import('@/test/inertia')).inertiaMock(importOriginal));

describe('Two-factor challenge', () => {
    afterEach(() => vi.restoreAllMocks());

    it('sends the six digits of the authenticator code, digits only', () => {
        const visit = vi.spyOn(router, 'visit').mockImplementation(() => {});
        render(<TwoFactorChallenge submitUrl="/two-factor-challenge" />);

        fireEvent.change(screen.getByLabelText('Authentication code'), { target: { value: '12 34-56' } });
        fireEvent.click(screen.getByRole('button', { name: 'Verify code' }));

        expect(visit.mock.calls[0][0]).toBe('/two-factor-challenge');
        expect(visit.mock.calls[0][1]).toMatchObject({ method: 'post', data: { code: '123456', recovery_code: '' } });
    });

    it('switches to a recovery code, dropping a half-typed authenticator code, and back', () => {
        const visit = vi.spyOn(router, 'visit').mockImplementation(() => {});
        render(<TwoFactorChallenge submitUrl="/two-factor-challenge" />);

        fireEvent.change(screen.getByLabelText('Authentication code'), { target: { value: '123' } });
        fireEvent.click(screen.getByRole('button', { name: 'Use a recovery code instead' }));
        expect(screen.queryByLabelText('Authentication code')).toBeNull();
        fireEvent.change(screen.getByLabelText('Recovery code'), { target: { value: 'AAAAAAAAAA-BBBBBBBBBB' } });
        fireEvent.click(screen.getByRole('button', { name: 'Verify code' }));

        expect(visit.mock.calls[0][1]).toMatchObject({ data: { code: '', recovery_code: 'AAAAAAAAAA-BBBBBBBBBB' } });

        fireEvent.click(screen.getByRole('button', { name: 'Use an authentication code instead' }));
        expect((screen.getByLabelText('Authentication code') as HTMLInputElement).value).toBe('');
    });

    it('shows a wrong code next to the field', () => {
        vi.spyOn(router, 'visit').mockImplementation((_url, options) => {
            options?.onError?.({ code: 'The provided two factor authentication code was invalid.' });
            options?.onFinish?.({} as never);
        });
        render(<TwoFactorChallenge submitUrl="/two-factor-challenge" />);

        fireEvent.change(screen.getByLabelText('Authentication code'), { target: { value: '000000' } });
        fireEvent.click(screen.getByRole('button', { name: 'Verify code' }));

        expect(screen.getByRole('alert')).toHaveTextContent('The provided two factor authentication code was invalid.');
    });

    it('is disabled when the server gave no endpoint', () => {
        render(<TwoFactorChallenge submitUrl={null} />);

        expect(screen.getByRole('button', { name: 'Verify code' })).toBeDisabled();
    });
});
