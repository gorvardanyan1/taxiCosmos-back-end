/**
 * P13-T3 · Auth screens: Login, Forgot Password, Reset Password, Accept Invitation, 2FA Challenge, 2FA Setup and My Account
 * (profile, change password, 2FA status, active sessions with "log out other sessions").
 *
 * The sign-in, reset and two-factor flows themselves are proved in depth by p3-t1 and p3-t2; this spec proves that each
 * screen works with its Fortify routes in the browser (validation and rate-limit messages next to what was typed), that the
 * forced two-factor setup screen cannot be skipped, and the My Account features this task made real. The forced-setup group
 * runs on a second server with ADMIN_2FA_REQUIRED_ROLES=super_admin,finance (the production default).
 */
import { PASSWORD } from '../support/commands';

const REQUIRED_SERVER = 'http://localhost:8006';

const typeLogin = (email: string, password: string) => {
    cy.findByLabelText('Email').clear().type(email);
    cy.findByLabelText('Password').clear().type(password, { log: false });
    cy.findByRole('button', { name: 'Sign in' }).click();
};
const signIn = (email: string, password = PASSWORD) => {
    cy.visit('/login?lang=en');
    cy.findByRole('button', { name: 'Sign in' }).should('be.visible').and('be.enabled');
    cy.get('html').should('have.attr', 'lang', 'en');
    typeLogin(email, password);
};
const decode = (value: unknown) => (typeof value === 'string' ? JSON.parse(value) : value ?? {});
/** The audit entries of an action by an admin, with `properties` and the before/after `changes` decoded. */
const audit = (action: string, causer: number) =>
    cy.sql('select subject_id, properties, attribute_changes from activity_log where description = ? and causer_id = ? order by id', [action, causer])
        .then((rows) => rows.map((row) => ({ ...row, properties: decode(row.properties), changes: decode(row.attribute_changes) })));
/** The screen's title (browser tab) and its subtitle under the logo. */
const screen = (title: string, subtitle = title) => {
    cy.title().should('contain', title);
    cy.findByRole('heading', { name: 'TaxiKosmos' }).should('be.visible');
    cy.contains('p', subtitle).should('be.visible');
};
const userRow = (email: string) => cy.sql('select id, name, timezone, password from users where email = ?', [email]).its('0');
/** Signs in and waits for the admin to open. */
const signInToAdmin = (email: string) => {
    signIn(email);
    cy.location('pathname').should('match', /^\/admin/);
};
const openAccount = (tab: 'profile' | 'security') => cy.visit(`/admin/account?tab=${tab}&lang=en`);
const confirmPassword = (dialogName: string, buttonName: string, password: string) => {
    cy.findByRole('dialog', { name: dialogName }).within(() => {
        cy.findByLabelText(/Current password/).type(password, { log: false });
        cy.findByRole('button', { name: buttonName }).click();
    });
};
/** A POST the way the page sends it, with the CSRF cookie of the current visit. */
const postForm = (path: string, body: Record<string, string>) =>
    cy.getCookie('XSRF-TOKEN').then((cookie) => cy.request({ method: 'POST', url: path, body, failOnStatusCode: false, followRedirect: false, headers: { 'X-XSRF-TOKEN': decodeURIComponent(cookie?.value ?? ''), Accept: 'text/html' } }));

