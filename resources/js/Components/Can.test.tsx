import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Can from '@/Components/Can';
import { grant } from '@/test/inertia';

vi.mock('@inertiajs/react', async (importOriginal) => (await import('@/test/inertia')).inertiaMock(importOriginal));

describe('Can', () => {
    beforeEach(() => grant(['riders.view']));

    it('renders its children for a held permission', () => {
        render(<Can permission="riders.view"><button>Open</button></Can>);

        expect(screen.getByRole('button', { name: 'Open' })).toBeInTheDocument();
    });

    it('renders nothing without the permission', () => {
        const { container } = render(<Can permission="riders.suspend"><button>Suspend</button></Can>);

        expect(screen.queryByRole('button', { name: 'Suspend' })).toBeNull();
        expect(container).toBeEmptyDOMElement();
    });

    it('accepts a list and needs only one of them', () => {
        render(<Can permission={['riders.suspend', 'riders.view']}><button>Either</button></Can>);

        expect(screen.getByRole('button', { name: 'Either' })).toBeInTheDocument();
    });
});
