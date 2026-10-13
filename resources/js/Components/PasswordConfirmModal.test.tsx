import { router } from '@inertiajs/core';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import PasswordConfirmModal from '@/Components/PasswordConfirmModal';

const props = { open: true, onClose: () => {}, title: 'Disable two-factor authentication', description: 'Confirm your password.', confirmLabel: 'Disable' };

describe('PasswordConfirmModal', () => {
    afterEach(() => vi.restoreAllMocks());

    it('cannot be confirmed until the password is typed, and not at all without an endpoint', () => {
        const { rerender } = render(<PasswordConfirmModal {...props} url="/admin/account/two-factor" />);
        const confirm = screen.getByRole('button', { name: 'Disable' });

        expect(confirm).toBeDisabled();
        fireEvent.change(screen.getByLabelText(/Current password/), { target: { value: 'secret-pass' } });
        expect(confirm).toBeEnabled();

        rerender(<PasswordConfirmModal {...props} url={null} />);
        expect(screen.getByRole('button', { name: 'Disable' })).toBeDisabled();
    });

    it('sends only the password, by the given method', () => {
        const visit = vi.spyOn(router, 'visit').mockImplementation(() => {});
        render(<PasswordConfirmModal {...props} method="delete" url="/admin/account/two-factor" />);

        fireEvent.change(screen.getByLabelText(/Current password/), { target: { value: 'secret-pass' } });
        fireEvent.click(screen.getByRole('button', { name: 'Disable' }));

        expect(visit).toHaveBeenCalledTimes(1);
        const [url, options] = visit.mock.calls[0];
        expect(url).toBe('/admin/account/two-factor');
        expect(options).toMatchObject({ method: 'delete', data: { password: 'secret-pass' } });
        expect(options?.data).not.toHaveProperty('reason');
    });

    it('shows the server error next to the password and empties the field so it is typed again', () => {
        vi.spyOn(router, 'visit').mockImplementation((_url, options) => {
            options?.onError?.({ password: 'The password is incorrect.' });
            options?.onFinish?.({} as never);
        });
        render(<PasswordConfirmModal {...props} url="/admin/account/two-factor" />);

        fireEvent.change(screen.getByLabelText(/Current password/), { target: { value: 'wrong-pass' } });
        fireEvent.click(screen.getByRole('button', { name: 'Disable' }));

        expect(screen.getByRole('alert')).toHaveTextContent('The password is incorrect.');
        expect((screen.getByLabelText(/Current password/) as HTMLInputElement).value).toBe('');
        expect(screen.getByRole('button', { name: 'Disable' })).toBeDisabled();
    });

    it('asks for a reason too when told to, and sends both', () => {
        const visit = vi.spyOn(router, 'visit').mockImplementation(() => {});
        render(<PasswordConfirmModal {...props} confirmLabel="Reset two-factor" url="/admin/settings/users/7/two-factor/reset" reasonPlaceholder="Why?" />);
        const confirm = screen.getByRole('button', { name: 'Reset two-factor' });

        fireEvent.change(screen.getByLabelText(/Current password/), { target: { value: 'secret-pass' } });
        expect(confirm).toBeDisabled();
        fireEvent.change(screen.getByLabelText(/Reason/), { target: { value: '   ' } });
        expect(confirm).toBeDisabled();
        fireEvent.change(screen.getByLabelText(/Reason/), { target: { value: 'Lost phone' } });
        fireEvent.click(confirm);

        expect(visit.mock.calls[0][1]).toMatchObject({ method: 'post', data: { password: 'secret-pass', reason: 'Lost phone' } });
    });

    it('sends one request for a double-click', () => {
        let finish: (() => void) | undefined;
        const visit = vi.spyOn(router, 'visit').mockImplementation((_url, options) => {
            finish = () => options?.onFinish?.({} as never);
        });
        render(<PasswordConfirmModal {...props} url="/admin/account/two-factor" />);

        fireEvent.change(screen.getByLabelText(/Current password/), { target: { value: 'secret-pass' } });
        const confirm = screen.getByRole('button', { name: 'Disable' });
        fireEvent.click(confirm);
        fireEvent.click(confirm);
        expect(visit).toHaveBeenCalledTimes(1);

        act(() => finish?.());
    });
});
