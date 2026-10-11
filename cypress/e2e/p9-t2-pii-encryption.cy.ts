/**
 * P9-T2 · PII encryption, as far as it is visible end to end: personal data typed into the app is
 * stored encrypted (Laravel ciphertext in the database), shown decrypted in the app, and found or
 * de-duplicated only through the blind-index hash. (Key rotation and the blind-index key are
 * config/console concerns covered by PHPUnit; a driver search by licence number arrives with the
 * real drivers list, P4-T2.)
 */
const CIPHERTEXT = /^eyJpdiI6/; // base64 of {"iv": … — Laravel's encrypted payload

describe('P9-T2 · PII encryption', () => {
    before(() => {
        cy.resetDb();
        cy.make('admin', { email: 'cy-pii-support@taxikosmos.test', role: 'support' });
    });

    beforeEach(() => cy.loginAs('cy-pii-support@taxikosmos.test'));

    // Criteria: encrypted casts on sensitive columns; unreadable in the database, readable in the app.
    it('stores a phone typed in the mobile app encrypted, and the admin sees it decrypted', () => {
        cy.api('POST', '/api/v1/auth/otp/request', { phone: '091 77 00 21', app: 'rider' }).its('status').should('eq', 202);
        cy.api('POST', '/api/v1/auth/otp/verify', { phone: '0037491770021', code: '123456', app: 'rider', device_id: 'cypress-pii' }).then((session) => {
            expect(session.status).to.equal(200);
            const id = session.body.user.id;

            cy.sql('select phone, phone_hash from users where id = ?', [id]).then(([row]) => {
                expect(row.phone).to.match(CIPHERTEXT);
                expect(row.phone).not.to.contain('91770021');
                expect(row.phone_hash).to.match(/^[0-9a-f]{64}$/);
            });
            cy.sql('select count(*)::int as n from users where phone like ? or phone_hash like ?', ['%770021%', '%770021%']).its('0.n').should('eq', 0);

            cy.visit(`/admin/riders/${id}`);
            cy.contains('+37491770021').should('be.visible');
        });
    });

    it('keeps licence numbers encrypted at rest with a hash next to them', () => {
        cy.make('driver_with_documents', { name: 'Lena Licence', types: ['license'] }).then((driver) => {
            cy.sql('select license_number, license_number_hash from driver_profiles where id = ?', [driver.driver_id]).then(([row]) => {
                expect(row.license_number).to.match(CIPHERTEXT);
                expect(row.license_number).not.to.contain(String(driver.license_number).replace(/\D/g, '').slice(0, 6));
                expect(row.license_number_hash).to.match(/^[0-9a-f]{64}$/);
            });
            cy.sql('select phone from users where id = ?', [driver.user_id]).its('0.phone').should('match', CIPHERTEXT);
        });
    });

    // Criterion: phone_hash (unique, from the E.164 number) is used by admin search and to keep phones unique.
    it('finds a rider by the exact phone in any spelling through the hash, never by a fragment', () => {
        cy.make('rider', { name: 'Hash Hunter', phone: '+37491770022' });

        // "91770022" is the national number without the trunk 0: a complete spelling, not a fragment.
        ['+37491770022', '091 77 00 22', '0037491770022', '+374 91-77-00-22', '91770022'].forEach((spelling) => {
            cy.visit(`/admin/riders?filter[search]=${encodeURIComponent(spelling)}`);
            cy.get('tbody tr').should('have.length', 1).and('contain', 'Hash Hunter');
        });
        ['770022', '+3749177002', '9177002', '0022'].forEach((fragment) => {
            cy.visit(`/admin/riders?filter[search]=${encodeURIComponent(fragment)}`);
            cy.get('tbody tr').should('have.length', 1).and('contain', 'No riders found');
        });
    });

    it('refuses a second account with the same phone written another way', () => {
        cy.make('rider', { name: 'First Owner', phone: '+37491770023' });
        cy.make('rider', { name: 'Second Rider', phone: '+37491770024' }).then((second) => {
            cy.visit(`/admin/riders/${second.id}`);
            cy.findByRole('button', { name: 'Edit' }).click();
            cy.findByRole('dialog').within(() => {
                cy.findByLabelText('Phone').clear().type('091 77 00 23');
                cy.findByLabelText(/Reason for the change/).type('Rider says this is their number');
                cy.findByRole('button', { name: 'Save changes' }).click();
                cy.findByLabelText('Phone').parent().find('[role=alert]').should('have.text', 'Another account already uses this phone number.');
            });
            cy.sql("select count(*)::int as n from users where phone_hash = (select phone_hash from users where name = 'First Owner')").its('0.n').should('eq', 1);
        });

        // The mobile sign-in with that phone lands on the existing account, never a new one.
        cy.api('POST', '/api/v1/auth/otp/request', { phone: '+374 91 77 00 23', app: 'rider' }).its('status').should('eq', 202);
        cy.api('POST', '/api/v1/auth/otp/verify', { phone: '091770023', code: '123456', app: 'rider', device_id: 'cypress-pii-2' }).its('body.user.name').should('eq', 'First Owner');
    });
});

export {};
