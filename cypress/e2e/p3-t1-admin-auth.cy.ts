/**
 * P3-T1 · Admin auth (Laravel session, Inertia).
 * Login/logout through the real form, guarded /admin routes, non-admins refused, throttle, session
 * regeneration and invalidation, idle timeout, password reset, deactivated admins.
 * (`php artisan admin:create` is a console command with prompts: covered by PHPUnit, no HTTP surface.)
 */
import { PASSWORD } from '../support/commands';

const SESSION_COOKIE = 'taxikosmos-session';

const typeLogin = (email: string, password: string) => {
    cy.findByLabelText('Email').clear().type(email);
    cy.findByLabelText('Password').clear().type(password, { log: false });
    cy.findByRole('button', { name: 'Sign in' }).click();
};

describe('P3-T1 · Admin authentication', () => {
    before(() => cy.resetDb());

    beforeEach(() => {
        Cypress.session.clearAllSavedSessions();
        cy.clearAllCookies();
    });

    // Criterion: POST /login and POST /logout are web routes answering with Inertia redirects, not JSON.
    it('signs an admin in and out with redirects, never JSON', () => {
        cy.make('admin', { email: 'cy-auth-ok@taxikosmos.test', role: 'admin', name: 'Ada Admin' });
        cy.intercept('POST', '/login').as('login');
        cy.intercept('POST', '/logout').as('logout');

        cy.visit('/login');
        typeLogin('cy-auth-ok@taxikosmos.test', PASSWORD);

        cy.wait('@login').then(({ response }) => {
            expect(response?.statusCode).to.be.oneOf([302, 303]);
            expect(String(response?.headers.location)).to.match(/\/admin$/);
            expect(String(response?.headers['content-type'] ?? '')).not.to.contain('application/json');
        });
        cy.location('pathname').should('eq', '/admin');
        cy.contains('header', 'Ada Admin').should('be.visible');

        cy.contains('button', 'Ada Admin').click();
        cy.findByRole('button', { name: /Sign out/ }).click();
        cy.wait('@logout').its('response.statusCode').should('be.oneOf', [302, 303]);
        cy.location('pathname').should('eq', '/login');

        cy.visit('/admin/riders');
        cy.location('pathname').should('eq', '/login');
    });

    // Criterion: an unauthenticated visit to any /admin/... route redirects to /login.
    it('sends a guest to the login page from every admin area', () => {
        ['/admin', '/admin/riders', '/admin/drivers', '/admin/zones', '/admin/activity-logs', '/admin/account', '/admin/settings/users'].forEach((path) => {
            cy.request({ url: path, followRedirect: false }).then((response) => {
                expect(response.status, path).to.be.oneOf([302, 303]);
                expect(String(response.headers.location), path).to.match(/\/login$/);
            });
        });

        cy.visit('/admin/zones');
        cy.location('pathname').should('eq', '/login');
        cy.findByRole('button', { name: 'Sign in' }).should('be.visible');
    });

    // Criterion: a non-admin-tier user (rider/driver) cannot authenticate through the admin login form.
    it('refuses riders and drivers on the admin login, with the same message as a wrong password', () => {
        cy.make('rider', { name: 'Rita Rider', email: 'cy-rider@taxikosmos.test' });
        cy.visit('/login');

        // Factory riders have the password "password": correct credentials, wrong kind of account.
        typeLogin('cy-rider@taxikosmos.test', 'password');
        cy.findByRole('alert').should('have.text', 'These credentials do not match our records.');
        cy.location('pathname').should('eq', '/login');

        cy.request({ url: '/admin', followRedirect: false }).its('status').should('be.oneOf', [302, 303]);
        cy.sql('select last_login_at from users where email = ?', ['cy-rider@taxikosmos.test']).then((rows) => expect(rows[0].last_login_at).to.equal(null));
    });

    it('refuses a wrong password and keeps the email typed', () => {
        cy.make('admin', { email: 'cy-auth-wrong@taxikosmos.test', role: 'support' });
        cy.visit('/login');

        typeLogin('cy-auth-wrong@taxikosmos.test', 'Not-The-Password-1!');

        cy.findByRole('alert').should('have.text', 'These credentials do not match our records.');
        cy.findByLabelText('Password').should('have.value', '');
        cy.location('pathname').should('eq', '/login');
    });

    // Criterion: rate limiting, 5 attempts per minute per IP + email.
    it('locks the sixth attempt in a minute, even with the right password, and only for that email', () => {
        cy.make('admin', { email: 'cy-auth-throttle@taxikosmos.test', role: 'support' });
        cy.make('admin', { email: 'cy-auth-other@taxikosmos.test', role: 'support' });
        cy.visit('/login');

        for (let attempt = 1; attempt <= 5; attempt++) {
            typeLogin('cy-auth-throttle@taxikosmos.test', `Wrong-Password-${attempt}!`);
            cy.findByRole('alert').should('have.text', 'These credentials do not match our records.');
        }

        typeLogin('cy-auth-throttle@taxikosmos.test', PASSWORD);
        cy.findByRole('alert').invoke('text').should('match', /^Too many login attempts\. Please try again in \d+ seconds\.$/);
        cy.location('pathname').should('eq', '/login');

        // Another email from the same browser is not locked out.
        typeLogin('cy-auth-other@taxikosmos.test', PASSWORD);
        cy.location('pathname').should('eq', '/admin');
    });

    // Criterion: the session is regenerated on login and invalidated on logout.
    it('issues a new session id at sign-in and kills the old one at sign-out', () => {
        cy.make('admin', { email: 'cy-auth-session@taxikosmos.test', role: 'admin', name: 'Sam Session' });
        cy.visit('/login');
        cy.getCookie(SESSION_COOKIE).should('exist').then((guestCookie) => {
            typeLogin('cy-auth-session@taxikosmos.test', PASSWORD);
            cy.location('pathname').should('eq', '/admin');

            cy.getCookie(SESSION_COOKIE).then((adminCookie) => {
                expect(adminCookie?.value, 'session id changes at sign-in').not.to.equal(guestCookie?.value);

                cy.contains('button', 'Sam Session').click();
                cy.findByRole('button', { name: /Sign out/ }).click();
                cy.location('pathname').should('eq', '/login');

                // Replaying the signed-in cookie after sign-out must not get back in.
                cy.setCookie(SESSION_COOKIE, adminCookie!.value);
                cy.request({ url: '/admin', followRedirect: false }).then((response) => {
                    expect(response.status).to.be.oneOf([302, 303]);
                    expect(String(response.headers.location)).to.match(/\/login$/);
                });
            });
        });
    });

    // Criterion: admin sessions expire after the idle timeout (60 min) and the UI says why.
    it('signs an idle admin out after 60 minutes and explains it on the login page', () => {
        cy.make('admin', { email: 'cy-auth-idle@taxikosmos.test', role: 'admin' });
        cy.loginAs('cy-auth-idle@taxikosmos.test');
        cy.visit('/admin/riders');
        cy.findByRole('heading', { name: 'Riders' }).should('be.visible');

        cy.make('age_admin_session', { email: 'cy-auth-idle@taxikosmos.test', minutes: 61 });
        cy.findByRole('link', { name: /Trips/ }).click();

        cy.location('pathname').should('eq', '/login');
        cy.contains('Your session expired after 60 minutes of inactivity. Please sign in again.').should('be.visible');
        cy.request({ url: '/admin', followRedirect: false }).its('status').should('be.oneOf', [302, 303]);
    });

    it('keeps an admin who was active 59 minutes ago signed in', () => {
        cy.make('admin', { email: 'cy-auth-active@taxikosmos.test', role: 'admin' });
        cy.loginAs('cy-auth-active@taxikosmos.test');
        cy.visit('/admin/riders');

        cy.make('age_admin_session', { email: 'cy-auth-active@taxikosmos.test', minutes: 59 });
        cy.findByRole('link', { name: /Trips/ }).click();

        cy.location('pathname').should('eq', '/admin/trips');
        cy.findByRole('heading', { name: 'Trips' }).should('be.visible');
    });

    // Criterion: deactivated admins are logged out on their next request.
    it('throws a deactivated admin out on the next click', () => {
        cy.make('admin', { email: 'cy-auth-deactivated@taxikosmos.test', role: 'admin' });
        cy.loginAs('cy-auth-deactivated@taxikosmos.test');
        cy.visit('/admin/riders');
        cy.findByRole('heading', { name: 'Riders' }).should('be.visible');

        cy.make('set_user_status', { email: 'cy-auth-deactivated@taxikosmos.test', status: 'deactivated' });
        cy.findByRole('link', { name: /Trips/ }).click();

        cy.location('pathname').should('eq', '/login');
        cy.contains('Your admin access is no longer active').should('be.visible');

        // And cannot sign back in.
        typeLogin('cy-auth-deactivated@taxikosmos.test', PASSWORD);
        cy.findByRole('alert').should('have.text', 'These credentials do not match our records.');
    });

    // Criterion: password reset by email, same answer for unknown emails (no account discovery).
    it('sends a reset link to an admin and answers identically for anyone else', () => {
        cy.make('admin', { email: 'cy-auth-forgot@taxikosmos.test', role: 'admin' });
        cy.make('rider', { name: 'Not An Admin', email: 'cy-auth-forgot-rider@taxikosmos.test' });

        cy.visit('/login');
        cy.findByRole('link', { name: 'Forgot password?' }).click();
        cy.location('pathname').should('eq', '/forgot-password');

        cy.findByLabelText('Email address').type('cy-auth-forgot@taxikosmos.test');
        cy.findByRole('button', { name: 'Send reset link' }).click();
        cy.contains('We have emailed your password reset link.').should('be.visible');

        cy.findByLabelText('Email address').clear().type('cy-auth-forgot-rider@taxikosmos.test');
        cy.findByRole('button', { name: 'Send reset link' }).click();
        cy.contains('We have emailed your password reset link.').should('be.visible');

        cy.findByLabelText('Email address').clear().type('nobody@taxikosmos.test');
        cy.findByRole('button', { name: 'Send reset link' }).click();
        cy.contains('We have emailed your password reset link.').should('be.visible');

        cy.sql('select email from password_reset_tokens order by email').then((rows) => {
            expect(rows.map((row) => row.email)).to.deep.equal(['cy-auth-forgot@taxikosmos.test']);
        });
    });

    // Criterion: admin passwords need 12+ characters with mixed case, a number and a symbol; then the new password works.
    it('resets the password through the emailed link with the admin password rules', () => {
        const newPassword = `Fresh-Pass-${Date.now()}!Ok`;
        cy.make('admin', { email: 'cy-auth-reset@taxikosmos.test', role: 'admin' });
        cy.make('password_reset_link', { email: 'cy-auth-reset@taxikosmos.test' }).then(({ path }) => {
            cy.visit(path);
            cy.findByLabelText('Email').should('have.value', 'cy-auth-reset@taxikosmos.test');

            cy.findByLabelText('New password').type('short1!A');
            cy.findByLabelText('Confirm password').type('short1!A');
            cy.findByRole('button', { name: 'Reset password' }).click();
            cy.contains('[role=alert]', 'at least 12 characters').should('be.visible');

            cy.findByLabelText('New password').type('alllowercase-no-digits');
            cy.findByLabelText('Confirm password').type('alllowercase-no-digits');
            cy.findByRole('button', { name: 'Reset password' }).click();
            cy.contains('[role=alert]', /uppercase and one lowercase|one number/).should('be.visible');

            cy.findByLabelText('New password').type(newPassword);
            cy.findByLabelText('Confirm password').type(`${newPassword}x`);
            cy.findByRole('button', { name: 'Reset password' }).click();
            cy.contains('[role=alert]', 'confirmation does not match').should('be.visible');

            cy.findByLabelText('New password').type(newPassword);
            cy.findByLabelText('Confirm password').type(newPassword);
            cy.findByRole('button', { name: 'Reset password' }).click();
        });

        cy.location('pathname').should('eq', '/login');
        cy.contains('Your password has been reset.').should('be.visible');

        typeLogin('cy-auth-reset@taxikosmos.test', PASSWORD);
        cy.findByRole('alert').should('have.text', 'These credentials do not match our records.');
        typeLogin('cy-auth-reset@taxikosmos.test', newPassword);
        cy.location('pathname').should('eq', '/admin');
        cy.sql('select count(*)::int as n from password_reset_tokens where email = ?', ['cy-auth-reset@taxikosmos.test']).then((rows) => expect(rows[0].n, 'the used link is gone').to.equal(0));
    });

    // Criterion: the reset link expires after 60 minutes.
    it('refuses a reset link older than 60 minutes', () => {
        cy.make('admin', { email: 'cy-auth-expired@taxikosmos.test', role: 'admin' });
        cy.make('password_reset_link', { email: 'cy-auth-expired@taxikosmos.test' }).then(({ path }) => {
            cy.make('age_password_reset', { email: 'cy-auth-expired@taxikosmos.test', minutes: 61 });
            cy.visit(path);
            cy.findByLabelText('New password').type('Brand-New-Pass-42!');
            cy.findByLabelText('Confirm password').type('Brand-New-Pass-42!');
            cy.findByRole('button', { name: 'Reset password' }).click();
        });

        cy.contains('[role=alert]', 'This password reset token is invalid.').should('be.visible');
        cy.location('pathname').should('match', /^\/reset-password\//);
        cy.visit('/login');
        typeLogin('cy-auth-expired@taxikosmos.test', PASSWORD);
        cy.location('pathname').should('eq', '/admin');
    });
});
