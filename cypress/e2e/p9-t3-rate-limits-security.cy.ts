/**
 * P9-T3 · Rate limiting and abuse protection: the security headers on the admin web surface (and that the
 * real pages still work under the CSP), 429 + Retry-After from the sign-in form, the admin auth endpoints and the
 * mobile API, and the optional IP allowlist (off by default; switched on by a second server started for this spec).
 * The general admin (300/min) and mobile (120/min) ceilings are covered by PHPUnit with a lowered limit: reaching them
 * here would take hundreds of requests.
 */
import { PASSWORD } from '../support/commands';

const CODE = '123456';
const SUPPORT = 'cy-security-support@taxikosmos.test';
const HEADERS = {
    'x-frame-options': 'DENY',
    'x-content-type-options': 'nosniff',
    'referrer-policy': 'same-origin',
    'permissions-policy': 'accelerometer=(), camera=(), geolocation=(), gyroscope=(), microphone=(), payment=(), usb=()',
};
const ALLOWLISTED = 'http://127.0.0.1:8002'; // started with ADMIN_IP_ALLOWLIST=203.0.113.7 (not this machine)
const OPEN = 'http://127.0.0.1:8003'; // started with ADMIN_IP_ALLOWLIST=127.0.0.1/32,203.0.113.7
const BEHIND_PROXY = 'http://127.0.0.1:8004'; // ADMIN_IP_ALLOWLIST=203.0.113.7 and TRUSTED_PROXIES=127.0.0.1 (this machine is the proxy)

const expectHardened = (headers: Record<string, unknown>, where: string) => {
    Object.entries(HEADERS).forEach(([name, value]) => expect(headers[name], `${where}: ${name}`).to.equal(value));
    const csp = String(headers['content-security-policy'] ?? '');
    expect(csp, `${where}: CSP`).to.contain("default-src 'self'").and.to.contain("frame-ancestors 'none'").and.to.contain("object-src 'none'");
    expect(csp, `${where}: no inline or eval scripts`).to.match(/script-src 'self'(;|$)/);
};

/** A JSON POST the way a script would send it: the CSRF header from the cookie, no X-Inertia. */
const postJson = (path: string, body: Record<string, unknown>) =>
    cy.getCookie('XSRF-TOKEN').then((cookie) =>
        cy.request({
            method: 'POST', url: path, body, failOnStatusCode: false, followRedirect: false,
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(cookie?.value ?? '') },
        }));

const riderToken = (phone: string) =>
    cy.api('POST', '/api/v1/auth/otp/request', { phone, app: 'rider' }).its('status').should('eq', 202).then(() =>
        cy.api('POST', '/api/v1/auth/otp/verify', { phone, code: CODE, app: 'rider', device_id: 'cypress-security-device' }).then((response) => {
            expect(response.status).to.equal(200);
            return response.body.access_token as string;
        }));

