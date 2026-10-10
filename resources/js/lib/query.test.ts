import { describe, expect, it } from 'vitest';
import { buildQueryUrl, withId } from '@/lib/query';

const at = (url: string) => new URL(url, 'http://localhost') as unknown as Location;

describe('buildQueryUrl', () => {
    it('merges changes into the current query and drops empty values', () => {
        expect(buildQueryUrl({ 'filter[status]': 'active' }, { location: at('/admin/riders?sort=name') })).toBe('/admin/riders?sort=name&filter%5Bstatus%5D=active');
        expect(buildQueryUrl({ 'filter[status]': '' }, { location: at('/admin/riders?filter%5Bstatus%5D=active&sort=name') })).toBe('/admin/riders?sort=name');
        expect(buildQueryUrl({ tab: null }, { location: at('/admin/drivers/8?tab=documents') })).toBe('/admin/drivers/8');
    });

    it('resets the page when asked', () => {
        expect(buildQueryUrl({ 'filter[search]': 'sun' }, { resetPage: true, location: at('/admin/riders?page=3') })).toBe('/admin/riders?filter%5Bsearch%5D=sun');
        expect(buildQueryUrl({ tab: 'vehicles' }, { location: at('/admin/drivers/8?page=3') })).toBe('/admin/drivers/8?page=3&tab=vehicles');
    });
});

describe('withId', () => {
    it('fills URL templates and stays null when unavailable', () => {
        expect(withId('/admin/riders/{id}/suspend', 80)).toBe('/admin/riders/80/suspend');
        expect(withId(null, 80)).toBeNull();
        expect(withId('/admin/riders/{id}/suspend', undefined)).toBeNull();
    });
});
