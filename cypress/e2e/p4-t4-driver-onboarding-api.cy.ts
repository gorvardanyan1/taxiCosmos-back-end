/**
 * P4-T4 · Driver onboarding API (driver app): POST /api/v1/driver/application, PUT /profile, /vehicles, /documents (multipart), /bank-accounts,
 * and the status in GET /api/v1/me. The mobile calls are real HTTP against the running app with real OTP sign-ins; the admin side is driven in
 * the browser through the real login form. Uploads are sent as multipart from the page's own origin (cy.request cannot send files). A second
 * server with a 100 KB upload limit proves the size check; the real limit is 10 MB.
 */
const CODE = '123456';
const ADMIN = 'cypress-t4-support@taxikosmos.test';
const SMALL_SERVER = 'http://127.0.0.1:8007'; // DRIVER_DOCUMENT_MAX_KB=100
const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
const PDF = '%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF';

interface Reply<T = any> { status: number; body: T; headers: Headers }

const signIn = (phone: string, app: 'driver' | 'rider' = 'driver', base = '') =>
    cy.request({ method: 'POST', url: `${base}/api/v1/auth/otp/request`, body: { phone, app }, failOnStatusCode: false }).its('status').should('eq', 202).then(() =>
        cy.request({ method: 'POST', url: `${base}/api/v1/auth/otp/verify`, body: { phone, code: CODE, app, device_id: `cypress-t4-${app}` }, failOnStatusCode: false }).then((response) => {
            expect(response.status, `sign-in of ${phone}`).to.equal(200);
            return response.body.access_token as string;
        }));
const json = (method: string, path: string, token: string | null, body?: Record<string, unknown>, base = '') =>
    cy.request({ method, url: `${base}${path}`, body, failOnStatusCode: false, headers: { Accept: 'application/json', ...(token ? { Authorization: `Bearer ${token}` } : {}) } });