describe('P13-T3 · Auth screens', () => {
    before(() => cy.resetDb());

    beforeEach(() => {
        Cypress.session.clearAllSavedSessions();
        cy.clearAllCookies();
        cy.make('clear_rate_limits');
    });

    // Criterion 1: every screen works with the Fortify routes, with validation and rate-limit messages.
    describe('the screens before signing in', () => {
        it('login: shows the template screen, the server\'s validation message, and the lock after five wrong passwords', () => {
            cy.make('admin', { email: 'cypress-t3-login@taxikosmos.test', role: 'support' });
            cy.visit('/login?lang=en');
            screen('Sign in', 'Login');
            cy.findByLabelText('Email').should('be.visible');
            cy.findByLabelText('Password').should('have.attr', 'type', 'password');
            cy.findByRole('link', { name: 'Forgot password?' }).should('have.attr', 'href', '/forgot-password');

            typeLogin('cypress-t3-login@taxikosmos.test', 'Wrong-Password-1!');
            cy.findByRole('alert').should('have.text', 'These credentials do not match our records.');
            cy.findByLabelText('Email').should('have.value', 'cypress-t3-login@taxikosmos.test');
            cy.findByLabelText('Password').should('have.value', '');

            for (let attempt = 2; attempt <= 5; attempt++) {
                typeLogin('cypress-t3-login@taxikosmos.test', `Wrong-Password-${attempt}!`);
                cy.findByRole('alert').should('have.text', 'These credentials do not match our records.');
            }
            typeLogin('cypress-t3-login@taxikosmos.test', PASSWORD);
            cy.findByRole('alert').invoke('text').should('match', /^Too many login attempts\. Please try again in \d+ seconds\.$/);
            cy.location('pathname').should('eq', '/login');
            cy.request({ url: '/admin', followRedirect: false }).its('status').should('be.oneOf', [302, 303]);
        });

        it('forgot password: rejects an empty email next to the field, confirms a valid one, and answers 429 with a message when flooded', () => {
            cy.make('admin', { email: 'cypress-t3-forgot@taxikosmos.test', role: 'support' });
            cy.visit('/forgot-password?lang=en');
            screen('Forgot password');

            cy.findByRole('button', { name: 'Send reset link' }).click();
            cy.findByRole('alert').should('have.text', 'The email field is required.');
            cy.sql('select count(*)::int as n from password_reset_tokens').its('0.n').should('eq', 0);

            cy.findByLabelText('Email address').clear().type('cypress-t3-forgot@taxikosmos.test');
            cy.findByRole('button', { name: 'Send reset link' }).click();
            cy.contains('We have emailed your password reset link.').should('be.visible');
            cy.sql('select count(*)::int as n from password_reset_tokens where email = ?', ['cypress-t3-forgot@taxikosmos.test']).its('0.n').should('eq', 1);

            // The limit is 30 a minute per address and path: the browser's next submit is refused with the "Too many requests" page.
            for (let i = 0; i < 30; i++) {
                postForm('/forgot-password', { email: 'nobody@taxikosmos.test' });
            }
            cy.intercept('POST', '/forgot-password').as('forgot');
            cy.visit('/forgot-password?lang=en');
            cy.findByLabelText('Email address').type('cypress-t3-forgot@taxikosmos.test');
            cy.findByRole('button', { name: 'Send reset link' }).click();
            cy.wait('@forgot').then(({ response }) => {
                expect(response?.statusCode).to.equal(429);
                expect(Number(response?.headers['retry-after'])).to.be.within(1, 60);
            });
            cy.contains('Too many requests').should('be.visible');
        });

        it('reset password: shows the rules, a message per mistake, and refuses a reused or bogus link', () => {
            cy.make('admin', { email: 'cypress-t3-reset@taxikosmos.test', role: 'support' });
            cy.make('password_reset_link', { email: 'cypress-t3-reset@taxikosmos.test' }).then(({ path }) => {
                cy.visit(`${path}&lang=en`);
                screen('Reset password');
                cy.contains('Minimum 12 characters · uppercase · number · symbol').should('be.visible');
                cy.findByLabelText('Email').should('have.value', 'cypress-t3-reset@taxikosmos.test');

                cy.findByLabelText('New password').type('short1!A');
                cy.findByLabelText('Confirm password').type('short1!A');
                cy.findByRole('button', { name: 'Reset password' }).click();
                cy.contains('[role=alert]', 'at least 12 characters').should('be.visible');
                cy.findByLabelText('New password').should('have.value', '');

                const fresh = 'Fresh-Pass-T3!2026';
                cy.findByLabelText('New password').type(fresh);
                cy.findByLabelText('Confirm password').type(`${fresh}x`);
                cy.findByRole('button', { name: 'Reset password' }).click();
                cy.contains('[role=alert]', 'confirmation does not match').should('be.visible');

                cy.findByLabelText('New password').type(fresh);
                cy.findByLabelText('Confirm password').type(fresh);
                cy.findByRole('button', { name: 'Reset password' }).click();
                cy.location('pathname').should('eq', '/login');
                cy.contains('Your password has been reset.').should('be.visible');

                // The same link cannot be used twice.
                cy.visit(`${path}&lang=en`);
                cy.findByLabelText('New password').type('Another-Pass-T3!2026');
                cy.findByLabelText('Confirm password').type('Another-Pass-T3!2026');
                cy.findByRole('button', { name: 'Reset password' }).click();
                cy.findByRole('alert').should('be.visible');
                cy.location('pathname').should('contain', '/reset-password/');
            });
        });

        it('accept invitation: the screen is there, and nothing can be submitted until invitations exist (P3-T4)', () => {
            cy.visit('/accept-invitation/some-token?lang=en');
            screen('Accept invitation');
            cy.findByLabelText('Full name').should('be.visible');
            cy.findByLabelText('Set password').should('have.attr', 'type', 'password');
            cy.findByLabelText('Confirm password').should('be.visible');
            cy.findByRole('button', { name: 'Accept invitation' }).should('be.disabled');
            cy.request({ method: 'POST', url: '/accept-invitation', failOnStatusCode: false, followRedirect: false }).its('status').should('be.oneOf', [404, 405, 419]);
        });

        it('two-factor challenge: takes a code or a recovery code, and refuses a wrong one with a message', () => {
            cy.make('admin', { email: 'cypress-t3-2fa@taxikosmos.test', role: 'support', two_factor: true });
            signIn('cypress-t3-2fa@taxikosmos.test');
            cy.location('pathname').should('eq', '/two-factor-challenge');
            screen('Two-factor challenge');

            cy.findByLabelText('Authentication code').type('000000');
            cy.findByRole('button', { name: 'Verify code' }).click();
            cy.findByRole('alert').should('contain', 'The provided two factor authentication code was invalid.');

            cy.findByRole('button', { name: 'Use a recovery code instead' }).click();
            cy.findByLabelText('Authentication code').should('not.exist');
            cy.findByLabelText('Recovery code').type('AAAAAAAAAA-BBBBBBBBBB');
            cy.findByRole('button', { name: 'Verify code' }).click();
            cy.findByRole('alert').should('be.visible');
            cy.location('pathname').should('eq', '/two-factor-challenge');

            cy.findByRole('button', { name: 'Use an authentication code instead' }).click();
            cy.findByLabelText('Authentication code').should('be.visible');
            cy.request({ url: '/admin', followRedirect: false }).its('status').should('be.oneOf', [302, 303]);
        });
    });

    // Criterion 2: forced 2FA setup lands on the setup screen and cannot be skipped.
    describe('forced two-factor setup (ADMIN_2FA_REQUIRED_ROLES=super_admin,finance)', { baseUrl: REQUIRED_SERVER }, () => {
        before(() => {
            cy.task('e2e:server:start', { port: 8006, env: { ADMIN_2FA_REQUIRED_ROLES: 'super_admin,finance' } });
            cy.request({ url: 'http://127.0.0.1:8006/up', retryOnNetworkFailure: true, failOnStatusCode: false }).its('status').should('eq', 200);
        });

        after(() => {
            cy.task('e2e:server:stop');
        });

        it('lands on the setup screen, shows the QR code, the key and the recovery codes with Copy and Download, and cannot be skipped', () => {
            cy.make('admin', { email: 'cypress-t3-forced@taxikosmos.test', role: 'finance', name: 'Fiona Forced' });
            signIn('cypress-t3-forced@taxikosmos.test');

            cy.location('pathname').should('eq', '/two-factor-setup');
            screen('Two-factor setup');
            cy.contains('Your role requires two-factor authentication.').should('be.visible');
            ['/admin', '/admin/drivers', '/admin/account'].forEach((path) => {
                cy.request({ url: path, followRedirect: false }).then((response) => {
                    expect(response.status, path).to.be.oneOf([302, 303]);
                    expect(String(response.headers.location), path).to.match(/\/two-factor-setup$/);
                });
            });

            cy.findByLabelText('Current password').type('wrong-password', { log: false });
            cy.findByRole('button', { name: 'Generate QR code' }).click();
            cy.findByRole('alert').should('contain', 'The password is incorrect.');

            cy.findByLabelText('Current password').type(PASSWORD, { log: false });
            cy.findByRole('button', { name: 'Generate QR code' }).click();
            cy.get('svg').should('exist');
            cy.contains(/^[A-Z2-7]{16,}$/).should('be.visible');
            cy.findAllByText(/^[A-Za-z0-9]{10}-[A-Za-z0-9]{10}$/).should('have.length', 8).then(($codes) => {
                const codes = [...$codes].map((element) => element.textContent as string);
                cy.findByRole('link', { name: 'Download' }).should('have.attr', 'download', 'taxikosmos-recovery-codes.txt').invoke('attr', 'href').then((href) => {
                    expect(decodeURIComponent(String(href).split(',')[1])).to.equal(codes.join('\n'));
                });
                cy.window().then((win) => {
                    cy.stub(win.navigator.clipboard, 'writeText').as('copy').resolves();
                });
                cy.findByRole('button', { name: 'Copy' }).click();
                cy.get('@copy').should('have.been.calledOnceWith', codes.join('\n'));
            });

            // A wrong code, or an unticked "I have saved these codes", keeps the admin on the screen.
            cy.findByLabelText('Authentication code').type('000000');
            cy.findByRole('button', { name: 'Enable two-factor' }).click();
            cy.findAllByRole('alert').should('have.length.at.least', 1);
            cy.location('pathname').should('eq', '/two-factor-setup');
            cy.request({ url: '/admin', followRedirect: false }).its('status').should('be.oneOf', [302, 303]);
            cy.sql("select two_factor_confirmed_at is not null as confirmed from users where email = 'cypress-t3-forced@taxikosmos.test'").its('0.confirmed').should('eq', false);

            // The only way out is signing out, and the next sign-in is back on the same screen.
            cy.findByRole('button', { name: 'Sign out' }).click();
            cy.location('pathname').should('eq', '/login');
            signIn('cypress-t3-forced@taxikosmos.test');
            cy.location('pathname').should('eq', '/two-factor-setup');
        });
    });

    // My Account: profile, change password, active sessions.
    describe('My Account', () => {
        it('saves the profile: name and timezone persist after a reload, the email is read-only, one audit entry, no console errors', () => {
            cy.make('admin', { email: 'cypress-t3-profile@taxikosmos.test', role: 'support', name: 'Old Name' });
            signInToAdmin('cypress-t3-profile@taxikosmos.test');
            cy.intercept('PATCH', '/admin/account/profile').as('profile');
            cy.visit('/admin/account?lang=en', { onBeforeLoad: (win) => { cy.spy(win.console, 'error').as('consoleError'); } });

            cy.findByLabelText('Email address').should('have.attr', 'readonly');
            cy.findByLabelText('Email address').should('have.value', 'cypress-t3-profile@taxikosmos.test');
            cy.findByLabelText('Full name').clear().type('Anna Support');
            cy.findByLabelText('Timezone').clear().type('Europe/Moscow');
            cy.findByRole('button', { name: 'Save changes' }).click();
            cy.wait('@profile').its('response.statusCode').should('be.oneOf', [302, 303]);
            cy.contains('Profile updated.').should('be.visible');

            cy.reload();
            cy.findByLabelText('Full name').should('have.value', 'Anna Support');
            cy.findByLabelText('Timezone').should('have.value', 'Europe/Moscow');
            userRow('cypress-t3-profile@taxikosmos.test').then((row) => {
                expect(row).to.include({ name: 'Anna Support', timezone: 'Europe/Moscow' });
                audit('account.profile_updated', row.id).then((entries) => {
                    expect(entries).to.have.length(1);
                    expect(entries[0].subject_id).to.equal(row.id);
                    expect(entries[0].changes.attributes).to.deep.equal({ name: 'Anna Support', timezone: 'Europe/Moscow' });
                    expect(entries[0].changes.old).to.deep.equal({ name: 'Old Name', timezone: 'Asia/Yerevan' });
                });
            });
            cy.get('@consoleError').should('not.have.been.called');
        });

        it('refuses an empty name and an unknown timezone next to the field and saves nothing', () => {
            cy.make('admin', { email: 'cypress-t3-profile2@taxikosmos.test', role: 'support', name: 'Keep Name' });
            signInToAdmin('cypress-t3-profile2@taxikosmos.test');
            openAccount('profile');

            cy.findByLabelText('Full name').clear();
            cy.findByRole('button', { name: 'Save changes' }).click();
            cy.findByRole('alert').should('have.text', 'The name field is required.');

            cy.findByLabelText('Full name').type('Keep Name');
            cy.findByLabelText('Timezone').clear().type('Mars/Base');
            cy.findByRole('button', { name: 'Save changes' }).click();
            cy.findByRole('alert').should('have.text', 'The selected timezone is invalid.');

            userRow('cypress-t3-profile2@taxikosmos.test').then((row) => {
                expect(row).to.include({ name: 'Keep Name', timezone: 'Asia/Yerevan' });
                audit('account.profile_updated', row.id).should('have.length', 0);
            });
        });

        it('changes the password: wrong current and weak new are refused with a message, a double-click changes it once, and only the new one signs in', () => {
            const newPassword = 'Brand-New-Pass!2026';
            cy.make('admin', { email: 'cypress-t3-password@taxikosmos.test', role: 'support' });
            signInToAdmin('cypress-t3-password@taxikosmos.test');
            openAccount('security');
            userRow('cypress-t3-password@taxikosmos.test').then((before) => {
                cy.findByPlaceholderText('Current password').type('not-my-password', { log: false });
                cy.findByPlaceholderText('New password').type(newPassword);
                cy.findByPlaceholderText('Confirm password').type(newPassword);
                cy.findByRole('button', { name: 'Update password' }).click();
                cy.findByRole('alert').should('have.text', 'The password is incorrect.');
                cy.findByPlaceholderText('New password').should('have.value', '');

                cy.findByPlaceholderText('Current password').clear().type(PASSWORD, { log: false });
                cy.findByPlaceholderText('New password').type('short1!A');
                cy.findByPlaceholderText('Confirm password').type('short1!A');
                cy.findByRole('button', { name: 'Update password' }).click();
                cy.contains('[role=alert]', 'at least 12 characters').should('be.visible');
                userRow('cypress-t3-password@taxikosmos.test').its('password').should('eq', before.password);

                cy.intercept('PUT', '/admin/account/password').as('password');
                cy.findByPlaceholderText('Current password').clear().type(PASSWORD, { log: false });
                cy.findByPlaceholderText('New password').clear().type(newPassword);
                cy.findByPlaceholderText('Confirm password').clear().type(newPassword);
                cy.findByRole('button', { name: 'Update password' }).dblclick();
                cy.contains('Password updated.').should('be.visible');
                cy.get('@password.all').should('have.length', 1);
                userRow('cypress-t3-password@taxikosmos.test').then((after) => {
                    expect(after.password).not.to.equal(before.password);
                    audit('account.password_changed', before.id).then((entries) => {
                        expect(entries).to.have.length(1);
                        expect(JSON.stringify(entries[0].properties)).not.to.contain(newPassword).and.not.to.contain(after.password);
                    });
                });
                cy.findByPlaceholderText('Current password').should('have.value', '');
            });

            // The admin stays signed in here; a new sign-in needs the new password.
            cy.request({ url: '/admin/account', followRedirect: false }).its('status').should('eq', 200);
            cy.clearAllCookies();
            signIn('cypress-t3-password@taxikosmos.test', PASSWORD);
            cy.findByRole('alert').should('have.text', 'These credentials do not match our records.');
            typeLogin('cypress-t3-password@taxikosmos.test', newPassword);
            cy.location('pathname').should('eq', '/admin');
        });

        it('lists the browsers signed in and logs the others out behind the password, leaving this one signed in', () => {
            cy.make('admin', { email: 'cypress-t3-sessions@taxikosmos.test', role: 'support' });
            cy.make('admin', { email: 'cypress-t3-sessions-other@taxikosmos.test', role: 'support' });
            let browserA: Cypress.Cookie[] = [];
            let browserB: Cypress.Cookie[] = [];

            // "Browser B": a separate sign-in (its own cookie jar), made over HTTP.
            cy.visit('/login?lang=en');
            cy.getCookie('XSRF-TOKEN').then((cookie) => cy.request({ method: 'POST', url: '/login', body: { email: 'cypress-t3-sessions@taxikosmos.test', password: PASSWORD }, followRedirect: false, headers: { 'X-XSRF-TOKEN': decodeURIComponent(cookie?.value ?? ''), Accept: 'text/html' } }));
            cy.request({ url: '/admin/account', followRedirect: false }).its('status').should('eq', 200);
            cy.getCookies().then((cookies) => { browserB = cookies; });
            cy.clearAllCookies();

            // "Browser A": the real login form.
            signIn('cypress-t3-sessions@taxikosmos.test');
            cy.location('pathname').should('eq', '/admin');
            openAccount('security');
            cy.findByText('Current session').should('be.visible');
            cy.findAllByText(/·.*\d+\.\d+\.\d+\.\d+|·.*::1/).should('have.length', 2);
            cy.findByRole('button', { name: 'Log out other sessions' }).should('be.enabled').click();

            // A wrong password ends nothing.
            confirmPassword('Log out other sessions', 'Log out other sessions', 'wrong-password');
            cy.findByRole('dialog', { name: 'Log out other sessions' }).findByRole('alert').should('have.text', 'The password is incorrect.');
            cy.sql("select count(*)::int as n from sessions s join users u on u.id = s.user_id where u.email = 'cypress-t3-sessions@taxikosmos.test'").its('0.n').should('eq', 2);

            cy.findByRole('dialog', { name: 'Log out other sessions' }).within(() => {
                cy.findByLabelText(/Current password/).type(PASSWORD, { log: false });
                cy.findByRole('button', { name: 'Log out other sessions' }).click();
            });
            cy.contains('Other sessions were signed out.').should('be.visible');
            cy.findByRole('button', { name: 'Log out other sessions' }).should('be.disabled');
            cy.sql("select count(*)::int as n from sessions s join users u on u.id = s.user_id where u.email = 'cypress-t3-sessions@taxikosmos.test'").its('0.n').should('eq', 1);
            userRow('cypress-t3-sessions@taxikosmos.test').then((row) => {
                audit('account.other_sessions_ended', row.id).then((entries) => {
                    expect(entries).to.have.length(1);
                    expect(entries[0].properties.context.sessions).to.equal(1);
                });
            });

            // This browser is still in; the other one is thrown out on its next click.
            cy.reload();
            cy.location('pathname').should('eq', '/admin/account');
            cy.getCookies().then((cookies) => { browserA = cookies; });
            cy.clearAllCookies().then(() => browserB.forEach((cookie) => cy.setCookie(cookie.name, cookie.value)));
            cy.request({ url: '/admin/account', followRedirect: false }).then((response) => {
                expect(response.status).to.be.oneOf([302, 303]);
                expect(String(response.headers.location)).to.match(/\/login$/);
            });
            cy.clearAllCookies().then(() => browserA.forEach((cookie) => cy.setCookie(cookie.name, cookie.value)));
            cy.request({ url: '/admin/account', followRedirect: false }).its('status').should('eq', 200);
        });

        it('refuses the three account writes to a guest and keeps another admin\'s sessions out of the list', () => {
            cy.visit('/login?lang=en');
            [['PATCH', '/admin/account/profile'], ['PUT', '/admin/account/password'], ['DELETE', '/admin/account/sessions']].forEach(([method, url]) => {
                cy.adminRequest(method, url, {}).then((response) => {
                    expect(response.status, `${method} ${url}`).to.be.oneOf([302, 303, 401, 419]);
                });
            });
            cy.sql("select count(*)::int as n from activity_log where description in ('account.profile_updated','account.password_changed','account.other_sessions_ended') and causer_id is null").its('0.n').should('eq', 0);
            cy.request({ url: '/admin/account', followRedirect: false }).then((response) => {
                expect(response.status).to.be.oneOf([302, 303]);
                expect(String(response.headers.location)).to.match(/\/login$/);
            });

            cy.sql("select count(*)::int as n from sessions s join users u on u.id = s.user_id where u.email = 'cypress-t3-sessions-other@taxikosmos.test'").its('0.n').should('eq', 0);
            signInToAdmin('cypress-t3-sessions-other@taxikosmos.test');
            openAccount('security');
            cy.findByText('Current session').should('be.visible');
            cy.findByRole('button', { name: 'Log out other sessions' }).should('be.disabled');
        });
    });
});
