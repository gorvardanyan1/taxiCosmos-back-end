/**
 * P13-T2 · Permission-aware navigation & actions: the sidebar, page buttons and row menus show only what the signed-in role
 * may use; pages a role cannot open are not in the menu and answer 403 when the URL is typed; the sidebar badges are real
 * counts (cached for 60 seconds). The server's 403 for every route and role is covered by PHPUnit (PermissionMatrixTest);
 * here the running app is driven as five real roles through the login form.
 */
const roles = {
    support: 'cy-nav-support@taxikosmos.test',
    dispatcher: 'cy-nav-dispatcher@taxikosmos.test',
    finance: 'cy-nav-finance@taxikosmos.test',
    admin: 'cy-nav-admin@taxikosmos.test',
    superAdmin: 'cy-nav-super@taxikosmos.test',
} as const;

const everyPage = ['Dashboard', 'Live Map', 'Riders', 'Drivers', 'Trips', 'Support Tickets', 'Ratings', 'Payments', 'Payouts', 'Chargebacks', 'Driver Balances', 'Reports', 'Zones & Fares', 'Surge', 'Commission', 'Settings', 'Activity Log'];
const pagesOf: Record<keyof typeof roles, string[]> = {
    support: ['Dashboard', 'Riders', 'Drivers', 'Trips', 'Support Tickets', 'Ratings', 'Payments'],
    dispatcher: ['Dashboard', 'Live Map', 'Riders', 'Drivers', 'Trips'],
    finance: ['Dashboard', 'Riders', 'Drivers', 'Trips', 'Payments', 'Payouts', 'Chargebacks', 'Driver Balances', 'Reports'],
    admin: everyPage,
    superAdmin: everyPage,
};

/** The sidebar's page links in order (a badge count at the end of a link is not part of its name). */
const sidebarPages = (expected: string[]) =>
    cy.findByRole('navigation', { name: 'Main' }).within(() => {
        cy.findAllByRole('link').should(($links) => {
            expect([...$links].map((link) => (link.textContent ?? '').replace(/\d+$/, '').trim())).to.deep.equal(expected);
        });
    });

/** A page typed into the address bar: the session cookie is sent, the answer must be the permission refusal. */
const status = (path: string) => cy.request({ url: path, failOnStatusCode: false, followRedirect: false }).its('status');
const forbidden = (path: string) => status(path).should('eq', 403);

