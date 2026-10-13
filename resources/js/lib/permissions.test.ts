import { describe, expect, it } from 'vitest';
import { allows, PERMISSIONS } from '@/lib/permissions';

describe('allows', () => {
    it('is true only for a permission that is held', () => {
        expect(allows(['riders.view'], 'riders.view')).toBe(true);
        expect(allows(['riders.view'], 'riders.suspend')).toBe(false);
        expect(allows([], 'riders.view')).toBe(false);
    });

    it('lets a list through when any one of its permissions is held, and not otherwise', () => {
        expect(allows(['settings.manage'], ['admins.manage', 'settings.manage'])).toBe(true);
        expect(allows(['admins.manage'], ['admins.manage', 'settings.manage'])).toBe(true);
        expect(allows(['riders.view'], ['admins.manage', 'settings.manage'])).toBe(false);
        expect(allows([], ['admins.manage', 'settings.manage'])).toBe(false);
    });

    it('does not treat a longer or shorter name as the same permission', () => {
        expect(allows(['riders.view_all'], 'riders.view')).toBe(false);
        expect(allows(['riders'], 'riders.view')).toBe(false);
    });
});

describe('the permission list', () => {
    it('has no duplicates and only resource.action names', () => {
        expect(new Set(PERMISSIONS).size).toBe(PERMISSIONS.length);
        for (const permission of PERMISSIONS) expect(permission).toMatch(/^[a-z_]+\.[a-z_]+$/);
    });
});
