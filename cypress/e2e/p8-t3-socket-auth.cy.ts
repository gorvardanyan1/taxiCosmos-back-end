/**
 * P8-T3 · Socket service authentication contract: POST /api/v1/internal/socket-auth (called by the Node service with the shared
 * service token) and GET /admin/socket-ticket. Real server, real OTP sign-ins, the real database. The e2e .env sets
 * SOCKET_SERVICE_TOKEN; the extra server on :8002 allows another address only, to prove the address check.
 */
const SERVICE_TOKEN = 'e2e-socket-service-token-0123456789abcdef';
const CODE = '123456';
const ADMIN_EMAIL = 'cypress-socket-admin@taxikosmos.test';
const OTHER_ADMIN_EMAIL = 'cypress-socket-admin-2@taxikosmos.test';

const signIn = (phone: string, app: 'driver' | 'rider' = 'driver') =>
    cy.api('POST', '/api/v1/auth/otp/request', { phone, app }).its('status').should('eq', 202).then(() =>
        cy.api('POST', '/api/v1/auth/otp/verify', { phone, code: CODE, app, device_id: `cypress-socket-${app}` }).then((response) => {
            expect(response.status, `sign-in of ${phone}`).to.equal(200);
            return response.body as { access_token: string; refresh_token: string };
        }));

const socketAuth = (body: Record<string, unknown>, serviceToken: string | null = SERVICE_TOKEN, base = '') =>
    cy.request({
        method: 'POST',
        url: `${base}/api/v1/internal/socket-auth`,
        body,
        failOnStatusCode: false,
        headers: { Accept: 'application/json', ...(serviceToken === null ? {} : { 'X-Service-Token': serviceToken }) },
    });

