/**
 * P9-T1 · Audit logging (admin Activity Log).
 * The entries come from real admin actions (edit, suspend) made through the app, then the page is
 * checked: filters in the URL, before/after diff, CSV export of the current filter, immutability and
 * permissions.
 */
const localDay = (offsetDays = 0) => new Date(Date.now() + offsetDays * 86_400_000).toLocaleDateString('en-CA', { timeZone: 'Asia/Yerevan' });
const rows = () => cy.get('tbody tr');

describe('P9-T1 · Activity Log', () => {
    const ids: Record<string, number> = {};

    before(() => {
        cy.resetDb();
        cy.make('admin', { email: 'cy-audit-ann@taxikosmos.test', role: 'admin', name: 'Ann Auditor' }).then((a) => (ids.ann = a.id));
        cy.make('admin', { email: 'cy-audit-bob@taxikosmos.test', role: 'support', name: 'Bob Support' }).then((a) => (ids.bob = a.id));
        cy.make('rider', { name: 'Rider One', email: 'one@example.com' }).then((r) => (ids.one = r.id));
        cy.make('rider', { name: 'Rider Two' }).then((r) => (ids.two = r.id));
        cy.make('rider', { name: 'Rider Three' }).then((r) => (ids.three = r.id));

        // Real, audited admin actions.
        cy.loginAs('cy-audit-ann@taxikosmos.test');
        cy.visit('/admin/riders');
        cy.then(() => {
            cy.adminRequest('PATCH', `/admin/riders/${ids.one}`, { name: 'Rider One Renamed', email: 'one.new@example.com', reason: 'Typo in name' }).its('status').should('eq', 303);
            cy.adminRequest('POST', `/admin/riders/${ids.two}/suspend`, { reason: 'Fraud check' }).its('status').should('eq', 302);
        });
        cy.loginAs('cy-audit-bob@taxikosmos.test');
        cy.visit('/admin/riders');
        cy.then(() => cy.adminRequest('POST', `/admin/riders/${ids.three}/suspend`, { reason: 'Abusive to drivers' }).its('status').should('eq', 302));
    });

    beforeEach(() => cy.loginAs('cy-audit-ann@taxikosmos.test'));

    // Criterion: GET /admin/activity-logs with filters (actor, action, target type/ID, date range).
    it('lists who did what to whom, with the reason, newest first', () => {
        cy.visit('/admin/activity-logs');
        cy.findByRole('heading', { name: 'Activity Log' }).should('be.visible');

        cy.contains('tbody tr', 'Abusive to drivers').should('contain', 'Bob Support · Support').and('contain', 'rider.suspended');
        cy.contains('tbody tr', 'Fraud check').should('contain', 'Ann Auditor · Admin').and('contain', 'rider.suspended');
        cy.contains('tbody tr', 'Typo in name').should('contain', 'rider.updated');
        cy.get('tbody tr').then(($rows) => {
            const order = $rows.toArray().map((row) => row.innerText);
            const index = (text: string) => order.findIndex((line) => line.includes(text));
            expect(index('Abusive to drivers'), 'newest first').to.be.lessThan(index('Fraud check'));
            expect(index('Fraud check')).to.be.lessThan(index('Typo in name'));
        });
    });

    it('filters by actor, action, target type and target id, keeps them in the URL and across reload/back', () => {
        cy.visit('/admin/activity-logs');

        cy.findByLabelText('All actors').select('Bob Support');
        cy.location('search').should('contain', `filter%5Bactor%5D=${ids.bob}`);
        // Bob's sign-in is audited too (auth log), next to his suspension; nothing of Ann's.
        rows().should('have.length', 2).and('not.contain', 'Ann Auditor');
        cy.contains('tbody tr', 'Abusive to drivers').should('contain', 'rider.suspended');
        cy.contains('tbody tr', 'auth.login').should('contain', 'Bob Support');

        cy.findByRole('button', { name: 'Clear filters' }).click();
        cy.findByLabelText('All actions').select('rider.suspended');
        rows().should('have.length', 2).each((row) => expect(row.text()).to.contain('rider.suspended'));

        cy.findByLabelText('Target ID').type(`${ids.two}{enter}`);
        cy.location('search').should('contain', `filter%5Btarget_id%5D=${ids.two}`);
        rows().should('have.length', 1).and('contain', 'Fraud check');

        cy.reload();
        rows().should('have.length', 1).and('contain', 'Fraud check');
        cy.findByLabelText('All actions').should('have.value', 'rider.suspended');

        cy.go('back');
        rows().should('have.length', 2);
        cy.findByLabelText('Target ID').should('have.value', '');

        cy.visit('/admin/activity-logs');
        cy.findByLabelText('All target types').select('Driver');
        rows().should('have.length', 1).and('contain', 'No activity recorded');
    });

    it('filters by date range in the admin timezone', () => {
        cy.visit(`/admin/activity-logs?filter[action]=rider.suspended&filter[from]=${localDay(1)}`);
        rows().should('have.length', 1).and('contain', 'No activity recorded');

        cy.findByLabelText('From date').clear().type(localDay(0));
        cy.findByLabelText('To date').type(localDay(0));
        rows().should('have.length', 2);

        cy.findByLabelText('To date').clear().type(localDay(-1));
        rows().should('have.length', 1).and('contain', 'No activity recorded');
    });

    // Criterion: a before/after diff view; personal data only shows as "changed".
    it('opens the before/after diff of an entry, deep-linkable, and hides PII', () => {
        cy.visit('/admin/activity-logs?filter[action]=rider.updated');
        cy.contains('tbody tr', 'Typo in name').click();

        cy.location('search').should('match', /entry=\d+/);
        cy.findByLabelText('Changes for rider.updated').within(() => {
            cy.contains('Before').next('pre').should('contain', 'name: "Rider One"').and('contain', 'email: "changed"');
            cy.contains('After').next('pre').should('contain', 'name: "Rider One Renamed"').and('contain', 'email: "changed"');
        });
        cy.get('main').should('not.contain', 'one.new@example.com').and('not.contain', 'one@example.com');

        cy.reload();
        cy.findByLabelText('Changes for rider.updated').should('be.visible');

        cy.contains('tbody tr', 'Typo in name').click();
        cy.location('search').should('not.match', /entry=/);
        cy.findByLabelText('Changes for rider.updated').should('not.exist');
    });

    // Criterion: GET /admin/activity-logs/export — CSV of the current filter.
    it('exports exactly the filtered entries as CSV, and the export itself is audited', () => {
        cy.visit('/admin/activity-logs');
        cy.findByLabelText('All actions').select('rider.suspended');

        cy.findByRole('link', { name: /Export CSV/ }).should('have.attr', 'href').and('contain', 'filter%5Baction%5D=rider.suspended').then((href) => {
            cy.request(String(href)).then((response) => {
                expect(response.headers['content-type']).to.contain('text/csv');
                expect(String(response.headers['content-disposition'])).to.match(/attachment; filename=activity-log-\d{8}-\d{6}\.csv/);

                const lines = String(response.body).trim().split('\n');
                expect(lines[0]).to.equal('time_utc,actor,actor_role,action,target_type,target,reason,ip,user_agent,before,after');
                expect(lines).to.have.length(3);
                expect(lines.slice(1).every((line) => line.includes(',rider.suspended,'))).to.equal(true);
                expect(lines.join('\n')).to.contain('Fraud check').and.contain('Abusive to drivers').and.not.contain('Typo in name');
            });
        });

        cy.sql("select causer_id, attribute_changes, properties->'context'->'filters' as filters from activity_log where description = 'activity_log.exported' order by id desc limit 1").then(([entry]) => {
            expect(entry.causer_id).to.equal(ids.ann);
            expect(JSON.parse(entry.filters)).to.deep.equal({ action: 'rider.suspended' });
        });
    });

    // Criterion: entries cannot be edited or deleted from the application (no routes).
    it('has no way to change or delete an entry', () => {
        cy.visit('/admin/activity-logs');
        cy.sql('select id from activity_log order by id limit 1').its('0.id').then((id) => {
            ['PATCH', 'PUT', 'DELETE'].forEach((method) => {
                cy.adminRequest(method, `/admin/activity-logs/${id}`, { description: 'tampered' }).its('status').should('be.oneOf', [404, 405]);
            });
            cy.adminRequest('POST', '/admin/activity-logs', { description: 'forged' }).its('status').should('be.oneOf', [404, 405]);
            cy.sql('select description from activity_log where id = ?', [id]).its('0.description').should('not.eq', 'tampered');
        });
        cy.sql("select count(*)::int as n from activity_log where description in ('tampered', 'forged')").its('0.n').should('eq', 0);
    });

    // Permission: activity_log.view (admin and super admin only).
    it('refuses the log and the export to roles without activity_log.view', () => {
        cy.loginAs('cy-audit-bob@taxikosmos.test');
        cy.visit('/admin/activity-logs', { failOnStatusCode: false });
        cy.findByRole('heading', { name: "You don't have access" }).should('be.visible');
        cy.request({ url: '/admin/activity-logs/export', failOnStatusCode: false }).its('status').should('eq', 403);
    });
});

export {};
