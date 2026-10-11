/**
 * P2-T3 · Driver document upload & verification workflow (admin side).
 * Approve / reject with reason, automatic driver verification, audit entries, signed expiring file
 * links, permissions and conflicts. (Uploads come from the driver app, P4-T4; files are created by
 * the fixture.)
 */
interface DriverFixture {
    driver_id: number;
    user_id: number;
    name: string;
    documents: Array<{ id: number; type: string }>;
}

const LICENSE = "Driver's license";
const INSURANCE = 'Insurance certificate';
const card = (label: string) => cy.contains('p', new RegExp(`^${label}$`)).parents('div.rounded-2xl').first();
const documentRow = (id: number) => cy.sql('select status, reviewed_by, reviewed_at, rejection_reason from driver_documents where id = ?', [id]).then((rows) => rows[0]);
const verification = (driverId: number) => cy.sql('select verification_status, approved_by from driver_profiles where id = ?', [driverId]).then((rows) => rows[0]);

describe('P2-T3 · Driver documents', () => {
    let support: { id: number };

    before(() => {
        cy.resetDb();
        cy.make('admin', { email: 'cy-docs-support@taxikosmos.test', role: 'support', name: 'Dora Docs' }).then((a) => (support = a));
        cy.make('admin', { email: 'cy-docs-dispatch@taxikosmos.test', role: 'dispatcher' });
        cy.make('admin', { email: 'cy-docs-auditor@taxikosmos.test', role: 'admin' });
    });

    beforeEach(() => cy.loginAs('cy-docs-support@taxikosmos.test'));

    // Criteria: approve endpoint, verification sync when all required documents are approved, audit for every action.
    it('approves every required document and the driver becomes verified', () => {
        cy.make<DriverFixture>('driver_with_documents', { name: 'Vic Verified' }).then((driver) => {
            cy.visit(`/admin/drivers/${driver.driver_id}?tab=documents`);
            cy.findByRole('heading', { name: 'Vic Verified' }).should('be.visible');
            cy.findByRole('tab', { name: 'Documents' }).should('have.attr', 'aria-selected', 'true');
            cy.get('main').contains('Pending').should('exist');

            [LICENSE, 'ID card', 'Vehicle registration', INSURANCE].forEach((label, index) => {
                cy.intercept('POST', `/admin/drivers/${driver.driver_id}/documents/${driver.documents[index].id}/approve`).as(`approve${index}`);
                card(label).within(() => cy.findByRole('button', { name: /Approve/ }).click());
                cy.wait(`@approve${index}`);
                cy.findAllByRole('status').last().should('contain', 'Document approved.');
                card(label).within(() => {
                    cy.contains('Approved').should('be.visible');
                    cy.findByRole('button', { name: /Approve/ }).should('not.exist');
                });
            });

            cy.reload();
            cy.get('main').contains('Pending').should('not.exist');
            cy.get('h1').parent().parent().should('contain', 'Approved');

            driver.documents.forEach((document) => documentRow(document.id).then((row) => {
                expect(row.status).to.equal('approved');
                expect(row.reviewed_by).to.equal(support.id);
                expect(row.reviewed_at).not.to.equal(null);
            }));
            verification(driver.driver_id).then((row) => expect(row).to.deep.equal({ verification_status: 'approved', approved_by: support.id }));

            cy.sql("select description, causer_id from activity_log where description like 'driver.%' and (subject_id = any(?) or subject_id = ?) order by id", [`{${driver.documents.map((d) => d.id).join(',')}}`, driver.driver_id]).then((entries) => {
                expect(entries.map((e) => e.description)).to.deep.equal([
                    'driver.document.approved', 'driver.document.approved', 'driver.document.approved', 'driver.document.approved', 'driver.verification.changed',
                ]);
                entries.forEach((entry) => expect(entry.causer_id).to.equal(support.id));
            });

            cy.make('stream', { type: 'driver.verification_changed' }).then((entries: Array<{ fields: Record<string, string> }>) => {
                const forDriver = entries.map((e) => JSON.parse(e.fields.payload)).filter((p) => p.driver_profile_id === driver.driver_id);
                expect(forDriver, 'one driver.verification_changed event').to.have.length(1);
                expect(forDriver[0]).to.deep.equal({ driver_user_id: driver.user_id, driver_profile_id: driver.driver_id, from_status: 'pending', to_status: 'approved' });
            });
        });
    });

    it('does not verify the driver while one required document is still pending', () => {
        cy.make<DriverFixture>('driver_with_documents', { name: 'Paula Partial' }).then((driver) => {
            cy.visit(`/admin/drivers/${driver.driver_id}?tab=documents`);
            [LICENSE, 'ID card', 'Vehicle registration'].forEach((label) => {
                card(label).within(() => cy.findByRole('button', { name: /Approve/ }).click());
                card(label).should('contain', 'Approved');
            });

            card(INSURANCE).should('contain', 'Pending');
            verification(driver.driver_id).its('verification_status').should('eq', 'pending');
        });
    });

    // Criterion: POST reject with a required reason.
    it('rejects a document only with a reason, and shows the reason on the card', () => {
        cy.make<DriverFixture>('driver_with_documents', { name: 'Rex Rejected', types: ['license', 'insurance'] }).then((driver) => {
            const license = driver.documents[0];
            cy.intercept('POST', `/admin/drivers/${driver.driver_id}/documents/${license.id}/reject`).as('reject');
            cy.visit(`/admin/drivers/${driver.driver_id}?tab=documents`);

            card(LICENSE).within(() => cy.findByRole('button', { name: /Reject/ }).click());
            cy.findByRole('dialog', { name: `Reject ${LICENSE}` }).within(() => {
                cy.findByRole('button', { name: 'Confirm' }).should('be.disabled');
                cy.findByLabelText(/Reason/).type('   ');
                cy.findByRole('button', { name: 'Confirm' }).should('be.disabled');
                cy.findByLabelText(/Reason/).clear().type('Photo is blurry, licence number unreadable');
                cy.findByRole('button', { name: 'Confirm' }).dblclick();
            });

            cy.wait('@reject').its('request.body').should('deep.equal', { reason: 'Photo is blurry, licence number unreadable' });
            cy.findAllByRole('status').last().should('contain', 'Document rejected.');
            cy.findByRole('dialog').should('not.exist');
            card(LICENSE).should('contain', 'Rejected').and('contain', 'Photo is blurry, licence number unreadable');

            cy.reload();
            card(LICENSE).should('contain', 'Photo is blurry, licence number unreadable');
            documentRow(license.id).then((row) => expect(row).to.include({ status: 'rejected', rejection_reason: 'Photo is blurry, licence number unreadable', reviewed_by: support.id }));
            verification(driver.driver_id).its('verification_status').should('not.eq', 'approved');
            cy.sql("select properties->>'reason' as reason from activity_log where description = 'driver.document.rejected' and subject_id = ?", [license.id]).then((entries) => {
                expect(entries, 'a double-click rejects once').to.have.length(1);
                expect(entries[0].reason).to.equal('Photo is blurry, licence number unreadable');
            });
        });
    });

    it('refuses a rejection without a reason on the server and reviews a document only once', () => {
        cy.make<DriverFixture>('driver_with_documents', { name: 'Ona Once', types: ['license'] }).then((driver) => {
            const doc = driver.documents[0];
            cy.visit(`/admin/drivers/${driver.driver_id}?tab=documents`);

            cy.adminRequest('POST', `/admin/drivers/${driver.driver_id}/documents/${doc.id}/reject`, { reason: '' }).its('status').should('eq', 302);
            cy.pageProps(`/admin/drivers/${driver.driver_id}?tab=documents`).its('errors.reason').should('match', /reason field is required/);
            documentRow(doc.id).its('status').should('eq', 'pending');

            cy.adminRequest('POST', `/admin/drivers/${driver.driver_id}/documents/${doc.id}/approve`).its('status').should('eq', 302);
            cy.adminRequest('POST', `/admin/drivers/${driver.driver_id}/documents/${doc.id}/reject`, { reason: 'Too late' }).its('status').should('eq', 302);
            cy.pageProps(`/admin/drivers/${driver.driver_id}?tab=documents`).its('flash.error').should('eq', 'This document has already been reviewed.');
            documentRow(doc.id).its('status').should('eq', 'approved');

            // A document id under another driver is a 404, never a cross-driver review.
            cy.make<DriverFixture>('driver_with_documents', { name: 'Other Driver', types: ['license'] }).then((other) => {
                cy.adminRequest('POST', `/admin/drivers/${driver.driver_id}/documents/${other.documents[0].id}/approve`).its('status').should('eq', 404);
                documentRow(other.documents[0].id).its('status').should('eq', 'pending');
            });
        });
    });

    // Criterion: document files are reachable only through signed, expiring URLs.
    it('serves the file only through the signed link, privately and as the stored type', () => {
        cy.make<DriverFixture>('driver_with_documents', { name: 'Fay File', types: ['license'] }).then((driver) => {
            const doc = driver.documents[0];
            cy.visit(`/admin/drivers/${driver.driver_id}?tab=documents`);

            card(LICENSE).within(() => {
                cy.findByRole('link', { name: /View/ }).should('have.attr', 'target', '_blank').invoke('attr', 'href').then((href) => {
                    expect(href).to.match(new RegExp(`^/admin/drivers/${driver.driver_id}/documents/${doc.id}/file\\?expires=\\d+&signature=[0-9a-f]{64}$`));

                    cy.request(href!).then((response) => {
                        expect(response.status).to.equal(200);
                        expect(response.headers['content-type']).to.equal('application/pdf');
                        expect(response.headers['x-content-type-options']).to.equal('nosniff');
                        expect(response.headers['cache-control']).to.contain('no-store');
                        expect(response.body).to.contain('%PDF-1.4');
                    });

                    const unsigned = href!.split('?')[0];
                    cy.request({ url: unsigned, failOnStatusCode: false }).its('status').should('eq', 403);
                    cy.request({ url: href!.replace(/signature=([0-9a-f])/, (_m, c) => `signature=${c === 'a' ? 'b' : 'a'}`), failOnStatusCode: false }).its('status').should('eq', 403);
                    cy.request({ url: href!.replace(/expires=(\d+)/, (_m, t) => `expires=${Number(t) + 3600}`), failOnStatusCode: false }).its('status').should('eq', 403);
                });
            });

            cy.make('signed_document_url', { driver_id: driver.driver_id, document_id: doc.id, minutes: -1 }).then(({ path }) => {
                cy.request({ url: path, failOnStatusCode: false }).its('status').should('eq', 403);
            });
            cy.make('signed_document_url', { driver_id: driver.driver_id, document_id: doc.id, minutes: 5 }).then(({ path }) => {
                cy.request(path).its('status').should('eq', 200);
                // Signed, but a guest: the admin session is still required.
                cy.clearAllCookies();
                cy.request({ url: path, failOnStatusCode: false, followRedirect: false }).its('status').should('be.oneOf', [302, 303]);
            });
        });
    });

    // Permission: drivers.view without drivers.verify sees the documents but cannot review them.
    it('lets a dispatcher see documents but refuses reviews with 403', () => {
        cy.make<DriverFixture>('driver_with_documents', { name: 'Dee Dispatch', types: ['license'] }).then((driver) => {
            cy.loginAs('cy-docs-dispatch@taxikosmos.test');
            cy.visit(`/admin/drivers/${driver.driver_id}?tab=documents`);
            card(LICENSE).should('contain', 'Pending');

            cy.adminRequest('POST', `/admin/drivers/${driver.driver_id}/documents/${driver.documents[0].id}/approve`).its('status').should('eq', 403);
            cy.adminRequest('POST', `/admin/drivers/${driver.driver_id}/documents/${driver.documents[0].id}/reject`, { reason: 'x' }).its('status').should('eq', 403);
            documentRow(driver.documents[0].id).its('status').should('eq', 'pending');
            cy.sql("select count(*)::int as n from activity_log where description like 'driver.document.%' and subject_id = ?", [driver.documents[0].id]).its('0.n').should('eq', 0);
        });
    });

    it('shows the review in the Activity Log with actor and reason', () => {
        cy.make<DriverFixture>('driver_with_documents', { name: 'Al Audit', types: ['insurance'] }).then((driver) => {
            cy.visit(`/admin/drivers/${driver.driver_id}?tab=documents`);
            card(INSURANCE).within(() => cy.findByRole('button', { name: /Reject/ }).click());
            cy.findByRole('dialog').within(() => {
                cy.findByLabelText(/Reason/).type('Policy expired last month');
                cy.findByRole('button', { name: 'Confirm' }).click();
            });
            cy.findAllByRole('status').last().should('contain', 'Document rejected.');

            // Support cannot open the Activity Log (no activity_log.view); an admin can.
            cy.request({ url: '/admin/activity-logs', failOnStatusCode: false }).its('status').should('eq', 403);
            cy.loginAs('cy-docs-auditor@taxikosmos.test');
            cy.visit('/admin/activity-logs');
            cy.findByLabelText('All actions').select('driver.document.rejected');
            cy.contains('tbody tr', 'Policy expired last month').should('contain', 'Dora Docs').and('contain', 'driver.document.rejected');
        });
    });
});

export {};