describe('P8-T3 · Socket service authentication contract', () => {
    let driver: { user_id: number; phone: string };
    let rider: { id: number; phone: string };

    before(() => {
        cy.resetDb();
        cy.make('admin', { email: ADMIN_EMAIL, role: 'support', name: 'Sock Admin' });
        cy.make('admin', { email: OTHER_ADMIN_EMAIL, role: 'dispatcher', name: 'Other Admin' });
        cy.make('driver', { name: 'Sock Driver', phone: '+37491770011' }).then((d) => (driver = d));
        cy.make('rider', { name: 'Sock Rider', phone: '+37491770012' }).then((r) => (rider = r));
    });

    beforeEach(() => cy.make('clear_rate_limits'));

    // Criterion: the internal endpoint rejects calls without the service token.
    it('rejects a call without the right service token, with no identity in the answer', () => {
        signIn(driver.phone).then(({ access_token }) => {
            [null, '', 'wrong-service-token-0123456789abcdef', access_token].forEach((serviceToken) => {
                socketAuth({ token: access_token }, serviceToken).then((response) => {
                    expect(response.status, `service token ${serviceToken}`).to.equal(401);
                    expect(response.body).to.deep.equal({ message: 'Unauthenticated.' });
                });
            });
            // The bearer form of the same service token is accepted.
            cy.request({ method: 'POST', url: '/api/v1/internal/socket-auth', body: { token: access_token }, headers: { Authorization: `Bearer ${SERVICE_TOKEN}`, Accept: 'application/json' } })
                .its('status').should('eq', 200);
        });
    });

    // Criterion: IP-restricted.
    it('refuses a caller whose address is not on the allowed list, even with the right token', () => {
        cy.task('e2e:server:start', { port: 8002, env: { SOCKET_SERVICE_ALLOWED_IPS: '203.0.113.7' } });
        cy.request({ url: 'http://127.0.0.1:8002/up', retryOnNetworkFailure: true, failOnStatusCode: false }).its('status').should('eq', 200);
        signIn(driver.phone).then(({ access_token }) => {
            socketAuth({ token: access_token }, SERVICE_TOKEN, 'http://127.0.0.1:8002').its('status').should('eq', 403);
        });
        cy.task('e2e:server:stop');
    });

    // Criterion: returns { user_id, roles/modes, status } for a live token.
    it('returns the identity of a live rider or driver token', () => {
        signIn(driver.phone).then(({ access_token }) => {
            socketAuth({ token: access_token }).then((response) => {
                expect(response.status).to.equal(200);
                expect(response.body).to.include({ user_id: driver.user_id, kind: 'mobile', app: 'driver', status: 'active' });
                expect(response.body.modes).to.deep.equal(['driver']);
                expect(response.body.expires_at).to.be.a('string');
                expect(JSON.stringify(response.body), 'no personal data').not.to.match(/phone|email|name/i);
            });
        });
        signIn(rider.phone, 'rider').then(({ access_token }) => {
            socketAuth({ token: access_token }).its('body').should('include', { user_id: rider.id, app: 'rider' });
        });
    });

    it('validates the body: neither or both of token and ticket, and a token that is not text', () => {
        socketAuth({}).then((r) => { expect(r.status).to.equal(422); expect(r.body.errors).to.have.keys('token', 'ticket'); });
        socketAuth({ token: 'a', ticket: 'b' }).its('status').should('eq', 422);
        socketAuth({ token: ['x'] }).its('status').should('eq', 422);
        socketAuth({ token: 'not-a-real-token' }).its('status').should('eq', 401);
    });

    // Criterion: a revoked or suspended user's token returns 401.
    it('answers 401 for a token that was signed out, expired, or belongs to a suspended account', () => {
        const fresh = { logout: '+37491770013', expired: '+37491770014', suspended: '+37491770015' };
        Object.values(fresh).forEach((phone, i) => cy.make('driver', { name: `Revoke ${i}`, phone }));

        signIn(fresh.logout).then(({ access_token }) => {
            socketAuth({ token: access_token }).its('status').should('eq', 200);
            cy.api('POST', '/api/v1/auth/logout', undefined, access_token).its('status').should('eq', 204);
            socketAuth({ token: access_token }).its('status').should('eq', 401);
        });

        signIn(fresh.expired).then(({ access_token }) => {
            cy.make('expire_access_token', { token: access_token });
            socketAuth({ token: access_token }).its('status').should('eq', 401);
        });

        signIn(fresh.suspended).then(({ access_token }) => {
            socketAuth({ token: access_token }).its('status').should('eq', 200);
            // Straight to the row, so the token is still open when the socket asks: the status check alone must refuse it.
            cy.make('set_user_status', { phone: fresh.suspended, status: 'suspended', silent: true });
            socketAuth({ token: access_token }).then((response) => {
                expect(response.status).to.equal(401);
                expect(response.body).not.to.have.property('user_id');
            });
        });
    });

    it('also ends the token when the account is suspended the normal way (sessions are closed)', () => {
        cy.make('driver', { name: 'Normal Suspend', phone: '+37491770016' });
        signIn('+37491770016').then(({ access_token }) => {
            cy.make('set_user_status', { phone: '+37491770016', status: 'suspended' });
            socketAuth({ token: access_token }).its('status').should('eq', 401);
        });
    });

    // Criterion: admin browser sockets use a short-lived ticket instead of the session cookie.
    it('issues a one-time admin ticket to a signed-in admin that the socket service can exchange exactly once', () => {
        cy.loginAs(ADMIN_EMAIL);
        cy.request('/admin/socket-ticket').then((issued) => {
            expect(issued.status).to.equal(200);
            expect(issued.headers['cache-control']).to.contain('no-store');
            expect(issued.body.expires_in).to.equal(60);
            const ticket = issued.body.ticket as string;
            expect(ticket).to.have.length(48);

            socketAuth({ ticket }, null).its('status').should('eq', 401); // still needs the service token
            socketAuth({ ticket }).then((response) => {
                expect(response.status).to.equal(200);
                expect(response.body).to.include({ kind: 'admin', app: 'admin', status: 'active' });
                expect(response.body.modes).to.deep.equal(['admin']);
                expect(response.body.roles).to.deep.equal(['support']);
            });
            socketAuth({ ticket }).its('status').should('eq', 401); // replay
        });
    });

    it('does not let a ticket stand in for a token or a token for a ticket', () => {
        signIn(driver.phone).then(({ access_token }) => {
            cy.loginAs(ADMIN_EMAIL);
            cy.request('/admin/socket-ticket').then((issued) => {
                socketAuth({ ticket: access_token }).its('status').should('eq', 401);
                socketAuth({ token: issued.body.ticket }).its('status').should('eq', 401);
                socketAuth({ ticket: issued.body.ticket }).its('status').should('eq', 200);
            });
        });
    });

    it('refuses the ticket route to a guest and to the mobile apps', () => {
        cy.clearAllCookies();
        cy.request({ url: '/admin/socket-ticket', followRedirect: false, failOnStatusCode: false, headers: { Accept: 'application/json' } }).its('status').should('eq', 401);
        signIn(rider.phone, 'rider').then(({ access_token }) => {
            cy.request({ url: '/admin/socket-ticket', failOnStatusCode: false, followRedirect: false, headers: { Accept: 'application/json', Authorization: `Bearer ${access_token}` } })
                .its('status').should('eq', 401);
        });
    });

    it('rate limits the ticket route per admin and tells when to come back', () => {
        cy.loginAs(OTHER_ADMIN_EMAIL);
        for (let i = 0; i < 20; i++) {
            cy.request('/admin/socket-ticket').its('status').should('eq', 200);
        }
        cy.request({ url: '/admin/socket-ticket', failOnStatusCode: false, headers: { Accept: 'application/json' } }).then((response) => {
            expect(response.status).to.equal(429);
            expect(Number(response.headers['retry-after'])).to.be.within(1, 60);
        });
    });
});
