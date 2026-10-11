/**
 * P3-T3 · Mobile auth: phone OTP + social login (Sanctum), through the real /api/v1 HTTP API.
 * The e2e environment lists +37491770001…39 as test phones with the fixed code 123456 (.env.e2e);
 * any other number gets a random code that only the SMS log sees.
 * Social sign-in cannot be completed without real Google/Apple/Facebook tokens: the spec checks the
 * three provider routes exist and fail safely when unconfigured; token verification is covered by
 * PHPUnit with signed test tokens.
 */
const CODE = '123456';
const otpRequest = (phone: string, app = 'rider') => cy.api('POST', '/api/v1/auth/otp/request', { phone, app });
const otpVerify = (phone: string, code = CODE, device = 'cypress-device-1', app = 'rider') =>
    cy.api('POST', '/api/v1/auth/otp/verify', { phone, code, app, device_id: device });
const signIn = (phone: string, device = 'cypress-device-1', app = 'rider') =>
    otpRequest(phone, app).its('status').should('eq', 202).then(() => otpVerify(phone, CODE, device, app).then((response) => {
        expect(response.status, `sign-in of ${phone}`).to.equal(200);
        return response.body;
    }));
const tokensOf = (userId: number) => cy.sql('select count(*)::int as n from personal_access_tokens where tokenable_id = ?', [userId]).its('0.n');

