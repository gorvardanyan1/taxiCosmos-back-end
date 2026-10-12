/**
 * P13-T1 · Convert the Figma template to Inertia (the admin shell and route map).
 * Every page of the route map renders from Inertia props without console errors; deep links,
 * filters and tabs live in the URL (reload, back/forward); shared props (user, permissions, flash,
 * app config) reach the browser; unknown pages use the template's error page.
 * (The TypeScript criterion is checked by `npm run typecheck`, not in a browser.)
 */
const ROUTE_MAP: Array<{ path: string; title: string; nav: string | null }> = [
    { path: '/admin', title: 'Dashboard', nav: 'Dashboard' },
    { path: '/admin/live-map', title: 'Live Map', nav: 'Live Map' },
    { path: '/admin/riders', title: 'Riders', nav: 'Riders' },
    { path: '/admin/drivers', title: 'Drivers', nav: 'Drivers' },
    { path: '/admin/trips', title: 'Trips', nav: 'Trips' },
    { path: '/admin/support-tickets', title: 'Support Tickets', nav: 'Support Tickets' },
    { path: '/admin/ratings', title: 'Ratings', nav: 'Ratings' },
    { path: '/admin/transactions', title: 'Payments & Transactions', nav: 'Payments' },
    { path: '/admin/payouts', title: 'Payouts', nav: 'Payouts' },
    { path: '/admin/chargebacks', title: 'Chargebacks', nav: 'Chargebacks' },
    { path: '/admin/driver-balances', title: 'Driver Balances', nav: 'Driver Balances' },
    { path: '/admin/reports', title: 'Reports', nav: 'Reports' },
    { path: '/admin/zones', title: 'Zones & Fares', nav: 'Zones & Fares' },
    { path: '/admin/surge', title: 'Surge', nav: 'Surge' },
    { path: '/admin/commission-rules', title: 'Commission', nav: 'Commission' },
    { path: '/admin/settings/users', title: 'Settings · Users & Roles', nav: 'Settings' },
    { path: '/admin/settings/gateways', title: 'Settings · Gateways', nav: 'Settings' },
    { path: '/admin/settings/currencies', title: 'Settings · Currencies', nav: 'Settings' },
    { path: '/admin/settings/platform', title: 'Settings · Platform', nav: 'Settings' },
    { path: '/admin/settings/maps', title: 'Settings · Maps', nav: 'Settings' },
    { path: '/admin/activity-logs', title: 'Activity Log', nav: 'Activity Log' },
    { path: '/admin/account', title: 'My Account', nav: null },
];

