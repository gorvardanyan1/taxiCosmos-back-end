import { router } from '@inertiajs/core';
import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import Login from '@/Pages/Auth/Login';

vi.mock('@inertiajs/react', async (importOriginal) => (await import('@/test/inertia')).inertiaMock(importOriginal));

describe('Login screen', () => {
    afterEach(() => vi.restoreAllMocks());

    const fill = () => {
        fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'morgan@taxikosmos.test' } });
        fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'Corr3ct-Horse!Battery' } });
    };

    it('posts email, password and remember to the login endpoint', () => {
        const visit = vi.spyOn(router, 'visit').mockImplementation(() => {});
        render(<Login status={null} submitUrl="/login" />);
        fill();
        fireEvent.click(screen.getByLabelText(/Remember me/));
        fireEvent.click(screen.getByRole('button', { name: 'Sign in' }));

        expect(visit).toHaveBeenCalledTimes(1);
        const [url, options] = visit.mock.calls[0];
        expect(url).toBe('/login');
        expect(options).toMatchObject({ method: 'post', data: { email: 'morgan@taxikosmos.test', password: 'Corr3ct-Horse!Battery', remember: true } });
    });

    it('shows the server error (wrong credentials or rate limit) and clears the password', () => {
        vi.spyOn(router, 'visit').mockImplementation((_url, options) => {
            options?.onError?.({ email: 'Too many login attempts. Please try again in 52 seconds.' });
            options?.onFinish?.({} as never);
        });
        render(<Login status={null} submitUrl="/login" />);
        fill();
        fireEvent.click(screen.getByRole('button', { name: 'Sign in' }));

        expect(screen.getByRole('alert')).toHaveTextContent('Too many login attempts. Please try again in 52 seconds.');
        expect((screen.getByLabelText('Password') as HTMLInputElement).value).toBe('');
        expect((screen.getByLabelText('Email') as HTMLInputElement).value).toBe('morgan@taxikosmos.test');
    });

    it('shows the status message, e.g. after an idle timeout', () => {
        render(<Login status="Your session expired after 60 minutes of inactivity. Please sign in again." submitUrl="/login" />);

        expect(screen.getByText('Your session expired after 60 minutes of inactivity. Please sign in again.')).toBeInTheDocument();
    });
});