describe('P3-T3 · Mobile auth API', () => {
    before(() => cy.resetDb());
    beforeEach(() => cy.make('clear_rate_limits'));

    // Criterion: SMS goes through SmsSender; the e2e/local log driver sends nothing and masks the number.
    it('answers a code request identically for known and unknown phones, and only logs the SMS', () => {
        cy.make('rider', { name: 'Known Phone', phone: '+37491770001' });

        otpRequest('+37491770001').then((known) => {
            otpRequest('+37491770002').then((unknown) => {
                expect(known.status).to.equal(202);
                expect(unknown.status).to.equal(202);
                expect(unknown.body).to.deep.equal(known.body);
                expect(known.body).to.deep.equal({ message: 'If the number can receive a code, it is on its way.', expires_in: 300 });
            });
        });

        cy.readFile('storage/logs/e2e.log').then((log: string) => {
            const lines = log.split('\n').filter((line) => line.includes('SMS (log driver, not sent)')).slice(-2);
            expect(lines).to.have.length(2);
            lines.forEach((line) => {
                expect(line).to.match(/"to":"\+374\*+00[12]"/);
                expect(line).not.to.contain('91770001').and.not.to.contain('91770002');
            });
        });
        cy.sql("select count(*)::int as n from users where name is null").its('0.n').should('eq', 0);
    });

    // Criterion: OTP codes are 6 digits, expire after 5 minutes, are stored hashed, and die after 5 wrong attempts.
    it('stores only a hash of the code, which expires and dies after five wrong guesses', () => {
        otpRequest('+37491770003').its('status').should('eq', 202);
        cy.sql('select code_hash, attempts, extract(epoch from (expires_at - created_at))::int as ttl from otp_codes order by id desc limit 1').then(([otp]) => {
            expect(otp.code_hash).to.match(/^[0-9a-f]{64}$/).and.not.contain(CODE);
            expect(otp.ttl).to.equal(300);
            expect(otp.attempts).to.equal(0);
        });

        for (let attempt = 1; attempt <= 5; attempt++) {
            otpVerify('+37491770003', '000000').then((response) => {
                expect(response.status).to.equal(422);
                expect(response.body.errors.code).to.deep.equal(['The code is invalid or has expired.']);
            });
        }
        otpVerify('+37491770003').its('status').should('eq', 422);
        cy.sql("select count(*)::int as n from users where phone_hash is not null").its('0.n').should('eq', 1); // only the known phone from the first test

        // Four wrong guesses still leave the code usable.
        cy.make('clear_rate_limits');
        otpRequest('+37491770004');
        for (let attempt = 1; attempt <= 4; attempt++) otpVerify('+37491770004', '000000').its('status').should('eq', 422);
        otpVerify('+37491770004').its('status').should('eq', 200);

        // An expired code is refused.
        otpRequest('+37491770005');
        cy.make('expire_otp', { phone: '+37491770005' });
        otpVerify('+37491770005').its('status').should('eq', 422);
    });

    // Criterion: a verified OTP for a new phone creates exactly one user, never a duplicate on a retried request.
    it('creates one rider for a new phone and never a second on retries or new codes', () => {
        otpRequest('+37491770006');
        otpVerify('+37491770006', CODE, 'cypress-phone').then((first) => {
            expect(first.status).to.equal(200);
            expect(Object.keys(first.body).sort()).to.deep.equal(['access_token', 'access_token_expires_at', 'needs_phone', 'refresh_token', 'user']);
            expect(first.body.needs_phone).to.equal(false);
            expect(first.body.user).to.include({ phone: '+37491770006', is_rider: true, is_driver: false, status: 'active' });
            expect(first.body.refresh_token).to.match(/^[A-Za-z0-9_-]{86}$/);
            const minutes = (Date.parse(first.body.access_token_expires_at) - Date.now()) / 60_000;
            expect(minutes).to.be.within(58, 60);

            // The app retries the same request after a timeout.
            otpVerify('+37491770006', CODE, 'cypress-phone').its('status').should('eq', 422);

            // Signing in again later (after the one-minute resend window) finds the same account, and the driver app adds the driver flag.
            cy.make('clear_rate_limits');
            signIn('+37491770006', 'cypress-driver-phone', 'driver').then((again) => {
                expect(again.user.id).to.equal(first.body.user.id);
                expect(again.user.is_driver).to.equal(true);
                expect(again.user.is_rider).to.equal(true);
            });

            cy.sql('select count(*)::int as n from users where id = ? or (phone_hash is not null and id <> ? and created_at > now() - interval \'1 minute\' and name is null)', [first.body.user.id, first.body.user.id]).its('0.n').should('eq', 1);
            cy.sql("select abilities from personal_access_tokens where tokenable_id = ? order by id", [first.body.user.id]).then((rows) => {
                expect(rows.map((row) => JSON.parse(row.abilities))).to.deep.equal([['rider'], ['driver']]);
            });
        });
    });

    // Criterion: OTP requests are rate-limited distinctly from OTP verification attempts.
    it('throttles code requests and code guesses separately, with Retry-After', () => {
        otpRequest('+37491770007').its('status').should('eq', 202);
        otpRequest('091 77 00 07').then((throttled) => {
            expect(throttled.status, 'the same number written differently shares the budget').to.equal(429);
            expect(Number(throttled.headers['retry-after'])).to.be.within(1, 60);
        });

        // Requests are exhausted, guesses are not: the code still signs in.
        otpVerify('+37491770007').its('status').should('eq', 200);

        // Guessing has its own limit, and hitting it does not block asking for a code.
        for (let guess = 1; guess <= 10; guess++) otpVerify('+37491770008', '000000').its('status').should('eq', 422);
        otpVerify('+37491770008', '000000').its('status').should('eq', 429);
        otpRequest('+37491770008').its('status').should('eq', 202);
    });

    // Criterion: in local/testing (and e2e) a fixed test code works for listed test phones only.
    it('accepts the fixed code only for listed test phones', () => {
        otpRequest('+37493000001').its('status').should('eq', 202);
        otpVerify('+37493000001', CODE).its('status').should('eq', 422);
        cy.sql("select count(*)::int as n from personal_access_tokens").its('0.n').then((before) => {
            signIn('+37491770009');
            cy.sql("select count(*)::int as n from personal_access_tokens").its('0.n').should('eq', Number(before) + 1);
        });
    });

    // Criterion: revoking one device's token never logs out the user's other devices unless /logout-all.
    it('logs out one device without touching the others, and logout-all ends them all', () => {
        signIn('+37491770010', 'cypress-phone').then((phone) => {
            cy.make('clear_rate_limits');
            signIn('+37491770010', 'cypress-tablet').then((tablet) => {
                cy.api('POST', '/api/v1/auth/logout', undefined, phone.access_token).its('status').should('eq', 204);
                cy.api('POST', '/api/v1/auth/refresh', { refresh_token: phone.refresh_token }).its('status').should('eq', 401);
                tokensOf(phone.user.id).should('eq', 1);

                cy.api('POST', '/api/v1/auth/refresh', { refresh_token: tablet.refresh_token }).then((rotated) => {
                    expect(rotated.status).to.equal(200);
                    cy.make('clear_rate_limits');
                    signIn('+37491770010', 'cypress-watch').then((watch) => {
                        cy.api('POST', '/api/v1/auth/logout-all', undefined, watch.access_token).its('status').should('eq', 204);
                        tokensOf(phone.user.id).should('eq', 0);
                        cy.api('POST', '/api/v1/auth/refresh', { refresh_token: rotated.body.refresh_token }).its('status').should('eq', 401);
                        cy.api('POST', '/api/v1/auth/logout', undefined, rotated.body.access_token).its('status').should('eq', 401);
                    });
                });
            });
        });
    });

    // Criterion: refresh-token rotation and reuse detection.
    it('rotates the refresh token and revokes the whole chain when a used one is replayed', () => {
        signIn('+37491770011', 'cypress-rotate').then((first) => {
            cy.api('POST', '/api/v1/auth/refresh', { refresh_token: first.refresh_token }).then((second) => {
                expect(second.status).to.equal(200);
                expect(second.body.refresh_token).not.to.equal(first.refresh_token);
                expect(second.body.access_token).not.to.equal(first.access_token);
                cy.api('POST', '/api/v1/auth/logout', undefined, first.access_token).its('status').should('eq', 401);

                cy.api('POST', '/api/v1/auth/refresh', { refresh_token: first.refresh_token }).its('status').should('eq', 401);
                cy.api('POST', '/api/v1/auth/refresh', { refresh_token: second.body.refresh_token }).its('status').should('eq', 401);
                cy.api('POST', '/api/v1/auth/logout', undefined, second.body.access_token).its('status').should('eq', 401);
            });
            cy.sql('select token_hash from refresh_tokens where user_id = ?', [first.user.id]).then((rows) => {
                rows.forEach((row) => expect(row.token_hash).to.match(/^[0-9a-f]{64}$/).and.not.equal(first.refresh_token));
            });
        });

        signIn('+37491770012', 'cypress-expiry').then((session) => {
            cy.make('expire_access_token', { token: session.access_token });
            cy.api('POST', '/api/v1/auth/logout', undefined, session.access_token).its('status').should('eq', 401);
        });
    });

    // Criterion: a suspended user cannot obtain a new token, and existing tokens are revoked at suspension time.
    it('revokes every session on suspension and refuses new sign-ins', () => {
        signIn('+37491770013', 'cypress-susp').then((session) => {
            tokensOf(session.user.id).should('eq', 1);
            cy.make('set_user_status', { id: session.user.id, status: 'suspended' });

            tokensOf(session.user.id).should('eq', 0);
            cy.api('POST', '/api/v1/auth/refresh', { refresh_token: session.refresh_token }).its('status').should('eq', 401);

            cy.make('clear_rate_limits'); // past the one-minute resend window
            otpRequest('+37491770013').its('status').should('eq', 202);
            otpVerify('+37491770013').then((refused) => {
                expect(refused.status).to.equal(403);
                expect(refused.body).to.deep.equal({ message: 'This account is suspended.' });
            });
            tokensOf(session.user.id).should('eq', 0);
        });
    });

    it('never gives a mobile token to an admin, and never accepts an admin browser session', () => {
        cy.make('admin', { email: 'cy-mobile-admin@taxikosmos.test', role: 'admin', phone: '+37491770015' }).then((admin) => {
            otpRequest('+37491770015').its('status').should('eq', 202);
            otpVerify('+37491770015').then((refused) => {
                expect(refused.status).to.equal(403);
                expect(refused.body).to.deep.equal({ message: 'Admin accounts cannot sign in to the apps.' });
            });
            tokensOf(admin.id).should('eq', 0);
        });

        // Signed in to the admin panel in this browser: the session cookie means nothing to /api/v1.
        cy.loginAs('cy-mobile-admin@taxikosmos.test');
        cy.visit('/admin');
        ['logout', 'logout-all'].forEach((path) => {
            cy.request({ method: 'POST', url: `/api/v1/auth/${path}`, failOnStatusCode: false, headers: { Accept: 'application/json' } }).its('status').should('eq', 401);
        });
    });

    // Criterion: Sign in with Apple is offered alongside Google and Facebook.
    it('exposes Google, Apple and Facebook sign-in, failing safely while unconfigured', () => {
        ['google', 'apple', 'facebook'].forEach((provider) => {
            cy.api('POST', `/api/v1/auth/social/${provider}`, { token: 'not-a-real-token', app: 'rider', device_id: 'cypress-social' }).then((response) => {
                expect(response.status, provider).to.equal(503);
                expect(response.body.message).to.equal('Sign-in with this provider is not available right now.');
            });
            cy.api('POST', `/api/v1/auth/social/${provider}`, { app: 'rider', device_id: 'cypress-social' }).then((response) => {
                expect(response.status).to.equal(422);
                expect(response.body.errors).to.have.property('token');
            });
        });
        cy.api('POST', '/api/v1/auth/social/twitter', { token: 'x', app: 'rider', device_id: 'cypress-social' }).its('status').should('eq', 404);
        cy.sql("select count(*)::int as n from social_accounts").its('0.n').should('eq', 0);
    });

    it('lets a social account without a phone add one, and only then gives it a full session', () => {
        cy.make('pending_mobile_session', { name: 'Pat Pending', email: 'pat@example.com' }).then((pending) => {
            expect(pending.needs_phone).to.equal(true);

            otpRequest('+37491770014');
            cy.api('POST', '/api/v1/auth/phone/verify', { phone: '+37491770014', code: '000000' }, pending.access_token).its('status').should('eq', 422);
            cy.api('POST', '/api/v1/auth/phone/verify', { phone: '091 77 00 14', code: CODE }, pending.access_token).then((full) => {
                expect(full.status).to.equal(200);
                expect(full.body.needs_phone).to.equal(false);
                expect(full.body.user).to.include({ id: pending.user_id, phone: '+37491770014' });
                cy.api('POST', '/api/v1/auth/logout', undefined, pending.access_token).its('status').should('eq', 401);
                cy.sql("select abilities from personal_access_tokens where tokenable_id = ?", [pending.user_id]).then((rows) => {
                    expect(rows.map((row) => JSON.parse(row.abilities))).to.deep.equal([['rider']]);
                });
            });
        });
    });
});

export {};