describe('P9-T3 · Rate limiting and abuse protection', () => {
    before(() => {
        cy.resetDb();
        cy.make('admin', { email: SUPPORT, role: 'support', name: 'Sam Security' });
        cy.make('admin', { email: 'cy-security-throttled@taxikosmos.test', role: 'admin', name: 'Tess Throttled' });
        cy.make('admin', { email: 'cy-security-banner@taxikosmos.test', role: 'admin', name: 'Bea Banner' });
        cy.make('admin', { email: 'cy-security-super@taxikosmos.test', role: 'super_admin', name: 'Sue Super' });
    });

    beforeEach(() => cy.make('clear_rate_limits'));

    // Criterion: security headers present on every /admin response.
    it('sends every security header on the sign-in page, an admin page, a redirect, a refusal and an unmatched URL', () => {
        cy.request('/login').then((response) => expectHardened(response.headers, 'GET /login'));
        cy.request({ url: '/admin/drivers', followRedirect: false }).then((response) => {
            expect(response.status).to.be.oneOf([302, 303]);
            expectHardened(response.headers, 'guest redirect');
        });
        cy.request({ url: '/admin/no-such-page', failOnStatusCode: false }).then((response) => {
            expect(response.status).to.equal(404);
            expectHardened(response.headers, '404 page');
        });

        cy.loginAs(SUPPORT);
        cy.request('/admin').then((response) => expectHardened(response.headers, 'GET /admin'));
        cy.request('/admin/drivers').then((response) => expectHardened(response.headers, 'GET /admin/drivers'));
        cy.request({ url: '/admin/activity-logs', failOnStatusCode: false }).then((response) => {
            expect(response.status).to.equal(403); // support has no activity_log.view
            expectHardened(response.headers, '403 page');
        });
    });

    // Criterion: HSTS only where it means something; the API is JSON and left alone.
    it('does not send HSTS over plain HTTP and leaves the mobile API without page headers', () => {
        cy.request('/login').its('headers').should('not.have.property', 'strict-transport-security');
        cy.api('POST', '/api/v1/auth/otp/verify', {}).then((response) => {
            expect(response.status).to.equal(422);
            ['content-security-policy', 'x-frame-options', 'referrer-policy', 'permissions-policy'].forEach((name) => expect(response.headers).not.to.have.property(name));
        });
    });

    // Criterion: the CSP must not break the real admin (a violation would blank a page or a map).
    it('loads the admin pages under the policy without a single violation', () => {
        const violations: string[] = [];
        const visit = (path: string) => cy.visit(path, {
            onBeforeLoad: (win) => win.document.addEventListener('securitypolicyviolation', (event) => violations.push(`${path}: ${event.violatedDirective} ${event.blockedURI}`)),
        });

        // The sign-in page first, as a guest (a signed-in admin is redirected away from it).
        Cypress.session.clearAllSavedSessions();
        cy.clearAllCookies();
        visit('/login');
        cy.findByRole('button', { name: 'Sign in' }).should('be.visible');
        cy.loginAs('cy-security-super@taxikosmos.test');
        ['/admin', '/admin/riders', '/admin/drivers', '/admin/account', '/admin/live-map', '/admin/zones'].forEach((path) => {
            visit(path);
            cy.get('#app, body').should('be.visible');
        });
        cy.then(() => expect(violations, 'CSP violations').to.deep.equal([]));
    });

    // Regression: `npm run dev` adds an inline React-refresh script; a CSP without a nonce blocks it and the app never boots.
    describe('while the Vite dev server is configured (npm run dev)', () => {
        before(() => cy.task('e2e:vite:hot', { on: true }));
        after(() => cy.task('e2e:vite:hot', { on: false }));

        it('allows the inline refresh script with a per-response nonce, and nothing else inline', () => {
            const violations: string[] = [];
            cy.clearAllCookies();
            cy.visit('/login', {
                failOnStatusCode: false,
                onBeforeLoad: (win) => win.document.addEventListener('securitypolicyviolation', (event) => violations.push(`${event.violatedDirective} ${event.blockedURI} ${event.sample}`)),
            });

            cy.request('/login').then((response) => {
                const nonce = /'nonce-([^']+)'/.exec(String(response.headers['content-security-policy']))?.[1];
                expect(nonce, 'nonce in the policy').to.match(/^[A-Za-z0-9+/=]{16,}$/);
                expect(String(response.headers['content-security-policy'])).not.to.match(/script-src[^;]*'unsafe-inline'/);
                expect(String(response.body), 'the inline preamble carries the nonce').to.contain(`<script type="module" nonce="${nonce}">`);
                cy.request('/login').then((again) => expect(/'nonce-([^']+)'/.exec(String(again.headers['content-security-policy']))?.[1]).not.to.equal(nonce));
            });
            cy.then(() => expect(violations.filter((v) => v.startsWith('script-src') && v.includes('inline')), 'inline script violations').to.deep.equal([]));
        });
    });

    it('cannot be framed by another site', () => {
        cy.request('/login').then((response) => {
            expect(response.headers['x-frame-options']).to.equal('DENY');
            expect(String(response.headers['content-security-policy'])).to.contain("frame-ancestors 'none'");
        });
    });

    // Criterion: rate-limited responses return 429 with Retry-After (the sign-in form).
    it('shows the "Too many login attempts" banner on the sign-in form after five tries and signs nobody in', () => {
        Cypress.session.clearAllSavedSessions();
        cy.clearAllCookies();
        cy.intercept('POST', '/login').as('login');
        cy.visit('/login?lang=en');

        for (let attempt = 1; attempt <= 5; attempt++) {
            cy.findByLabelText('Email').clear().type('cy-security-banner@taxikosmos.test');
            cy.findByLabelText('Password').clear().type('Wrong-Password-123', { log: false });
            cy.findByRole('button', { name: 'Sign in' }).click();
            cy.wait('@login');
            cy.findByRole('alert').should('contain', 'These credentials do not match our records.');
        }

        cy.findByLabelText('Email').clear().type('cy-security-banner@taxikosmos.test');
        cy.findByLabelText('Password').clear().type(PASSWORD, { log: false });
        cy.findByRole('button', { name: 'Sign in' }).click();
        cy.wait('@login').then(({ response }) => {
            expect(response?.headers['retry-after'], 'Retry-After on the banner response').to.match(/^\d+$/);
            expect(Number(response?.headers['retry-after'])).to.be.within(1, 60);
        });
        cy.findByRole('alert').invoke('text').should('match', /^Too many login attempts\. Please try again in \d+ seconds\.$/);
        cy.location('pathname').should('eq', '/login');
        cy.request({ url: '/admin', followRedirect: false }).its('status').should('be.oneOf', [302, 303]);
    });

    it('answers a script hammering the sign-in with 429, Retry-After and a JSON message', () => {
        cy.clearAllCookies();
        cy.visit('/login');
        for (let attempt = 1; attempt <= 5; attempt++) {
            postJson('/login', { email: 'cy-security-throttled@taxikosmos.test', password: 'Wrong-Password-123' }).its('status').should('be.oneOf', [302, 303, 422]);
        }

        postJson('/login', { email: 'cy-security-throttled@taxikosmos.test', password: PASSWORD }).then((response) => {
            expect(response.status).to.equal(429);
            expect(response.headers['retry-after']).to.match(/^\d+$/);
            expect(response.body.message).to.match(/^Too many login attempts\. Please try again in \d+ seconds\.$/);
            expectHardened(response.headers, '429');
        });
        cy.request({ url: '/admin', followRedirect: false }).its('status').should('be.oneOf', [302, 303]);
    });

    // Criterion: throttle on the other public/auth endpoints.
    it('limits the admin password-reset endpoint per address: the 31st request in a minute is a 429 with Retry-After', () => {
        cy.clearAllCookies();
        cy.visit('/forgot-password');
        cy.intercept('POST', '/forgot-password').as('reset');

        for (let request = 1; request <= 30; request++) {
            cy.adminRequest('POST', '/forgot-password', { email: `nobody-${request}@taxikosmos.test` }).its('status').should('be.oneOf', [302, 303]);
        }

        cy.adminRequest('POST', '/forgot-password', { email: 'nobody-31@taxikosmos.test' }).then((response) => {
            expect(response.status).to.equal(429);
            expect(response.headers['retry-after']).to.match(/^\d+$/);
            expectHardened(response.headers, '429 page');
            expect(response.body.component, 'the template\'s 429 card').to.equal('Errors/Show');
            expect(response.body.props.status).to.equal(429);
        });
    });

    it('limits the mobile code request and the authenticated fare estimate with 429 and Retry-After', () => {
        cy.api('POST', '/api/v1/auth/otp/request', { phone: '+37491770030', app: 'rider' }).its('status').should('eq', 202);
        cy.api('POST', '/api/v1/auth/otp/request', { phone: '+37491770030', app: 'rider' }).then((response) => {
            expect(response.status).to.equal(429);
            expect(response.headers['retry-after']).to.match(/^\d+$/);
            expect(response.body).to.have.property('message');
        });

        cy.make('clear_rate_limits');
        riderToken('+37491770031').then((token) => {
            for (let call = 1; call <= 30; call++) {
                cy.api('POST', '/api/v1/trips/fare-estimate', {}, token).its('status').should('eq', 422);
            }
            cy.api('POST', '/api/v1/trips/fare-estimate', {}, token).then((response) => {
                expect(response.status).to.equal(429);
                expect(response.headers['retry-after']).to.match(/^\d+$/);
            });
            // Another rider is not affected by this one's budget.
            riderToken('+37491770032').then((other) => cy.api('POST', '/api/v1/trips/fare-estimate', {}, other).its('status').should('eq', 422));
        });
    });

    // Criterion: optional IP allowlist for /admin and /horizon, configurable via .env, off by default.
    it('lets everyone in while ADMIN_IP_ALLOWLIST is empty (the default)', () => {
        cy.request('/login').its('status').should('eq', 200);
        cy.request({ url: '/admin', followRedirect: false }).its('status').should('be.oneOf', [302, 303]);
        cy.request({ url: '/horizon', followRedirect: false, failOnStatusCode: false }).its('status').should('not.eq', 403);
    });

    describe('with ADMIN_IP_ALLOWLIST set', () => {
        before(() => {
            cy.task('e2e:server:start', { port: 8002, env: { ADMIN_IP_ALLOWLIST: '203.0.113.7' } });
            cy.task('e2e:server:start', { port: 8003, env: { ADMIN_IP_ALLOWLIST: '127.0.0.1/32,203.0.113.7' } });
            cy.task('e2e:server:start', { port: 8004, env: { ADMIN_IP_ALLOWLIST: '203.0.113.7', TRUSTED_PROXIES: '127.0.0.1' } });
            [ALLOWLISTED, OPEN, BEHIND_PROXY].forEach((base) => cy.request({ url: `${base}/up`, retryOnNetworkFailure: true, failOnStatusCode: false }).its('status').should('eq', 200));
        });

        after(() => {
            cy.task('e2e:server:stop');
        });

        it('answers 403, with the security headers, to every admin and sign-in URL from an address outside the list', () => {
            ['/admin', '/admin/drivers', '/admin/no-such-page', '/horizon', '/horizon/api/stats', '/login', '/forgot-password', '/reset-password/some-token', '/two-factor-challenge'].forEach((path) => {
                cy.request({ url: `${ALLOWLISTED}${path}`, failOnStatusCode: false, followRedirect: false }).then((response) => {
                    expect(response.status, path).to.equal(403);
                    expect(response.headers['x-frame-options'], path).to.equal('DENY');
                });
            });
            cy.request({ method: 'POST', url: `${ALLOWLISTED}/login`, body: { email: 'x@x.test', password: 'x' }, failOnStatusCode: false, followRedirect: false }).its('status').should('eq', 403);
        });

        it('never restricts the mobile API or the health check', () => {
            cy.request({ url: `${ALLOWLISTED}/up`, failOnStatusCode: false }).its('status').should('eq', 200);
            cy.request({ method: 'POST', url: `${ALLOWLISTED}/api/v1/auth/otp/verify`, body: {}, failOnStatusCode: false, headers: { Accept: 'application/json' } }).its('status').should('eq', 422);
        });

        it('believes X-Forwarded-For only from a proxy listed in TRUSTED_PROXIES', () => {
            const forwarded = (address: string) => ({ 'X-Forwarded-For': address });

            // No trusted proxy: the header is ignored, so a spoofed allowed address does not get in.
            cy.request({ url: `${ALLOWLISTED}/login`, headers: forwarded('203.0.113.7'), failOnStatusCode: false }).its('status').should('eq', 403);

            // This machine is a trusted proxy: the client address it reports is judged against the list.
            cy.request({ url: `${BEHIND_PROXY}/login`, headers: forwarded('203.0.113.7'), failOnStatusCode: false }).its('status').should('eq', 200);
            cy.request({ url: `${BEHIND_PROXY}/login`, headers: forwarded('198.51.100.9'), failOnStatusCode: false }).its('status').should('eq', 403);
            cy.request({ url: `${BEHIND_PROXY}/login`, failOnStatusCode: false }).its('status').should('eq', 403); // no header: the proxy's own address
        });

        it('lets a listed address (a CIDR containing it) reach the sign-in page and the admin', () => {
            cy.request({ url: `${OPEN}/login`, failOnStatusCode: false }).its('status').should('eq', 200);
            cy.request({ url: `${OPEN}/admin`, failOnStatusCode: false, followRedirect: false }).its('status').should('be.oneOf', [302, 303]);
        });
    });
});

export {};
