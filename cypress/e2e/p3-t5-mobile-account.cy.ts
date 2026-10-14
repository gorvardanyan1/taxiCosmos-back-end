/**
 * P3-T5 · Mobile account & profile API: GET/PATCH /api/v1/me, the phone change by code, the blocked-account 403 with a reason
 * code, and the rider's saved places. Real server, real OTP sign-ins (the whitelisted test phones take the fixed code), the real
 * database. The admin part (suspending, rejecting a document) uses the real admin session where an action is needed.
 */
const CODE = '123456';

interface Session { access_token: string; refresh_token: string; user: { id: number } }

const signIn = (phone: string, app: 'driver' | 'rider' = 'rider') =>
    cy.api('POST', '/api/v1/auth/otp/request', { phone, app }).its('status').should('eq', 202).then(() =>
        cy.api('POST', '/api/v1/auth/otp/verify', { phone, code: CODE, app, device_id: `cypress-${app}-${phone}` }).then((response) => {
            expect(response.status, `sign-in of ${phone}`).to.equal(200);
            return response.body as Session;
        }));

const place = (override: Record<string, unknown> = {}) => ({ label: 'home', name: 'Home', address: '12 Abovyan St, Yerevan', lat: 40.1792, lng: 44.5133, ...override });
const userRow = (id: number) => cy.sql('select name, email, email_verified_at is not null as verified, locale, status, phone_hash from users where id = ?', [id]).its('0');