/** A multipart upload from the page's own origin (cy.request cannot send a file). `content` is a string or the bytes of a file. */
const upload = (token: string, type: string, name: string, content: string | Uint8Array, extra: Record<string, string> = {}, base = ''): Cypress.Chainable<Reply> =>
    cy.window().then((win): Cypress.Chainable<Reply> => {
        const form = new win.FormData();
        form.append('type', type);
        form.append('file', new win.Blob([content as BlobPart], { type: 'application/octet-stream' }), name);
        Object.entries(extra).forEach(([key, value]) => form.append(key, value));

        const reply: Promise<Reply> = win.fetch(`${base}/api/v1/driver/documents`, { method: 'POST', headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' }, body: form })
            .then(async (response) => ({ status: response.status, body: await response.json().catch(() => null), headers: response.headers }));

        return cy.wrap(reply, { log: false });
    });
const pngBytes = () => Uint8Array.from(atob(PNG_BASE64), (c) => c.charCodeAt(0));
const sql = (query: string, bindings: unknown[] = []) => cy.sql(query, bindings);
const profileOf = (userId: number) => sql('select id, verification_status, home_zone_id from driver_profiles where user_id = ?', [userId]).its('0');
const docs = (driverId: number) => sql('select id, type, status, vehicle_id, deleted_at is not null as replaced from driver_documents where driver_id = ? order by id', [driverId]);
const future = (years = 3) => new Date(Date.now() + years * 365 * 86400000).toISOString().slice(0, 10);
const vehicle = (over: Record<string, unknown> = {}) => ({ make: 'Toyota', model: 'Camry', year: 2020, color: 'White', plate_number: '01 AB 234', vehicle_class: 'economy', ...over });
const account = (over: Record<string, unknown> = {}) => ({ account_holder: 'Aram Sargsyan', bank_name: 'Ameriabank', account_number: 'AM00 1234 5678 9012', swift: 'ARMIAM22', ...over });
const card = (label: string) => cy.contains('p', new RegExp(`^${label}$`)).parents('div.rounded-2xl').first();

/** A person who has just signed in to the driver app: returns their user id and token (no application yet). */
const newDriver = (phone: string, name: string) =>
    cy.make<{ id: number }>('rider', { name, phone }).then((person) => signIn(phone).then((token) => ({ id: person.id, token })));

describe('P4-T4 · Driver onboarding API', () => {
    before(() => {
        cy.resetDb();
        cy.make('admin', { email: ADMIN, role: 'support', name: 'Sue Support' });
    });

    beforeEach(() => {
        Cypress.session.clearAllSavedSessions();
        cy.clearAllCookies();
        cy.make('clear_rate_limits');
        cy.visit('/login?lang=en');
    });

    // Criterion 1 + 4: sign-up to "all documents submitted" with only these endpoints; the status is visible through GET /me.
    it('takes a new driver from sign-up to all documents submitted using only the app endpoints, and the admin sees the application', () => {
        newDriver('+37491770040', 'Nora Newdriver').then(({ id, token }) => {
            json('POST', '/api/v1/driver/application', token).then((r) => { expect(r.status).to.equal(201); expect(r.body.data.verification_status).to.equal('pending'); });
            profileOf(id).then((p) => expect(p.verification_status).to.equal('pending'));
            json('PUT', '/api/v1/driver/profile', token, { license_number: 'DL-4040404', license_expiry: future(), home_zone_id: null }).then((r) => {
                expect(r.status).to.equal(200);
                expect(r.body.data.license.number_last4).to.equal('0404');
            });
            json('POST', '/api/v1/driver/vehicles', token, vehicle()).then((created) => {
                expect(created.status).to.equal(201);
                expect(created.body.data).to.include({ status: 'pending_review', plate_number: '01AB234', is_primary: false });
                const vehicleId = created.body.data.id as number;
                json('POST', '/api/v1/driver/bank-accounts', token, account()).its('status').should('eq', 201);

                // Nothing uploaded yet: GET /me and GET /documents both say everything is missing.
                json('GET', '/api/v1/me', token).then((me) => {
                    expect(me.body.user.driver.verification_status).to.equal('pending');
                    expect(me.body.user.driver.documents.map((d: { status: string }) => d.status)).to.deep.equal(['missing', 'missing', 'missing', 'missing']);
                });
                json('GET', '/api/v1/driver/documents', token).its('body.all_submitted').should('eq', false);

                upload(token, 'license', 'licence.pdf', PDF, { document_number: 'DL-4040404', expires_at: future() }).its('status').should('eq', 201);
                upload(token, 'id_card', 'id.png', pngBytes()).its('status').should('eq', 201);
                upload(token, 'vehicle_registration', 'registration.pdf', PDF, { vehicle_id: String(vehicleId) }).its('status').should('eq', 201);
                upload(token, 'insurance', 'insurance.pdf', PDF, { vehicle_id: String(vehicleId), expires_at: future(1) }).its('status').should('eq', 201);

                json('GET', '/api/v1/driver/documents', token).then((r) => {
                    expect(r.body.all_submitted).to.equal(true);
                    expect(r.body.data).to.have.length(4);
                    expect(r.body.data.map((d: { status: string }) => d.status)).to.deep.equal(['pending', 'pending', 'pending', 'pending']);
                    expect(JSON.stringify(r.body)).not.to.contain('drivers/').and.not.to.contain('DL-4040404');
                });
                json('GET', '/api/v1/me', token).then((me) => {
                    expect(me.body.user.driver.verification_status).to.equal('pending');
                    expect(me.body.user.driver.documents.map((d: { status: string }) => d.status)).to.deep.equal(['pending', 'pending', 'pending', 'pending']);
                });
            });

            // The admin sees the application: the pending filter lists the driver, and the Documents tab shows the four papers.
            profileOf(id).then((p) => {
                cy.loginAs(ADMIN);
                cy.visit('/admin/drivers?filter[verification_status]=pending&lang=en');
                cy.contains('Nora Newdriver').should('be.visible');
                cy.visit(`/admin/drivers/${p.id}?tab=documents&lang=en`);
                cy.findByRole('heading', { name: 'Nora Newdriver' }).should('be.visible');
                ["Driver's license", 'ID card', 'Vehicle registration', 'Insurance certificate'].forEach((label) => card(label).should('contain', 'Pending'));
                docs(p.id).should('have.length', 4);
            });
        });
    });

    it('refuses bad input with field errors, a missing application, a missing or wrong-app token, and saves nothing', () => {
        newDriver('+37491770041', 'Val Validator').then(({ token }) => {
            // No application yet: every other call says so.
            json('GET', '/api/v1/driver/vehicles', token).then((r) => { expect(r.status).to.equal(409); expect(r.body.code).to.equal('driver_application_required'); });
            json('POST', '/api/v1/driver/bank-accounts', token, account()).its('body.code').should('eq', 'driver_application_required');
            json('POST', '/api/v1/driver/application', null).its('status').should('eq', 401);
            json('POST', '/api/v1/driver/application', 'junk').its('status').should('eq', 401);
            signIn('+37491770042', 'rider').then((riderToken) => json('POST', '/api/v1/driver/application', riderToken).its('status').should('eq', 403));

            json('POST', '/api/v1/driver/application', token).its('status').should('eq', 201);
            const counts = () => sql('select (select count(*) from vehicles)::int as v, (select count(*) from driver_bank_accounts)::int as b, (select count(*) from driver_documents)::int as d').its('0');
            counts().then((before) => {
            const ok = { license_number: 'DL-4141414', license_expiry: future() };
            json('PUT', '/api/v1/driver/profile', token, {}).then((r) => expect(r.body.errors).to.have.keys('license_number', 'license_expiry'));
            json('PUT', '/api/v1/driver/profile', token, { ...ok, license_expiry: new Date().toISOString().slice(0, 10) }).its('body.errors').should('have.property', 'license_expiry');
            json('PUT', '/api/v1/driver/profile', token, { ...ok, license_number: 'ab' }).its('body.errors').should('have.property', 'license_number');
            json('PUT', '/api/v1/driver/profile', token, { ...ok, home_zone_id: 99999999 }).its('body.errors').should('have.property', 'home_zone_id');
            [{}, { year: 1979 }, { year: 2099 }, { vehicle_class: 'limo' }, { plate_number: 'a' }, { make: 'x'.repeat(65) }].forEach((over) => {
                json('POST', '/api/v1/driver/vehicles', token, Object.keys(over).length === 0 ? {} : vehicle(over)).its('status').should('eq', 422);
            });
            [{}, { account_number: '1234567' }, { swift: 'ABC' }, { account_holder: 'A' }].forEach((over) => {
                json('POST', '/api/v1/driver/bank-accounts', token, Object.keys(over).length === 0 ? {} : account(over)).its('status').should('eq', 422);
            });
            upload(token, 'passport', 'a.pdf', PDF).then((r) => expect(r.status).to.equal(422));
            upload(token, 'license', 'a.pdf', PDF, { expires_at: '2020-01-01' }).then((r) => expect(r.status).to.equal(422));
            upload(token, 'insurance', 'a.pdf', PDF, { vehicle_id: '99999999' }).then((r) => expect(r.status).to.equal(422));

            counts().should('deep.equal', before);
            json('GET', '/api/v1/driver/vehicles', token).its('body.data').should('have.length', 0);
            json('GET', '/api/v1/driver/documents', token).its('body.data').should('have.length', 0);
            json('GET', '/api/v1/driver/bank-accounts', token).its('body.data').should('have.length', 0);
            });
        });
    });

    // Criterion 3: type checked by MIME sniffing (not the extension).
    it('judges an upload by its content, not its name: scripts, text, executables and webp are refused, a real PNG under a wrong name is accepted', () => {
        newDriver('+37491770043', 'Mia Mime').then(({ id, token }) => {
            json('POST', '/api/v1/driver/application', token).its('status').should('eq', 201);
            const refused: Array<[string, string, string]> = [['a PHP script named .jpg', 'licence.jpg', '<?php echo "pwned";'], ['HTML named .pdf', 'licence.pdf', '<html><script>alert(1)</script></html>'],
                ['plain text named .png', 'licence.png', 'just some text'], ['an executable named .pdf', 'licence.pdf', 'MZ\u0090\u0000\u0003\u0000\u0000\u0000']];
            refused.forEach(([label, name, content]) => upload(token, 'license', name, content).then((r) => { expect(r.status, label).to.equal(422); expect(r.body.errors, label).to.have.property('file'); }));
            upload(token, 'license', 'licence.webp', 'RIFF\u0000\u0000\u0000\u0000WEBPVP8 ').then((r) => expect(r.status, 'webp').to.equal(422));
            profileOf(id).then((p) => docs(p.id).should('have.length', 0));

            upload(token, 'id_card', 'scan.txt', pngBytes()).then((r) => expect(r.status).to.equal(201));
            profileOf(id).then((p) => sql("select mime_type from driver_documents where driver_id = ?", [p.id]).its('0.mime_type').should('eq', 'image/png'));
        });
    });

    // Criterion 2: re-uploading a rejected document replaces only that document (the admin rejects it in the browser).
    it('replaces only the rejected document when the driver re-uploads it, and the others keep their status', () => {
        newDriver('+37491770044', 'Rex Rejected').then(({ id, token }) => {
            json('POST', '/api/v1/driver/application', token).its('status').should('eq', 201);
            upload(token, 'license', 'licence.pdf', PDF).its('status').should('eq', 201);
            upload(token, 'id_card', 'id.png', pngBytes()).its('status').should('eq', 201);

            profileOf(id).then((p) => {
                cy.intercept('POST', `/admin/drivers/${p.id}/documents/*/reject`).as('reject');
                cy.loginAs(ADMIN);
                cy.visit(`/admin/drivers/${p.id}?tab=documents&lang=en`);
                card("Driver's license").within(() => cy.findByRole('button', { name: /Reject/ }).click());
                cy.findByRole('dialog', { name: "Reject Driver's license" }).within(() => {
                    cy.findByLabelText(/Reason/).type('Photo is blurry, licence number unreadable');
                    cy.findByRole('button', { name: 'Confirm' }).click();
                });
                cy.wait('@reject').its('response.statusCode').should('be.oneOf', [302, 303]);
                cy.findAllByRole('status').last().should('contain', 'Document rejected.');
                cy.make('approve_documents', { driver_user_id: id, types: ['id_card'], reviewer_email: ADMIN });

                json('GET', '/api/v1/driver/documents', token).then((r) => {
                    expect(r.body.data.find((d: { type: string }) => d.type === 'license')).to.include({ status: 'rejected', rejection_reason: 'Photo is blurry, licence number unreadable' });
                    expect(r.body.data.find((d: { type: string }) => d.type === 'id_card').status).to.equal('approved');
                    expect(r.body.required.find((d: { type: string }) => d.type === 'license')).to.include({ status: 'rejected', reason: 'Photo is blurry, licence number unreadable' });
                    expect(r.body.all_submitted).to.equal(false);
                });
                docs(p.id).then((before) => {
                    const license = before.find((d) => d.type === 'license');
                    const idCard = before.find((d) => d.type === 'id_card');
                    upload(token, 'license', 'licence-new.pdf', PDF).then((again) => {
                        expect(again.status).to.equal(201);
                        docs(p.id).then((after) => {
                            expect(after.find((d) => d.id === license.id).replaced, 'the rejected document is replaced').to.equal(true);
                            expect(after.find((d) => d.id === idCard.id), 'the other document is untouched').to.include({ status: 'approved', replaced: false });
                            expect(after.find((d) => d.id === again.body.data.id)).to.include({ status: 'pending', replaced: false });
                        });
                    });
                });
                json('GET', '/api/v1/driver/documents', token).then((r) => {
                    expect(r.body.data).to.have.length(2);
                    expect(r.body.data.find((d: { type: string }) => d.type === 'license')).to.include({ status: 'pending', rejection_reason: null });
                });
                cy.visit(`/admin/drivers/${p.id}?tab=documents&lang=en`);
                card("Driver's license").should('contain', 'Pending').and('not.contain', 'blurry');
                card('ID card').should('contain', 'Approved');
            });
        });
    });

    // Every change to an approved licence or vehicle sends that item back to review, without blocking unrelated items.
    it('sends only the changed licence back to review, and only the changed vehicle with its own papers', () => {
        newDriver('+37491770045', 'Cal Changer').then(({ id, token }) => {
            json('POST', '/api/v1/driver/application', token).its('status').should('eq', 201);
            json('PUT', '/api/v1/driver/profile', token, { license_number: 'DL-4545454', license_expiry: future() }).its('status').should('eq', 200);
            json('POST', '/api/v1/driver/vehicles', token, vehicle({ plate_number: 'CH1' })).then((one) => json('POST', '/api/v1/driver/vehicles', token, vehicle({ plate_number: 'CH2' })).then((two) => {
                const [a, b] = [one.body.data.id as number, two.body.data.id as number];
                upload(token, 'license', 'l.pdf', PDF).its('status').should('eq', 201);
                upload(token, 'id_card', 'i.png', pngBytes()).its('status').should('eq', 201);
                upload(token, 'vehicle_registration', 'r1.pdf', PDF, { vehicle_id: String(a) }).its('status').should('eq', 201);
                upload(token, 'insurance', 'n2.pdf', PDF, { vehicle_id: String(b) }).its('status').should('eq', 201);
                cy.make('approve_documents', { driver_user_id: id, reviewer_email: ADMIN });
                cy.make('activate_vehicle', { vehicle_id: a, primary: true });
                cy.make('activate_vehicle', { vehicle_id: b });
                // Every required type is approved now (a paper counts for its type, whichever vehicle it is for), so the driver is verified.
                json('GET', '/api/v1/me', token).then((me) => { expect(me.body.user.driver.verification_status).to.equal('approved'); expect(me.body.user.driver.documents).to.have.length(0); });

                // The same licence again changes nothing; a new number sends the licence back and nothing else.
                json('PUT', '/api/v1/driver/profile', token, { license_number: 'DL-4545454', license_expiry: future() }).its('status').should('eq', 200);
                profileOf(id).then((p) => docs(p.id).then((rows) => expect(rows.filter((d) => d.status === 'pending')).to.have.length(0)));
                json('PUT', '/api/v1/driver/profile', token, { license_number: 'DL-4545455', license_expiry: future() }).its('status').should('eq', 200);
                profileOf(id).then((p) => docs(p.id).then((rows) => {
                    expect(rows.filter((d) => d.status === 'pending').map((d) => d.type)).to.deep.equal(['license']);
                    expect(rows.filter((d) => d.status === 'approved')).to.have.length(3);
                }));
                profileOf(id).its('verification_status').should('eq', 'pending');

                // Changing the first (approved) vehicle sends it and its own registration back; the other vehicle and the licence are untouched.
                json('PATCH', `/api/v1/driver/vehicles/${a}`, token, { color: 'Black' }).then((r) => {
                    expect(r.status).to.equal(200);
                    expect(r.body.data).to.include({ color: 'Black', status: 'pending_review', is_primary: false });
                });
                json('GET', '/api/v1/driver/vehicles', token).then((r) => {
                    expect(r.body.data.find((v: { id: number }) => v.id === a).status).to.equal('pending_review');
                    expect(r.body.data.find((v: { id: number }) => v.id === b).status).to.equal('active');
                });
                profileOf(id).then((p) => docs(p.id).then((rows) => {
                    expect(rows.find((d) => d.type === 'vehicle_registration').status).to.equal('pending');
                    expect(rows.find((d) => d.type === 'insurance').status).to.equal('approved');
                    expect(rows.find((d) => d.type === 'id_card').status).to.equal('approved');
                }));
            }));
        });
    });

    it('keeps drivers apart: another driver\'s vehicle and payout account are not found, and a vehicle on a trip cannot be removed', () => {
        newDriver('+37491770046', 'Ann Alone').then(({ token }) => newDriver('+37491770047', 'Ben Intruder').then((other) => {
            json('POST', '/api/v1/driver/application', token).its('status').should('eq', 201);
            json('POST', '/api/v1/driver/application', other.token).its('status').should('eq', 201);
            json('POST', '/api/v1/driver/vehicles', token, vehicle({ plate_number: 'ALONE1' })).then((v) => {
                json('POST', '/api/v1/driver/bank-accounts', token, account()).then((b) => {
                    json('PATCH', `/api/v1/driver/vehicles/${v.body.data.id}`, other.token, { color: 'Pink' }).its('status').should('eq', 404);
                    json('DELETE', `/api/v1/driver/vehicles/${v.body.data.id}`, other.token).its('status').should('eq', 404);
                    json('DELETE', `/api/v1/driver/bank-accounts/${b.body.data.id}`, other.token).its('status').should('eq', 404);
                    upload(other.token, 'insurance', 'n.pdf', PDF, { vehicle_id: String(v.body.data.id) }).then((r) => expect(r.status).to.equal(422));
                    json('GET', '/api/v1/driver/vehicles', other.token).its('body.data').should('have.length', 0);
                    json('GET', '/api/v1/driver/bank-accounts', other.token).its('body.data').should('have.length', 0);
                    // The plate is taken platform-wide; the same car sent again by its owner is the same car.
                    json('POST', '/api/v1/driver/vehicles', other.token, vehicle({ plate_number: 'ALONE1' })).its('status').should('eq', 422);
                    json('POST', '/api/v1/driver/vehicles', token, vehicle({ plate_number: 'ALONE1' })).then((again) => { expect(again.status).to.equal(200); expect(again.body.data.id).to.equal(v.body.data.id); });
                    sql('select count(*)::int as n from vehicles where plate_number = ?', ['ALONE1']).its('0.n').should('eq', 1);
                    json('DELETE', `/api/v1/driver/vehicles/${v.body.data.id}`, token).its('status').should('eq', 204);
                    json('GET', '/api/v1/driver/vehicles', token).its('body.data').should('have.length', 0);
                });
            });
        }));
    });

    it('stores payout accounts encrypted and returns them masked: the first is the default, a retry is the same account, the limit is 409, removal promotes the newest', () => {
        newDriver('+37491770048', 'Pia Payout').then(({ id, token }) => {
            json('POST', '/api/v1/driver/application', token).its('status').should('eq', 201);
            json('POST', '/api/v1/driver/bank-accounts', token, account()).then((first) => {
                expect(first.status).to.equal(201);
                expect(first.body.data).to.include({ account_last4: '9012', is_default: true, verified: false, swift: 'ARMIAM22' });
                expect(JSON.stringify(first.body)).not.to.contain('AM001234');
                sql('select account_number, account_last4 from driver_bank_accounts where id = ?', [first.body.data.id]).then((rows) => {
                    expect(rows[0].account_number).not.to.contain('AM00').and.not.to.contain('9012');
                    expect(rows[0].account_last4).to.equal('9012');
                });
                json('POST', '/api/v1/driver/bank-accounts', token, account({ account_number: 'AM00123456789012' })).then((again) => { expect(again.status).to.equal(200); expect(again.body.data.id).to.equal(first.body.data.id); });
                const ids: number[] = [first.body.data.id];
                ['AM00222222222222', 'AM00333333333333', 'AM00444444444444', 'AM00555555555555'].forEach((number) => json('POST', '/api/v1/driver/bank-accounts', token, account({ account_number: number })).then((r) => { expect(r.status).to.equal(201); expect(r.body.data.is_default).to.equal(false); ids.push(r.body.data.id); }));
                json('POST', '/api/v1/driver/bank-accounts', token, account({ account_number: 'AM00666666666666' })).then((r) => { expect(r.status).to.equal(409); expect(r.body.code).to.equal('bank_account_limit_reached'); });
                cy.then(() => json('DELETE', `/api/v1/driver/bank-accounts/${ids[0]}`, token).its('status').should('eq', 204));
                cy.then(() => sql('select id, is_default from driver_bank_accounts where deleted_at is null and driver_id = (select id from driver_profiles where user_id = ?) order by id', [id]).then((rows) => {
                    expect(rows.filter((r) => r.is_default).map((r) => r.id)).to.deep.equal([ids[4]]);
                }));
            });
            sql("select count(*)::int as n from activity_log where description = 'driver.self.bank_account_added' and causer_id = ?", [id]).its('0.n').should('eq', 5);
        });
    });

    it('stops a suspended driver on every onboarding call', () => {
        newDriver('+37491770049', 'Sam Suspended').then(({ id, token }) => {
            json('POST', '/api/v1/driver/application', token).its('status').should('eq', 201);
            cy.make('set_user_status', { id, status: 'suspended', silent: true });
            json('POST', '/api/v1/driver/vehicles', token, vehicle({ plate_number: 'SUSP1' })).then((r) => { expect(r.status).to.equal(403); expect(r.body.code).to.equal('account_suspended'); });
            json('POST', '/api/v1/driver/bank-accounts', token, account()).its('status').should('eq', 403);
            upload(token, 'license', 'l.pdf', PDF).then((r) => expect(r.status).to.equal(403));
            sql('select count(*)::int as n from vehicles where plate_number = ?', ['SUSP1']).its('0.n').should('eq', 0);
        });
    });

    it('rate limits uploads per driver with Retry-After', () => {
        newDriver('+37491770050', 'Rita Limited').then(({ token }) => {
            json('POST', '/api/v1/driver/application', token).its('status').should('eq', 201);
            for (let i = 0; i < 20; i++) {
                upload(token, 'id_card', 'i.png', pngBytes()).then((r) => expect(r.status, `upload ${i + 1}`).to.equal(201));
            }
            upload(token, 'id_card', 'i.png', pngBytes()).then((r) => {
                expect(r.status).to.equal(429);
                expect(Number(r.headers.get('retry-after'))).to.be.within(1, 60);
            });
        });
    });

    // Criterion 3: size-limited. The second server allows 100 KB; the real default is 10 MB (tested in PHPUnit).
    describe('with the upload limit lowered to 100 KB (DRIVER_DOCUMENT_MAX_KB=100)', { baseUrl: SMALL_SERVER }, () => {
        before(() => {
            cy.task('e2e:server:start', { port: 8007, env: { DRIVER_DOCUMENT_MAX_KB: '100' } });
            cy.request({ url: `${SMALL_SERVER}/up`, retryOnNetworkFailure: true, failOnStatusCode: false }).its('status').should('eq', 200);
        });

        after(() => {
            cy.task('e2e:server:stop');
        });

        it('refuses a file over the limit and accepts one just under it', () => {
            cy.visit('/login');
            newDriverOn('+37491770051', 'Sia Sized').then(({ id, token }) => {
                json('POST', '/api/v1/driver/application', token).its('status').should('eq', 201);
                const header = '%PDF-1.4\n';
                upload(token, 'license', 'big.pdf', header + 'a'.repeat(102400 + 1 - header.length)).then((r) => { expect(r.status).to.equal(422); expect(r.body.errors).to.have.property('file'); });
                profileOf(id).then((p) => docs(p.id).should('have.length', 0));
                upload(token, 'license', 'ok.pdf', header + 'a'.repeat(102400 - header.length)).then((r) => expect(r.status).to.equal(201));
                profileOf(id).then((p) => docs(p.id).should('have.length', 1));
            });
        });
    });
});

/** Same as newDriver, but the OTP calls go to the page's own server (the 100 KB one). */
function newDriverOn(phone: string, name: string) {
    return cy.make<{ id: number }>('rider', { name, phone }).then((person) => signIn(phone, 'driver', SMALL_SERVER).then((token) => ({ id: person.id, token })));
}

export {};
