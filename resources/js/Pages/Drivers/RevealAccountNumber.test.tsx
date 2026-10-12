import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import RevealAccountNumber from '@/Pages/Drivers/RevealAccountNumber';

describe('Reveal account number', () => {
    beforeEach(() => { vi.useRealTimers(); document.cookie = 'XSRF-TOKEN=abc%3D; path=/'; });
    afterEach(() => { vi.restoreAllMocks(); vi.useRealTimers(); });

    it('asks the server (which logs it) and shows the number it returns, then hides it on request', async () => {
        const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response(JSON.stringify({ account_number: 'AM1234567890 4821' }), { status: 200 }));
        render(<RevealAccountNumber url="/admin/drivers/4/bank-accounts/9/reveal" />);

        expect(screen.queryByTestId('revealed-account-number')).toBeNull();
        fireEvent.click(screen.getByRole('button', { name: /Reveal account/ }));

        expect(await screen.findByTestId('revealed-account-number')).toHaveTextContent('AM1234567890 4821');
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock.mock.calls[0][0]).toBe('/admin/drivers/4/bank-accounts/9/reveal');
        expect(fetchMock.mock.calls[0][1]).toMatchObject({ method: 'POST', credentials: 'same-origin' });
        expect((fetchMock.mock.calls[0][1]?.headers as Record<string, string>)['X-XSRF-TOKEN']).toBe('abc=');

        fireEvent.click(screen.getByRole('button', { name: 'Hide' }));
        expect(screen.queryByTestId('revealed-account-number')).toBeNull();
        expect(screen.getByRole('button', { name: /Reveal account/ })).toBeInTheDocument();
    });

    it('hides the number again by itself after thirty seconds', async () => {
        vi.useFakeTimers();
        vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response(JSON.stringify({ account_number: 'AM00 1111' }), { status: 200 }));
        render(<RevealAccountNumber url="/reveal" />);

        fireEvent.click(screen.getByRole('button', { name: /Reveal account/ }));
        await act(async () => { await vi.advanceTimersByTimeAsync(0); });
        expect(screen.getByTestId('revealed-account-number')).toBeInTheDocument();

        await act(async () => { await vi.advanceTimersByTimeAsync(29_000); });
        expect(screen.getByTestId('revealed-account-number')).toBeInTheDocument();
        await act(async () => { await vi.advanceTimersByTimeAsync(2_000); });

        expect(screen.queryByTestId('revealed-account-number')).toBeNull();
    });

    it('shows an error and no number when the server refuses', async () => {
        vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response('{}', { status: 403 }));
        render(<RevealAccountNumber url="/reveal" />);

        fireEvent.click(screen.getByRole('button', { name: /Reveal account/ }));

        expect(await screen.findByRole('alert')).toHaveTextContent('The account number could not be revealed.');
        expect(screen.queryByTestId('revealed-account-number')).toBeNull();
    });

    it('is disabled and sends nothing without the permission', () => {
        const fetchMock = vi.spyOn(globalThis, 'fetch');
        render(<RevealAccountNumber url={null} />);

        const button = screen.getByRole('button', { name: /Reveal account/ });
        expect(button).toBeDisabled();
        expect(button).toHaveAttribute('title', 'Only finance can reveal full account numbers.');
        fireEvent.click(button);
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('does not send two requests for a double click', async () => {
        let resolve!: (response: Response) => void;
        const fetchMock = vi.spyOn(globalThis, 'fetch').mockReturnValue(new Promise((r) => { resolve = r; }));
        render(<RevealAccountNumber url="/reveal" />);

        fireEvent.click(screen.getByRole('button', { name: /Reveal account/ }));
        fireEvent.click(screen.getByRole('button', { name: /Reveal account/ }));
        resolve(new Response(JSON.stringify({ account_number: 'X' }), { status: 200 }));

        await screen.findByTestId('revealed-account-number');
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });
});
