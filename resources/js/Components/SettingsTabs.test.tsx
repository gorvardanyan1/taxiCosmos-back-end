import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import SettingsTabs from '@/Components/SettingsTabs';
import { grant } from '@/test/inertia';

vi.mock('@inertiajs/react', async (importOriginal) => (await import('@/test/inertia')).inertiaMock(importOriginal));

const tabNames = () => screen.getAllByRole('link').map((tab) => tab.textContent?.trim());

describe('Settings tabs by permission', () => {
    it('shows Users & Roles only with admins.manage, and the integration tabs only with settings.manage', () => {
        grant(['admins.manage', 'settings.manage']);
        const all = render(<SettingsTabs active="users"><p>body</p></SettingsTabs>);
        expect(tabNames()).toEqual(['Users & Roles', 'Gateways', 'Currencies', 'Platform', 'Maps']);
        all.unmount();

        grant(['settings.manage']);
        const settingsOnly = render(<SettingsTabs active="gateways"><p>body</p></SettingsTabs>);
        expect(tabNames()).toEqual(['Gateways', 'Currencies', 'Platform', 'Maps']);
        settingsOnly.unmount();

        grant(['admins.manage']);
        render(<SettingsTabs active="users"><p>body</p></SettingsTabs>);
        expect(tabNames()).toEqual(['Users & Roles']);
    });
});
