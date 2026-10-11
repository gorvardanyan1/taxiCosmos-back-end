/**
 * P4-T1 · Admin rider management.
 * List (pagination, search, filters, sorting in the URL), detail, edit with reason + audit,
 * suspend (reason, token revocation, user.suspended event) and reactivate, permissions, conflicts.
 */
interface Rider {
    id: number;
    code: string;
    name: string;
    email: string | null;
    phone: string | null;
}

const rows = () => cy.get('tbody tr');
const auditFor = (action: string, riderId: number) =>
    cy.sql(
        "select causer_id, properties->>'reason' as reason, attribute_changes as changes from activity_log where description = ? and subject_id = ? and subject_type = 'App\\Models\\User' order by id",
        [action, riderId],
    );

describe('P4-T1 · Admin rider management', () => {
    let admin: { id: number; email: string };

    before(() => {
        cy.resetDb();
        cy.make('admin', { email: 'cy-riders-support@taxikosmos.test', role: 'support', name: 'Sue Support' }).then((a) => (admin = a));
        cy.make('admin', { email: 'cy-riders-dispatch@taxikosmos.test', role: 'dispatcher' });
        cy.make('admin', { email: 'cy-riders-finance@taxikosmos.test', role: 'finance' });
        cy.make('riders', { count: 23, prefix: 'Bulk Rider', registered_at: '2026-09-01 10:00:00' });
    });

    beforeEach(() => cy.loginAs('cy-riders-support@taxikosmos.test'));

    // Criterion: GET /admin/riders is paginated and filterable (Inertia page with props).
    it('pages through riders with the page in the URL', () => {
        cy.visit('/admin/riders?sort=name');
        cy.findByRole('heading', { name: 'Riders' }).should('be.visible');
        cy.contains('23 registered accounts').should('be.visible');
        rows().should('have.length', 10).first().should('contain', 'Bulk Rider 01');
        cy.contains('Showing').should('contain', '1–10').and('contain', 'of 23');

        cy.findByRole('button', { name: 'Next page' }).click();
        cy.location('search').should('contain', 'page=2');
        rows().should('have.length', 10).first().should('contain', 'Bulk Rider 11');

        cy.findByRole('button', { name: 'Next page' }).click();
        rows().should('have.length', 3);
        cy.findByRole('button', { name: 'Next page' }).should('be.disabled');

        cy.reload();
        rows().should('have.length', 3).first().should('contain', 'Bulk Rider 21');
        cy.go('back');
        cy.location('search').should('contain', 'page=2');
        rows().first().should('contain', 'Bulk Rider 11');
    });

    it('finds riders by name, email, rider code and by phone written any way', () => {
        cy.make<Rider>('rider', { name: 'Zara Zed', email: 'zara.zed@example.com', phone: '+37491770011' }).then((zara) => {
            const search = (term: string) => {
                cy.visit('/admin/riders');
                cy.findByLabelText('Search').clear().type(term);
                cy.location('search').should('contain', 'filter%5Bsearch%5D');
                rows().should('have.length', 1).and('contain', 'Zara Zed');
            };

            search('zara');
            search('ZED@EXAMPLE');
            search(zara.code);
            search(`r${zara.id}`);
            search('+37491770011');
            search('091 77 00 11');
            search('0037491770011');

            // A fragment of the phone matches nothing: the phone is encrypted, only exact lookups work.
            cy.visit('/admin/riders');
            cy.findByLabelText('Search').type('77001');
            rows().should('have.length', 1).and('contain', 'No riders found');
        });
    });

    it('filters by status and registration date, and clears the filters', () => {
        cy.make('rider', { name: 'Sid Suspended', status: 'suspended', registered_at: '2026-10-05 08:00:00' });
        cy.visit('/admin/riders');

        cy.findByLabelText('All Statuses').select('Suspended');
        cy.location('search').should('contain', 'filter%5Bstatus%5D=suspended');
        rows().should('have.length', 1).and('contain', 'Sid Suspended');

        cy.findByRole('button', { name: 'Clear filters' }).click();
        cy.findByLabelText('Registered from').type('2026-10-01');
        rows().each((row) => expect(row.text()).not.to.contain('Bulk Rider'));
        cy.contains('tbody tr', 'Sid Suspended').should('exist');

        cy.findByLabelText('Registered from').clear().type('2026-09-01');
        cy.findByLabelText('Registered to').type('2026-09-01');
        cy.contains('result').should('contain', '23 results');
    });

    it('sorts by name both ways from the column header', () => {
        cy.visit('/admin/riders?filter[search]=bulk');
        cy.contains('th button', 'Rider').click();
        cy.location('search').should('contain', 'sort=name');
        rows().first().should('contain', 'Bulk Rider 01');

        cy.contains('th button', 'Rider').click();
        cy.location('search').should('contain', 'sort=-name');
        rows().first().should('contain', 'Bulk Rider 23');
    });

    it('rejects malformed filters with a 400 instead of a crash', () => {
        ['filter[status]=banned', 'filter[registered_from]=yesterday', 'sort=password', 'filter[bogus]=1'].forEach((query) => {
            cy.request({ url: `/admin/riders?${query}`, failOnStatusCode: false }).its('status').should('eq', 400);
        });
    });

    // Criterion: GET /admin/riders/{id}.
    it('opens a rider profile from the list and 404s for ids that are not riders', () => {
        cy.make<Rider>('rider', { name: 'Paul Profile', email: 'paul@example.com', phone: '+37491770012' }).then((paul) => {
            cy.visit('/admin/riders');
            cy.findByLabelText('Search').type('Paul Profile');
            cy.findByRole('link', { name: 'Paul Profile' }).click();

            cy.location('pathname').should('eq', `/admin/riders/${paul.id}`);
            cy.findByRole('heading', { name: 'Paul Profile' }).should('be.visible');
            cy.contains('+37491770012 · paul@example.com').should('be.visible');
            cy.title().should('eq', 'Paul Profile · TaxiKosmos Admin');
        });

        cy.request({ url: `/admin/riders/${admin.id}`, failOnStatusCode: false }).its('status').should('eq', 404);
        cy.request({ url: '/admin/riders/999999999', failOnStatusCode: false }).its('status').should('eq', 404);
        cy.request({ url: '/admin/riders/99999999999999999999', failOnStatusCode: false }).its('status').should('eq', 404);
        cy.visit('/admin/riders/999999999', { failOnStatusCode: false });
        cy.findByRole('heading', { name: 'Page not found' }).should('be.visible');
    });

    // Criterion: PATCH /admin/riders/{id} (name, email, phone, locale — with reason, activity-logged).
    it('edits a rider with a reason, stores the phone in E.164 and audits the change without PII', () => {
        cy.make<Rider>('rider', { name: 'Eddie Edit', email: 'eddie@example.com', phone: '+37491770013' }).then((eddie) => {
            cy.intercept('PATCH', `/admin/riders/${eddie.id}`).as('save');
            cy.visit(`/admin/riders/${eddie.id}`);
            cy.findByRole('button', { name: 'Edit' }).click();

            cy.findByRole('dialog', { name: 'Edit Eddie Edit' }).within(() => {
                cy.findByLabelText('Name').should('have.value', 'Eddie Edit').clear().type('Eddie Edited');
                cy.findByRole('button', { name: 'Save changes' }).should('be.disabled');
                cy.findByLabelText('Email').clear().type('Eddie.New@Example.com');
                cy.findByLabelText('Phone').clear().type('093 77 00 14');
                cy.findByLabelText('Language').select('Русский');
                cy.findByRole('button', { name: 'Save changes' }).should('be.disabled');
                cy.findByLabelText(/Reason for the change/).type('Rider asked to update details');
                cy.findByRole('button', { name: 'Save changes' }).should('be.enabled').click();
            });

            cy.wait('@save').its('request.body').should('deep.equal', {
                name: 'Eddie Edited', email: 'Eddie.New@Example.com', phone: '093 77 00 14', locale: 'ru', reason: 'Rider asked to update details',
            });
            cy.findByRole('status').should('contain', 'Rider updated.');
            cy.findByRole('dialog').should('not.exist');

            cy.reload();
            cy.findByRole('heading', { name: 'Eddie Edited' }).should('be.visible');
            cy.contains('eddie.new@example.com').should('be.visible');
            cy.contains('+37493770014').should('be.visible');

            cy.sql('select name, email, locale, phone from users where id = ?', [eddie.id]).then(([row]) => {
                expect(row).to.include({ name: 'Eddie Edited', email: 'eddie.new@example.com', locale: 'ru' });
                expect(row.phone, 'phone is encrypted at rest').not.to.contain('77001');
            });

            auditFor('rider.updated', eddie.id).then((entries) => {
                expect(entries).to.have.length(1);
                expect(entries[0].causer_id).to.equal(admin.id);
                expect(entries[0].reason).to.equal('Rider asked to update details');
                const changes = JSON.stringify(entries[0].changes);
                expect(changes).to.contain('Eddie Edited').and.to.contain('changed');
                expect(changes, 'no email or phone in the audit diff').not.to.contain('eddie').and.not.to.contain('77001');
            });
        });
    });

    it('shows validation errors next to the field and saves nothing', () => {
        cy.make('rider', { name: 'Owner Of Email', email: 'taken@example.com', phone: '+37491770015' });
        cy.make<Rider>('rider', { name: 'Vera Valid', email: 'vera@example.com', phone: '+37491770016' }).then((vera) => {
            cy.visit(`/admin/riders/${vera.id}`);
            cy.findByRole('button', { name: 'Edit' }).click();

            const attempt = (field: string, value: string, message: RegExp) => {
                cy.findByRole('dialog').within(() => {
                    cy.findByLabelText(field).clear().type(value);
                    cy.findByLabelText(/Reason for the change/).clear().type('Testing validation');
                    cy.findByRole('button', { name: 'Save changes' }).click();
                    cy.findByLabelText(field).parent().find('[role=alert]').invoke('text').should('match', message);
                    cy.findByLabelText(field).clear().type(field === 'Phone' ? '+37491770016' : field === 'Email' ? 'vera@example.com' : 'Vera Valid');
                });
            };

            attempt('Email', 'taken@example.com', /already been taken/);
            attempt('Email', 'not-an-email', /valid email/);
            attempt('Phone', '+37491770015', /Another account already uses this phone number/);
            attempt('Phone', 'abc', /valid phone number/);
            attempt('Name', 'n'.repeat(300), /255/);

            cy.sql('select name, email from users where id = ?', [vera.id]).then(([row]) => expect(row).to.deep.equal({ name: 'Vera Valid', email: 'vera@example.com' }));
            auditFor('rider.updated', vera.id).should('have.length', 0);
        });
    });

    // Criterion: POST /admin/riders/{id}/suspend — reason required, Sanctum tokens revoked, user.suspended published.
    it('suspends a rider: reason required, the app session dies and user.suspended is published', () => {
        cy.make<Rider>('rider', { name: 'Mo Mobile', phone: '+37491770017' }).then((mo) => {
            // The rider is signed in to the mobile app.
            cy.api('POST', '/api/v1/auth/otp/request', { phone: '+37491770017', app: 'rider' }).its('status').should('eq', 202);
            cy.api('POST', '/api/v1/auth/otp/verify', { phone: '+37491770017', code: '123456', app: 'rider', device_id: 'cypress-phone-1' }).then((session) => {
                expect(session.status).to.equal(200);
                cy.sql('select count(*)::int as n from personal_access_tokens where tokenable_id = ?', [mo.id]).then(([row]) => expect(row.n).to.equal(1));

                cy.intercept('POST', `/admin/riders/${mo.id}/suspend`).as('suspend');
                cy.visit('/admin/riders');
                cy.findByLabelText('Search').type('Mo Mobile');
                cy.findByRole('button', { name: 'Actions for Mo Mobile' }).click();
                cy.findByRole('button', { name: /Suspend/ }).click();

                cy.findByRole('dialog', { name: 'Suspend Mo Mobile' }).within(() => {
                    cy.findByRole('button', { name: 'Confirm Suspension' }).should('be.disabled');
                    cy.findByLabelText(/Reason/).type('   ');
                    cy.findByRole('button', { name: 'Confirm Suspension' }).should('be.disabled');
                    cy.findByLabelText(/Reason/).type('Repeated no-shows');
                    cy.findByRole('button', { name: 'Confirm Suspension' }).dblclick();
                });

                cy.wait('@suspend');
                cy.findByRole('status').should('contain', 'Rider suspended.');
                cy.contains('tbody tr', 'Mo Mobile').should('contain', 'Suspended');

                cy.reload();
                cy.contains('tbody tr', 'Mo Mobile').should('contain', 'Suspended');
                cy.sql('select status, suspension_reason from users where id = ?', [mo.id]).then(([row]) => expect(row).to.deep.equal({ status: 'suspended', suspension_reason: 'Repeated no-shows' }));
                auditFor('rider.suspended', mo.id).then((entries) => {
                    expect(entries, 'a double-click suspends once').to.have.length(1);
                    expect(entries[0].reason).to.equal('Repeated no-shows');
                    expect(entries[0].causer_id).to.equal(admin.id);
                });

                // Tokens are gone: the app cannot refresh or use its old access token.
                cy.sql('select count(*)::int as n from personal_access_tokens where tokenable_id = ?', [mo.id]).then(([row]) => expect(row.n).to.equal(0));
                cy.api('POST', '/api/v1/auth/refresh', { refresh_token: session.body.refresh_token }).its('status').should('eq', 401);
                cy.api('POST', '/api/v1/auth/logout', undefined, session.body.access_token).its('status').should('eq', 401);

                cy.make('stream', { type: 'user.suspended' }).then((entries: Array<{ fields: Record<string, string> }>) => {
                    const forMo = entries.filter((entry) => JSON.parse(entry.fields.payload).user_id === mo.id);
                    expect(forMo).to.have.length(1);
                    expect(forMo[0].fields.type).to.equal('user.suspended');
                    expect(JSON.parse(forMo[0].fields.payload)).to.deep.equal({ user_id: mo.id, status: 'suspended' });
                });
            });
        });
    });

    // Criterion: POST /admin/riders/{id}/reactivate (reason required).
    it('reactivates a suspended rider from the profile with a reason', () => {
        cy.make<Rider>('rider', { name: 'Rae Return', status: 'suspended' }).then((rae) => {
            cy.visit(`/admin/riders/${rae.id}`);
            cy.contains('Suspended').should('be.visible');
            cy.findByRole('button', { name: 'Reactivate' }).click();

            cy.findByRole('dialog', { name: 'Reactivate Rae Return' }).within(() => {
                cy.findByRole('button', { name: 'Confirm Reactivation' }).should('be.disabled');
                cy.findByLabelText(/Reason/).type('Appeal accepted');
                cy.findByRole('button', { name: 'Confirm Reactivation' }).click();
            });

            cy.findByRole('status').should('contain', 'Rider reactivated.');
            cy.findByRole('button', { name: 'Suspend' }).should('be.visible');
            cy.sql('select status, suspension_reason from users where id = ?', [rae.id]).then(([row]) => expect(row).to.deep.equal({ status: 'active', suspension_reason: null }));
            auditFor('rider.reactivated', rae.id).then((entries) => {
                expect(entries).to.have.length(1);
                expect(entries[0].reason).to.equal('Appeal accepted');
            });
        });
    });

    it('refuses an empty reason and an invalid state change on the server too', () => {
        cy.make<Rider>('rider', { name: 'Stan State' }).then((stan) => {
            cy.visit(`/admin/riders/${stan.id}`);

            // The UI never sends an empty reason; the server refuses it anyway.
            cy.adminRequest('POST', `/admin/riders/${stan.id}/suspend`, { reason: '   ' }).its('status').should('eq', 302);
            cy.pageProps(`/admin/riders/${stan.id}`).its('errors.reason').should('match', /reason field is required/);

            cy.adminRequest('PATCH', `/admin/riders/${stan.id}`, { name: 'No Reason' }).its('status').should('eq', 303);
            cy.pageProps(`/admin/riders/${stan.id}`).its('errors.reason').should('match', /reason field is required/);

            // Reactivating someone who is not suspended is a conflict, shown as an error message.
            cy.adminRequest('POST', `/admin/riders/${stan.id}/reactivate`, { reason: 'Not suspended' }).its('status').should('eq', 302);
            cy.pageProps(`/admin/riders/${stan.id}`).its('flash.error').should('eq', 'Only suspended riders can be reactivated.');

            cy.sql('select name, status from users where id = ?', [stan.id]).then(([row]) => expect(row).to.deep.equal({ name: 'Stan State', status: 'active' }));
            cy.sql("select count(*)::int as n from activity_log where subject_id = ? and description like 'rider.%'", [stan.id]).then(([row]) => expect(row.n).to.equal(0));
        });
    });

    // Permissions: dispatcher and finance can view riders but not edit or suspend them.
    it('lets read-only roles view riders but refuses their edits and suspensions with 403', () => {
        cy.make<Rider>('rider', { name: 'Guarded Rider' }).then((rider) => {
            ['cy-riders-dispatch@taxikosmos.test', 'cy-riders-finance@taxikosmos.test'].forEach((email) => {
                cy.loginAs(email);
                cy.visit(`/admin/riders/${rider.id}`);
                cy.findByRole('heading', { name: 'Guarded Rider' }).should('be.visible');

                cy.adminRequest('PATCH', `/admin/riders/${rider.id}`, { name: 'Hacked', reason: 'x' }).its('status').should('eq', 403);
                cy.adminRequest('POST', `/admin/riders/${rider.id}/suspend`, { reason: 'x' }).its('status').should('eq', 403);
                cy.adminRequest('POST', `/admin/riders/${rider.id}/reactivate`, { reason: 'x' }).its('status').should('eq', 403);
            });

            cy.sql('select name, status from users where id = ?', [rider.id]).then(([row]) => expect(row).to.deep.equal({ name: 'Guarded Rider', status: 'active' }));
        });
    });
});

export {};