describe('P13-T1 · Admin shell and route map', () => {
    before(() => {
        cy.resetDb();
        cy.make('admin', { email: 'cy-shell-super@taxikosmos.test', role: 'super_admin', name: 'Sky Super' });
        cy.make('admin', { email: 'cy-shell-support@taxikosmos.test', role: 'support', name: 'Sol Support' });
    });

    beforeEach(() => cy.loginAs('cy-shell-super@taxikosmos.test'));

    // Criterion: every page in the route map renders under its route from Inertia props.
    it('renders every page of the route map with its title, heading and active menu item', () => {
        ROUTE_MAP.forEach(({ path, title, nav }) => {
            cy.intercept({ method: 'GET', url: `${path}*` }).as('page');
            cy.visit(path);
            cy.title().should('eq', `${title} · TaxiKosmos Admin`);
            cy.get('main h1').should('be.visible').invoke('text').should('not.be.empty');
            cy.get('#app').invoke('attr', 'data-page').then((raw) => {
                const page = JSON.parse(String(raw));
                expect(page.url.split('?')[0], path).to.equal(path);
                expect(page.component, path).to.be.a('string').and.not.be.empty;
            });
            if (nav) {
                cy.get('nav[aria-label=Main] a[aria-current=page]').should('have.length', 1).and('contain', nav);
            }
        });
    });

    it('navigates between pages through the sidebar without full reloads', () => {
        cy.visit('/admin');
        cy.window().then((win) => ((win as unknown as { marker: string }).marker = 'same-document'));

        ['Riders', 'Trips', 'Zones & Fares', 'Activity Log', 'Dashboard'].forEach((label) => {
            cy.get('nav[aria-label=Main]').contains('a', label).click();
            cy.get('nav[aria-label=Main] a[aria-current=page]').should('contain', label);
        });

        cy.window().its('marker').should('eq', 'same-document');
    });

    it('opens detail pages from their lists and back again', () => {
        cy.visit('/admin/trips');
        cy.get('tbody tr').first().click();
        cy.location('pathname').should('match', /^\/admin\/trips\/\d+$/);
        cy.get('main h1').invoke('text').then((code) => cy.title().should('eq', `${code.trim()} · TaxiKosmos Admin`));
        cy.go('back');
        cy.location('pathname').should('eq', '/admin/trips');

        cy.make('driver_with_documents', { name: 'Shell Driver' });
        cy.visit('/admin/drivers');
        cy.get('tbody tr a').first().then(($link) => {
            const name = $link.text();
            cy.wrap($link).click();
            cy.location('pathname').should('match', /^\/admin\/drivers\/\d+$/);
            cy.findByRole('heading', { name }).should('be.visible');
        });
    });

    // Criterion: browser back/forward and deep links work for every page, filter and tab (state in the URL).
    it('keeps list filters in the URL through reload, back and forward', () => {
        cy.visit('/admin/trips');
        cy.findByLabelText('All Statuses').find('option').eq(1).invoke('val').then((status) => {
            cy.findByLabelText('All Statuses').select(String(status));
            cy.location('search').should('contain', `filter%5Bstatus%5D=${status}`);
            cy.findByLabelText('Search').type('zzz-no-such-trip');
            cy.location('search').should('contain', 'filter%5Bsearch%5D=zzz-no-such-trip');
            cy.contains('tbody tr', 'No').should('exist');

            cy.reload();
            cy.findByLabelText('All Statuses').should('have.value', status);
            cy.findByLabelText('Search').should('have.value', 'zzz-no-such-trip');

            cy.go('back');
            cy.findByLabelText('Search').should('have.value', '');
            cy.findByLabelText('All Statuses').should('have.value', status);
            cy.go('back');
            cy.findByLabelText('All Statuses').should('have.value', '');
            cy.go('forward');
            cy.findByLabelText('All Statuses').should('have.value', status);
        });
    });

    it('keeps the selected tab in the URL for deep links, reload, back and forward', () => {
        cy.make('rider', { name: 'Tab Rider' }).then((rider) => {
            cy.visit(`/admin/riders/${rider.id}`);
            cy.findByRole('tab', { name: 'Trips' }).should('have.attr', 'aria-selected', 'true');

            cy.findByRole('tab', { name: 'Payment methods' }).click();
            cy.location('search').should('eq', '?tab=payment-methods');
            cy.findByRole('tab', { name: 'Payment methods' }).should('have.attr', 'aria-selected', 'true');

            cy.reload();
            cy.findByRole('tab', { name: 'Payment methods' }).should('have.attr', 'aria-selected', 'true');
            cy.go('back');
            cy.findByRole('tab', { name: 'Trips' }).should('have.attr', 'aria-selected', 'true');
            cy.go('forward');
            cy.findByRole('tab', { name: 'Payment methods' }).should('have.attr', 'aria-selected', 'true');

            cy.visit(`/admin/riders/${rider.id}?tab=activity`);
            cy.findByRole('tab', { name: 'Activity' }).should('have.attr', 'aria-selected', 'true');
            cy.visit(`/admin/riders/${rider.id}?tab=nonsense`);
            cy.findByRole('tab', { name: 'Trips' }).should('have.attr', 'aria-selected', 'true');
        });

        cy.visit('/admin/settings/users');
        cy.get('nav[aria-label=Settings]').contains('a', 'Currencies').click();
        cy.location('pathname').should('eq', '/admin/settings/currencies');
        cy.go('back');
        cy.location('pathname').should('eq', '/admin/settings/users');
    });

    // Criterion: shared props include the auth user, permissions, flash messages and app config.
    it('shares the signed-in admin, their permissions and the app config with every page', () => {
        cy.loginAs('cy-shell-support@taxikosmos.test');
        cy.visit('/admin/riders');
        cy.contains('header', 'Sol Support').should('contain', 'Support');
        cy.get('aside').should('contain', 'TaxiKosmos').and('contain', 'Sol Support');

        cy.pageProps('/admin/riders').then((props) => {
            expect(props.auth.user).to.include({ name: 'Sol Support', email: 'cy-shell-support@taxikosmos.test', role: 'support' });
            expect(props.auth.permissions).to.deep.equal([
                'dashboard.view', 'drivers.edit', 'drivers.suspend', 'drivers.verify', 'drivers.view', 'payments.view', 'ratings.view',
                'riders.edit', 'riders.suspend', 'riders.view', 'support.manage', 'trips.view',
            ]);
            expect(props.app.currencies.map((c: { code: string }) => c.code)).to.include('AMD');
            expect(props.app).to.include({ name: 'TaxiKosmos', baseCurrency: 'AMD', timezone: 'Asia/Yerevan' });
            expect(props.flash).to.deep.equal({ success: null, error: null });
            expect(JSON.stringify(props.auth.user)).not.to.contain('password');
        });
    });

    it('turns a flash message into a toast once, after the redirect', () => {
        cy.make('rider', { name: 'Flash Rider' }).then((rider) => {
            cy.visit(`/admin/riders/${rider.id}`);
            cy.findByRole('button', { name: 'Suspend' }).click();
            cy.findByLabelText(/Reason/).type('Flash test');
            cy.findByRole('button', { name: 'Confirm Suspension' }).click();

            cy.findByRole('status').should('contain', 'Rider suspended.');
            cy.findByRole('button', { name: 'Dismiss' }).click();
            cy.findByRole('status').should('not.exist');
            cy.reload();
            cy.findByRole('status').should('not.exist');
        });
    });

    it('shows the template error page for an unknown admin page and a forbidden one', () => {
        cy.visit('/admin/no-such-page', { failOnStatusCode: false });
        cy.findByRole('heading', { name: 'Page not found' }).should('be.visible');
        cy.title().should('eq', 'Page not found · TaxiKosmos Admin');

        cy.loginAs('cy-shell-support@taxikosmos.test');
        cy.visit('/admin/payouts', { failOnStatusCode: false });
        cy.findByRole('heading', { name: "You don't have access" }).should('be.visible');
        cy.request({ url: '/admin/payouts', failOnStatusCode: false }).its('status').should('eq', 403);
    });
});

export {};