describe('P3-T5 · Mobile account & profile API', () => {
    before(() => {
        cy.resetDb();
        cy.make('admin', { email: 'cypress-account-admin@taxikosmos.test', role: 'super_admin', name: 'Account Admin' });
    });

    beforeEach(() => cy.make('clear_rate_limits'));

    // Criterion: GET /me returns the documented shape (rider).
    it('returns the rider shape with a masked phone and no secrets, and refuses a guest', () => {
        signIn('+37491520041').then((session) => {
            cy.api('GET', '/api/v1/me', undefined, session.access_token).then((r) => {
                expect(r.status).to.equal(200);
                expect(Object.keys(r.body)).to.deep.equal(['user']);
                expect(r.body.user).to.deep.include({ id: session.user.id, phone: '+374*****041', status: 'active', email: null, email_verified: false });
                expect(r.body.user.modes).to.deep.equal({ is_rider: true, is_driver: false });
                expect(r.body.user.driver).to.equal(null);
                expect(r.body.user.rider).to.deep.equal({ rating: { average: null, count: 0 }, outstanding_balance: { amount: 0, currency: 'AMD' }, default_payment_method: null });
                const text = JSON.stringify(r.body);
                ['37491520041', 'phone_hash', 'password', 'two_factor'].forEach((secret) => expect(text).not.to.contain(secret));
            });
            // The sign-in answer already carried this same user.
            cy.api('GET', '/api/v1/me', undefined, session.access_token).its('body.user.id').should('eq', session.user.id);
        });
        cy.api('GET', '/api/v1/me').its('status').should('eq', 401);
        cy.api('GET', '/api/v1/me', undefined, 'junk').its('status').should('eq', 401);
    });

    // Criterion: GET /me for a pending driver, with the missing / rejected documents and their reasons.
    it('shows a pending driver which documents wait for review and which were rejected, with the reason', () => {
        cy.make<{ driver_id: number; user_id: number; documents: { id: number; type: string }[] }>('driver_with_documents', { name: 'Pending Pavel', phone: '+37491520047', types: ['license', 'id_card'] }).then((driver) => {
            signIn('+37491520047', 'driver').then((session) => {
                cy.api('GET', '/api/v1/me', undefined, session.access_token).then((r) => {
                    const block = r.body.user.driver;
                    expect(r.body.user.modes).to.deep.equal({ is_rider: false, is_driver: true });
                    expect(block.verification_status).to.equal('pending');
                    expect(block.availability).to.equal('offline');
                    expect(block.debt_limit_reached).to.equal(false);
                    expect(block.primary_vehicle).to.equal(null);
                    const byType = Object.fromEntries(block.documents.map((d: { type: string; status: string }) => [d.type, d.status]));
                    expect(byType).to.deep.equal({ license: 'pending', id_card: 'pending', vehicle_registration: 'missing', insurance: 'missing' });
                });

                // A real admin rejects the licence; the driver sees why.
                cy.loginAs('cypress-account-admin@taxikosmos.test');
                const license = driver.documents.find((d) => d.type === 'license')!;
                cy.adminRequest('POST', `/admin/drivers/${driver.driver_id}/documents/${license.id}/reject`, { reason: 'The photo is blurry' }).its('status').should('eq', 302);

                cy.api('GET', '/api/v1/me', undefined, session.access_token).then((r) => {
                    const rejected = r.body.user.driver.documents.find((d: { type: string }) => d.type === 'license');
                    expect(rejected).to.deep.equal({ type: 'license', status: 'rejected', reason: 'The photo is blurry' });
                    expect(r.body.user.driver.verification_status).to.equal('rejected');
                });
            });
        });
    });

    // Criterion: a suspended user gets 403 with a machine-readable reason code on every endpoint except /me, logout and support.
    it('refuses a blocked account everywhere but /me and logout, with the reason code', () => {
        signIn('+37491520048').then((session) => {
            cy.make('set_user_status', { id: session.user.id, status: 'suspended', silent: true });
            const token = session.access_token;

            cy.api('GET', '/api/v1/me', undefined, token).then((r) => {
                expect(r.status).to.equal(200);
                expect(r.body.user.status).to.equal('suspended');
            });

            const refused: [string, string, Record<string, unknown> | undefined][] = [
                ['PATCH', '/api/v1/me', { name: 'Blocked' }],
                ['GET', '/api/v1/saved-places', undefined],
                ['POST', '/api/v1/saved-places', place()],
                ['POST', '/api/v1/devices', { push_token: 'ExponentPushToken[blocked-token-1]', platform: 'ios' }],
                ['GET', '/api/v1/geo/autocomplete?q=abc', undefined],
                ['POST', '/api/v1/trips/fare-estimate', {}],
            ];
            refused.forEach(([method, path, body]) => cy.api(method, path, body, token).then((r) => {
                expect(r.status, `${method} ${path}`).to.equal(403);
                expect(r.body).to.deep.equal({ message: 'This account is suspended.', code: 'account_suspended' });
            }));
            userRow(session.user.id).then((row) => expect(row.name).not.to.equal('Blocked'));
            cy.sql('select count(*)::int as n from saved_places where user_id = ?', [session.user.id]).its('0.n').should('eq', 0);

            cy.api('POST', '/api/v1/auth/logout', undefined, token).its('status').should('eq', 204);
        });
    });

    it('ends the sessions when the admin suspends, and then the sign-in answers with the same reason code', () => {
        signIn('+37491520049').then((session) => {
            cy.make('set_user_status', { id: session.user.id, status: 'suspended' });

            cy.api('GET', '/api/v1/me', undefined, session.access_token).its('status').should('eq', 401);
            cy.make('clear_rate_limits');
            cy.api('POST', '/api/v1/auth/otp/request', { phone: '+37491520049', app: 'rider' }).its('status').should('eq', 202);
            cy.api('POST', '/api/v1/auth/otp/verify', { phone: '+37491520049', code: CODE, app: 'rider', device_id: 'cypress-rider-again' }).then((r) => {
                expect(r.status).to.equal(403);
                expect(r.body).to.deep.equal({ message: 'This account is suspended.', code: 'account_suspended' });
            });
        });
    });

    // Criterion: PATCH /me updates name, email, locale.
    it('updates name, email and language, never touches anything else, and refuses bad input without saving', () => {
        signIn('+37491520051').then((session) => {
            const token = session.access_token;
            cy.api('PATCH', '/api/v1/me', { name: 'Ani Hakobyan', email: 'Ani.Cypress@Example.com', locale: 'hy', status: 'suspended', is_driver: true, phone: '+37499000000' }, token).then((r) => {
                expect(r.status).to.equal(200);
                expect(r.body.user).to.deep.include({ name: 'Ani Hakobyan', email: 'ani.cypress@example.com', locale: 'hy', email_verified: false, status: 'active' });
                expect(r.body.user.modes.is_driver).to.equal(false);
            });
            userRow(session.user.id).then((row) => expect(row).to.deep.include({ name: 'Ani Hakobyan', email: 'ani.cypress@example.com', locale: 'hy', status: 'active', verified: false }));

            [
                [{ name: '' }, 'name'], [{ name: 'x'.repeat(121) }, 'name'], [{ email: 'not-an-email' }, 'email'], [{ locale: 'fr' }, 'locale'],
            ].forEach(([body, field]) => cy.api('PATCH', '/api/v1/me', body as Record<string, unknown>, token).then((r) => {
                expect(r.status, JSON.stringify(body)).to.equal(422);
                expect(r.body.errors).to.have.property(field as string);
            }));
            userRow(session.user.id).then((row) => expect(row).to.deep.include({ name: 'Ani Hakobyan', email: 'ani.cypress@example.com', locale: 'hy' }));

            // An address another account holds, in any case.
            signIn('+37491520052').then((other) => {
                cy.api('PATCH', '/api/v1/me', { email: 'ANI.cypress@example.com' }, other.access_token).then((r) => {
                    expect(r.status).to.equal(422);
                    expect(r.body.errors).to.have.property('email');
                });
            });
        });
        cy.api('PATCH', '/api/v1/me', { name: 'Nobody' }).its('status').should('eq', 401);
    });

    // Criterion: phone change requires OTP on the new number and keeps phone_hash unique.
    it('changes the phone only after the code sent to the new number, and keeps one account per number', () => {
        signIn('+37491520042').then((session) => {
            const token = session.access_token;
            userRow(session.user.id).then((before) => {
                cy.api('POST', '/api/v1/me/phone/request', { phone: '+37491520053' }, token).then((r) => {
                    expect(r.status).to.equal(202);
                    expect(r.body.expires_in).to.equal(300);
                });
                userRow(session.user.id).then((row) => expect(row.phone_hash, 'unchanged until the code is entered').to.equal(before.phone_hash));

                cy.api('POST', '/api/v1/me/phone/verify', { phone: '+37491520053', code: '000000' }, token).then((r) => {
                    expect(r.status).to.equal(422);
                    expect(r.body.errors).to.have.property('code');
                });
                userRow(session.user.id).then((row) => expect(row.phone_hash).to.equal(before.phone_hash));

                cy.api('POST', '/api/v1/me/phone/verify', { phone: '+37491520053', code: CODE }, token).then((r) => {
                    expect(r.status).to.equal(200);
                    expect(r.body.user.phone).to.equal('+374*****053');
                });
                userRow(session.user.id).then((row) => expect(row.phone_hash, 'a new blind index').not.to.equal(before.phone_hash));
                cy.api('GET', '/api/v1/me', undefined, token).its('body.user.phone').should('eq', '+374*****053');
                cy.sql('select count(*)::int as n from users where id = ? or phone_hash = ?', [session.user.id, before.phone_hash]).its('0.n').should('eq', 1);

                // The code was used up.
                cy.api('POST', '/api/v1/me/phone/verify', { phone: '+37491520053', code: CODE }, token).its('status').should('eq', 422);
            });
        });
    });

    it('gives no code and no hint for a number another account holds, and refuses the current number and a bad one', () => {
        signIn('+37491520044').then(() => {
            signIn('+37491520045').then((session) => {
                const token = session.access_token;
                cy.make('clear_rate_limits'); // the sign-ins above used this number's one-code-a-minute allowance
                cy.api('POST', '/api/v1/me/phone/request', { phone: '+37491520044' }, token).then((taken) => {
                    expect(taken.status).to.equal(202);
                    expect(taken.body.expires_in).to.equal(300);
                });
                // No code exists for the taken number, so even the fixed test code is refused.
                cy.api('POST', '/api/v1/me/phone/verify', { phone: '+37491520044', code: CODE }, token).its('status').should('eq', 422);
                userRow(session.user.id).then((row) => cy.sql('select count(*)::int as n from users where phone_hash = ?', [row.phone_hash]).its('0.n').should('eq', 1));

                cy.api('POST', '/api/v1/me/phone/request', { phone: '+37491520045' }, token).then((r) => {
                    expect(r.status).to.equal(422);
                    expect(r.body.errors).to.have.property('phone');
                });
                cy.api('POST', '/api/v1/me/phone/request', { phone: 'abc' }, token).its('status').should('eq', 422);
                cy.api('POST', '/api/v1/me/phone/verify', { phone: '+37491520054', code: '12' }, token).its('status').should('eq', 422);
            });
        });
        cy.api('POST', '/api/v1/me/phone/request', { phone: '+37491520054' }).its('status').should('eq', 401);
    });

    it('limits code requests to one a minute', () => {
        signIn('+37491520055').then((session) => {
            cy.make('clear_rate_limits');
            cy.api('POST', '/api/v1/me/phone/request', { phone: '+37491520056' }, session.access_token).its('status').should('eq', 202);
            cy.api('POST', '/api/v1/me/phone/request', { phone: '+37491520056' }, session.access_token).then((r) => {
                expect(r.status).to.equal(429);
                expect(r.headers).to.have.property('retry-after');
            });
        });
    });

    // Criterion: saved places: max 20 per user, validated coordinates.
    it('creates, reads, changes and deletes a saved place, stored as a PostGIS point', () => {
        signIn('+37491520046').then((session) => {
            const token = session.access_token;
            cy.api('POST', '/api/v1/saved-places', place(), token).then((r) => {
                expect(r.status).to.equal(201);
                expect(r.body.data).to.deep.include({ label: 'home', name: 'Home', address: '12 Abovyan St, Yerevan', lat: 40.1792, lng: 44.5133 });
                const id = r.body.data.id;
                cy.sql('select user_id, ST_X(location::geometry) as lng, ST_Y(location::geometry) as lat from saved_places where id = ?', [id]).its('0').should('deep.include', { user_id: session.user.id, lng: 44.5133, lat: 40.1792 });
                cy.api('GET', '/api/v1/saved-places', undefined, token).its('body.data').should('have.length', 1);
                cy.api('GET', `/api/v1/saved-places/${id}`, undefined, token).its('body.data.name').should('eq', 'Home');
                cy.api('PATCH', `/api/v1/saved-places/${id}`, { label: 'work', name: 'Office', lat: 40.2, lng: 44.6 }, token).then((u) => {
                    expect(u.status).to.equal(200);
                    expect(u.body.data).to.deep.include({ label: 'work', name: 'Office', lat: 40.2, lng: 44.6, address: '12 Abovyan St, Yerevan' });
                });
                cy.sql('select label, ST_X(location::geometry) as lng from saved_places where id = ?', [id]).its('0').should('deep.include', { label: 'work', lng: 44.6 });
                cy.api('DELETE', `/api/v1/saved-places/${id}`, undefined, token).its('status').should('eq', 204);
                cy.api('GET', `/api/v1/saved-places/${id}`, undefined, token).its('status').should('eq', 404);
                cy.sql('select count(*)::int as n from saved_places where id = ?', [id]).its('0.n').should('eq', 0);
            });
        });
    });

    it('refuses invalid coordinates and fields without saving anything', () => {
        signIn('+37491520057').then((session) => {
            const token = session.access_token;
            [
                [{ lat: 90.0001 }, 'lat'], [{ lat: -91 }, 'lat'], [{ lng: 180.5 }, 'lng'], [{ lng: -181 }, 'lng'], [{ lat: 'north' }, 'lat'],
                [{ lat: null }, 'lat'], [{ label: 'gym' }, 'label'], [{ name: '' }, 'name'], [{ name: 'n'.repeat(81) }, 'name'], [{ address: '' }, 'address'],
            ].forEach(([override, field]) => cy.api('POST', '/api/v1/saved-places', place(override as Record<string, unknown>), token).then((r) => {
                expect(r.status, JSON.stringify(override)).to.equal(422);
                expect(r.body.errors).to.have.property(field as string);
            }));
            cy.api('POST', '/api/v1/saved-places', place({ lat: 90, lng: 180 }), token).its('status').should('eq', 201); // the boundary itself
            cy.sql('select count(*)::int as n from saved_places where user_id = ?', [session.user.id]).its('0.n').should('eq', 1);
        });
    });

    it('allows twenty places and refuses the twenty-first with a reason code, per person', () => {
        signIn('+37491520058').then((session) => {
            const token = session.access_token;
            for (let i = 1; i <= 20; i++) cy.api('POST', '/api/v1/saved-places', place({ label: 'other', name: `Place ${i}` }), token).its('status').should('eq', 201);
            cy.api('POST', '/api/v1/saved-places', place({ name: 'One too many' }), token).then((r) => {
                expect(r.status).to.equal(422);
                expect(r.body.code).to.equal('saved_places_limit_reached');
            });
            cy.sql('select count(*)::int as n from saved_places where user_id = ?', [session.user.id]).its('0.n').should('eq', 20);

            // Another person is not affected, and deleting frees a slot.
            signIn('+37491520059').then((other) => cy.api('POST', '/api/v1/saved-places', place(), other.access_token).its('status').should('eq', 201));
            cy.api('GET', '/api/v1/saved-places', undefined, token).its('body.data.0.id').then((first) => cy.api('DELETE', `/api/v1/saved-places/${first}`, undefined, token).its('status').should('eq', 204));
            cy.api('POST', '/api/v1/saved-places', place({ name: 'Fits now' }), token).its('status').should('eq', 201);
        });
    });

    it('keeps one rider\'s places away from another and from the driver app and guests', () => {
        signIn('+37491520061').then((owner) => {
            cy.api('POST', '/api/v1/saved-places', place({ name: 'Private' }), owner.access_token).then((created) => {
                const id = created.body.data.id;
                signIn('+37491520062').then((stranger) => {
                    cy.api('GET', `/api/v1/saved-places/${id}`, undefined, stranger.access_token).its('status').should('eq', 404);
                    cy.api('PATCH', `/api/v1/saved-places/${id}`, { name: 'Stolen' }, stranger.access_token).its('status').should('eq', 404);
                    cy.api('DELETE', `/api/v1/saved-places/${id}`, undefined, stranger.access_token).its('status').should('eq', 404);
                    cy.api('GET', '/api/v1/saved-places', undefined, stranger.access_token).its('body.data').should('deep.equal', []);
                    // The owner cannot be chosen in the request.
                    cy.api('POST', '/api/v1/saved-places', place({ user_id: owner.user.id, name: 'Mine' }), stranger.access_token).then((r) => {
                        expect(r.status).to.equal(201);
                        cy.sql('select user_id from saved_places where id = ?', [r.body.data.id]).its('0.user_id').should('eq', stranger.user.id);
                    });
                });
                cy.sql('select name from saved_places where id = ?', [id]).its('0.name').should('eq', 'Private');
            });
        });
        cy.api('GET', '/api/v1/saved-places').its('status').should('eq', 401);
        cy.make<{ phone: string }>('driver', { name: 'Driver Dan', phone: '+37491520063' }).then(() => {
            signIn('+37491520063', 'driver').then((driver) => cy.api('GET', '/api/v1/saved-places', undefined, driver.access_token).its('status').should('eq', 403));
        });
    });
});

export {};
