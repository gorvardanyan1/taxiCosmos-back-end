import { router } from '@inertiajs/core';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import ActionButton from '@/Components/ActionButton';

describe('ActionButton', () => {
    afterEach(() => vi.restoreAllMocks());

    it('visits the endpoint once for a double-click and again after the visit finished', () => {
        let finish: (() => void) | undefined;
        const visit = vi.spyOn(router, 'visit').mockImplementation((_url, options) => {
            finish = () => options?.onFinish?.({} as never);
        });
        render(<ActionButton url="/admin/drivers/3/documents/9/approve" className="btn">Approve</ActionButton>);

        const button = screen.getByRole('button', { name: 'Approve' });
        fireEvent.click(button);
        fireEvent.click(button);

        expect(visit).toHaveBeenCalledTimes(1);
        expect(visit.mock.calls[0][0]).toBe('/admin/drivers/3/documents/9/approve');
        expect(visit.mock.calls[0][1]).toMatchObject({ method: 'post', preserveScroll: true });

        act(() => finish?.());
        fireEvent.click(button);
        expect(visit).toHaveBeenCalledTimes(2);
    });

    it('does nothing when the visit is declined in the confirm dialog, and stays usable', () => {
        const visit = vi.spyOn(router, 'visit').mockImplementation(() => {});
        const confirm = vi.spyOn(window, 'confirm').mockReturnValueOnce(false).mockReturnValueOnce(true);
        render(<ActionButton url="/admin/zones/1/deactivate" confirm="Deactivate?" className="btn">Deactivate</ActionButton>);

        fireEvent.click(screen.getByRole('button', { name: 'Deactivate' }));
        expect(visit).not.toHaveBeenCalled();

        fireEvent.click(screen.getByRole('button', { name: 'Deactivate' }));
        expect(confirm).toHaveBeenCalledTimes(2);
        expect(visit).toHaveBeenCalledTimes(1);
    });

    it('is disabled with a hint while the endpoint does not exist', () => {
        const visit = vi.spyOn(router, 'visit').mockImplementation(() => {});
        render(<ActionButton url={null} className="btn">Export</ActionButton>);

        const button = screen.getByRole('button', { name: 'Export' });
        expect(button).toBeDisabled();
        fireEvent.click(button);
        expect(visit).not.toHaveBeenCalled();
    });
});
