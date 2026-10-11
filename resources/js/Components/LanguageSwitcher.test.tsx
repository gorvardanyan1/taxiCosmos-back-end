import { render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import LanguageSwitcher from '@/Components/LanguageSwitcher';
import { setDictionary } from '@/lib/i18n';
import { page, sharedProps } from '@/test/inertia';

vi.mock('@inertiajs/react', async (importOriginal) => (await import('@/test/inertia')).inertiaMock(importOriginal));

describe('LanguageSwitcher (sign-in screens)', () => {
    beforeEach(() => { page.props = sharedProps(); });
    afterEach(() => setDictionary('en', {}));

    it('offers every language by its own name, as plain ?lang= links', () => {
        render(<LanguageSwitcher />);

        const links = screen.getAllByRole('link');
        expect(links.map((a) => a.textContent)).toEqual(['English', 'Русский', 'Հայերեն']);
        expect(links.map((a) => a.getAttribute('href'))).toEqual(['?lang=en', '?lang=ru', '?lang=hy']);
        expect(links.map((a) => a.getAttribute('lang'))).toEqual(['en', 'ru', 'hy']);
    });

    it('marks the current language', () => {
        setDictionary('ru', {});
        render(<LanguageSwitcher />);

        expect(screen.getByRole('link', { name: 'Русский' })).toHaveAttribute('aria-current', 'true');
        expect(screen.getByRole('link', { name: 'English' })).not.toHaveAttribute('aria-current');
    });

    it('labels the navigation in the current language', () => {
        setDictionary('hy', { Language: 'Լեզու' });
        render(<LanguageSwitcher />);

        expect(screen.getByRole('navigation', { name: 'Լեզու' })).toBeInTheDocument();
    });

    it('shows only the languages the server lists, and ignores unknown codes', () => {
        page.props = sharedProps({ app: { ...sharedProps().app, locales: ['en', 'ru', 'de'] } });
        render(<LanguageSwitcher />);

        expect(screen.getAllByRole('link').map((a) => a.textContent)).toEqual(['English', 'Русский']);
    });
});