describe('P13-T2 · Permission-aware navigation & actions', () => {
    before(() => {
        cy.resetDb();
        cy.make('admin', { email: roles.support, role: 'support', name: 'Sam Support' });
        cy.make('admin', { email: roles.dispatcher, role: 'dispatcher', name: 'Dana Dispatch' });
        cy.make('admin', { email: roles.finance, role: 'finance', name: 'Fiona Finance' });
        cy.make('admin', { email: roles.admin, role: 'admin', name: 'Ada Admin' });
        cy.make('admin', { email: roles.superAdmin, role: 'super_admin', name: 'Sol Super' });
    });

    beforeEach(() => cy.make('clear_rate_limits'));

    // Criterion: the sidebar shows only the pages the role's permissions allow; empty groups disappear.
    (Object.keys(roles) as (keyof typeof roles)[]).forEach((role) => {
        it(`lists for ${role} exactly the pages it may open`, () => {
            cy.loginAs(roles[role]);
            cy.visit('/admin');

            sidebarPages(pagesOf[role]);
        });
    });

    it('drops a whole sidebar group when none of its pages is allowed', () => {
        cy.loginAs(roles.dispatcher);
        cy.visit('/admin');

        cy.findByRole('navigation', { name: 'Main' }).within(() => {
            cy.findByText('Overview').should('be.visible');
            cy.findByText('Operations').should('be.visible');
            cy.findByText('Finance').should('not.exist');
            cy.findByText('Platform').should('not.exist');
        });
    });

    // Criterion: pages a role cannot access return 403 if visited directly.
    it('answers 403 with the access screen when a hidden page is typed in, and still serves the visible ones', () => {
        cy.loginAs(roles.dispatcher);

        cy.visit('/admin/payouts', { failOnStatusCode: false });
        cy.findByRole('heading', { name: 'You don\'t have access' }).should('be.visible');
        ['/admin/transactions', '/admin/payouts', '/admin/reports', '/admin/zones', '/admin/settings', '/admin/settings/users', '/admin/activity-logs', '/admin/support-tickets'].forEach(forbidden);
        status('/admin/live-map').should('eq', 200);
        status('/admin/riders').should('eq', 200);
    });

    // Criterion: nothing in the UI leads a role to a page it cannot open - the dashboard's alert cards included.
    it('shows each dashboard alert only to roles that may open its page, and every link it shows works', () => {
        const cards = { dispatcher: ['Stuck trips'], finance: ['Stuck trips', 'Failed payments'], support: ['Stuck trips', 'Failed payments', 'Urgent tickets'] } as const;
        const all = ['Stuck trips', 'Failed payments', 'Urgent tickets'];

        (Object.keys(cards) as (keyof typeof cards)[]).forEach((role) => {
            cy.loginAs(roles[role]);
            cy.visit('/admin');
            cy.findByTestId('dashboard-alerts').within(() => {
                cards[role].forEach((label) => cy.findByText(label).should('be.visible'));
                all.filter((label) => !(cards[role] as readonly string[]).includes(label)).forEach((label) => cy.findByText(label).should('not.exist'));
                cy.findAllByRole('link', { name: /View →/ }).should('have.length', cards[role].length).each(($link) => {
                    cy.request({ url: $link.attr('href') as string, failOnStatusCode: false }).its('status').should('eq', 200);
                });
            });
        });
    });

    it('sends a guest to the login page from every page, with no navigation data', () => {
        cy.clearAllCookies();
        cy.visit('/admin/payouts');

        cy.location('pathname').should('eq', '/login');
        cy.findByRole('navigation', { name: 'Main' }).should('not.exist');
    });

    // Criterion: page actions show only what the permissions allow.
    it('hides Edit and Suspend on a rider page from roles without riders.edit / riders.suspend, and refuses them by hand', () => {
        cy.make<{ id: number }>('rider', { name: 'Nav Rider' }).then((rider) => {
            [roles.dispatcher, roles.finance].forEach((email) => {
                cy.loginAs(email);
                cy.visit(`/admin/riders/${rider.id}`);
                cy.findByRole('heading', { name: 'Nav Rider' }).should('be.visible');
                cy.findByRole('button', { name: 'Edit' }).should('not.exist');
                cy.findByRole('button', { name: 'Suspend' }).should('not.exist');
                cy.adminRequest('POST', `/admin/riders/${rider.id}/suspend`, { reason: 'by hand' }).its('status').should('eq', 403);
            });

            cy.loginAs(roles.support);
            cy.visit(`/admin/riders/${rider.id}`);
            cy.findByRole('button', { name: 'Edit' }).should('be.visible');
            cy.findByRole('button', { name: 'Suspend' }).should('be.visible');

            cy.sql('select status from users where id = ?', [rider.id]).then(([row]) => expect(row.status, 'nothing was suspended').to.equal('active'));
        });
    });

    it('keeps Suspend out of the riders list menu for a dispatcher but offers it to support', () => {
        cy.make('rider', { name: 'Menu Rider' });

        cy.loginAs(roles.dispatcher);
        cy.visit('/admin/riders');
        cy.findByRole('button', { name: 'Actions for Menu Rider' }).click();
        cy.findByRole('button', { name: /View Profile/ }).should('be.visible');
        cy.findByRole('button', { name: /Suspend/ }).should('not.exist');

        cy.loginAs(roles.support);
        cy.visit('/admin/riders');
        cy.findByRole('button', { name: 'Actions for Menu Rider' }).click();
        cy.findByRole('button', { name: /Suspend/ }).should('be.visible');
    });

    it('shows driver document review to support only, vehicle writes to roles with drivers.edit, and the bank reveal to finance only', () => {
        cy.make<{ driver_id: number }>('driver_with_documents', { name: 'Nav Driver' }).then((withDocs) => {
            cy.make<{ driver_id: number }>('driver', {
                name: 'Nav Vehicles', vehicles: [{ make: 'Kia', model: 'Rio', plate: '10NV100', primary: false }], bank_account: { bank_name: 'Ameria', account_number: '1234567890123' },
            }).then((withExtras) => {
                // Documents: drivers.verify (support).
                cy.loginAs(roles.support);
                cy.visit(`/admin/drivers/${withDocs.driver_id}?tab=documents`);
                cy.findAllByRole('button', { name: /Approve/ }).should('have.length.greaterThan', 0);
                [roles.dispatcher, roles.finance].forEach((email) => {
                    cy.loginAs(email);
                    cy.visit(`/admin/drivers/${withDocs.driver_id}?tab=documents`);
                    cy.findByText('Nav Driver').should('be.visible');
                    cy.findByRole('button', { name: /Approve/ }).should('not.exist');
                    cy.findByRole('button', { name: /Reject/ }).should('not.exist');
                });

                // Header: Edit (drivers.edit) and Suspend (drivers.suspend).
                cy.loginAs(roles.dispatcher);
                cy.visit(`/admin/drivers/${withDocs.driver_id}`);
                cy.findByRole('button', { name: 'Edit' }).should('not.exist');
                cy.findByRole('button', { name: 'Suspend' }).should('not.exist');
                cy.loginAs(roles.support);
                cy.visit(`/admin/drivers/${withDocs.driver_id}`);
                cy.findByRole('button', { name: 'Edit' }).should('be.visible');
                cy.findByRole('button', { name: 'Suspend' }).should('be.visible');

                // Vehicles: drivers.edit.
                cy.loginAs(roles.finance);
                cy.visit(`/admin/drivers/${withExtras.driver_id}?tab=vehicles`);
                cy.findByText(/Kia Rio/).should('be.visible');
                cy.findByRole('button', { name: /Add vehicle/ }).should('not.exist');
                cy.findByRole('button', { name: /Set as primary/ }).should('not.exist');
                cy.loginAs(roles.support);
                cy.visit(`/admin/drivers/${withExtras.driver_id}?tab=vehicles`);
                cy.findByRole('button', { name: /Add vehicle/ }).should('be.visible');
                cy.findByRole('button', { name: /Set as primary/ }).should('be.visible');

                // Bank account: drivers.reveal_bank_account (finance, super admin).
                cy.loginAs(roles.support);
                cy.visit(`/admin/drivers/${withExtras.driver_id}?tab=bank-accounts`);
                cy.findByText(/•••• /).should('be.visible');
                cy.findByRole('button', { name: /Reveal/ }).should('not.exist');
                cy.loginAs(roles.finance);
                cy.visit(`/admin/drivers/${withExtras.driver_id}?tab=bank-accounts`);
                cy.findByRole('button', { name: /Reveal/ }).should('be.visible');
            });
        });
    });

    it('offers each trip admin action only to the role that holds its permission', () => {
        cy.loginAs(roles.dispatcher);
        cy.visit('/admin/trips/4');
        cy.findByRole('button', { name: 'Force Cancel' }).scrollIntoView().should('be.visible');
        cy.findByRole('button', { name: 'Adjust Fare' }).should('not.exist');

        cy.loginAs(roles.finance);
        cy.visit('/admin/trips/4');
        cy.findByRole('button', { name: 'Adjust Fare' }).scrollIntoView().should('be.visible');
        cy.findByRole('button', { name: 'Force Cancel' }).should('not.exist');

        cy.loginAs(roles.support);
        cy.visit('/admin/trips/4');
        cy.findByText('TK-58200').should('be.visible');
        cy.findByText('Admin Actions').should('not.exist');
    });

    it('keeps money actions with finance: refunds and manual payments are not offered to support, payouts not even listed', () => {
        cy.loginAs(roles.support);
        cy.visit('/admin/transactions');
        cy.findByRole('heading', { name: /Payments/ }).should('be.visible');
        cy.findByRole('button', { name: /Record Manual Payment/ }).should('not.exist');
        cy.findByRole('button', { name: /Refund/ }).should('not.exist');
        forbidden('/admin/payouts');

        cy.loginAs(roles.finance);
        cy.visit('/admin/transactions');
        cy.findByRole('button', { name: /Record Manual Payment/ }).should('be.visible');
        cy.findAllByRole('button', { name: /Refund/ }).should('have.length.greaterThan', 0);
        cy.visit('/admin/payouts');
        cy.findByRole('button', { name: 'Approve batch' }).should('be.visible');
    });

    // Criterion: Settings opens a tab the role may use; tabs it cannot open are not shown.
    it('opens Settings on the first allowed tab and shows only the tabs the role may open', () => {
        cy.loginAs(roles.admin);
        cy.visit('/admin');
        cy.findByRole('link', { name: 'Settings' }).click();
        cy.location('pathname').should('eq', '/admin/settings/gateways');
        cy.findByRole('navigation', { name: 'Settings' }).within(() => {
            cy.findAllByRole('link').should(($tabs) => expect([...$tabs].map((tab) => tab.textContent?.trim())).to.deep.equal(['Gateways', 'Currencies', 'Platform', 'Maps']));
        });
        forbidden('/admin/settings/users');

        cy.loginAs(roles.superAdmin);
        cy.visit('/admin/settings');
        cy.location('pathname').should('eq', '/admin/settings/users');
        cy.findByRole('navigation', { name: 'Settings' }).within(() => {
            cy.findAllByRole('link').should(($tabs) => expect([...$tabs].map((tab) => tab.textContent?.trim())).to.deep.equal(['Users & Roles', 'Gateways', 'Currencies', 'Platform', 'Maps']));
        });

        cy.loginAs(roles.support);
        forbidden('/admin/settings');
    });

    it('links the queue monitor only for roles the Horizon gate lets in', () => {
        cy.loginAs(roles.superAdmin);
        cy.visit('/admin');
        cy.findByText('Queue Monitor').should('be.visible');

        cy.loginAs(roles.support);
        cy.visit('/admin');
        cy.findByRole('navigation', { name: 'Main' }).should('be.visible');
        cy.findByText('Queue Monitor').should('not.exist');
    });

    // Criterion: sidebar badges are real counts, cached for 60 seconds.
    it('shows the real number of pending driver verifications, served from the cache for a minute', () => {
        const pendingInDb = () => cy.sql("select count(*)::int as n from driver_profiles where verification_status = 'pending'").then((rows) => rows[0].n as number);
        const driversLink = () => cy.findByRole('navigation', { name: 'Main' }).findByRole('link', { name: /^Drivers/ });

        cy.make('driver', { name: 'Pending One', verification_status: 'pending' });
        cy.make('driver', { name: 'Pending Two', verification_status: 'pending' });
        cy.make('driver', { name: 'Approved One', verification_status: 'approved' });
        cy.make('clear_rate_limits'); // flushes the cache, so the count is taken now

        pendingInDb().then((first) => {
            expect(first).to.be.at.least(2);
            cy.loginAs(roles.support);
            cy.visit('/admin');
            driversLink().should('have.text', `Drivers${first}`);

            // A new pending driver is not counted until the cached number expires.
            cy.make('driver', { name: 'Pending Three', verification_status: 'pending' });
            pendingInDb().should('eq', first + 1);
            cy.visit('/admin');
            driversLink().should('have.text', `Drivers${first}`);

            // Once the cache is gone, the real number shows.
            cy.make('clear_rate_limits');
            cy.visit('/admin');
            driversLink().should('have.text', `Drivers${first + 1}`);
        });
    });
});

describe('P13-T2 · Sidebar badge at zero', () => {
    before(() => {
        cy.resetDb();
        cy.make('admin', { email: roles.support, role: 'support', name: 'Sam Support' });
    });

    it('shows no badge on a page whose count is zero', () => {
        cy.make('driver', { name: 'Already Approved', verification_status: 'approved' });
        cy.make('clear_rate_limits');
        cy.sql("select count(*)::int as n from driver_profiles where verification_status = 'pending'").then((rows) => expect(rows[0].n).to.equal(0));

        cy.loginAs(roles.support);
        cy.visit('/admin');

        cy.findByRole('navigation', { name: 'Main' }).findByRole('link', { name: /^Drivers/ }).should('have.text', 'Drivers');
    });
});

export {};
