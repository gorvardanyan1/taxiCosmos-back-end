/**
 * P3-T2 · Two-factor authentication for admin accounts (Fortify TOTP on the session guard).
 * The first group runs on the normal e2e server (the "required roles" rule is off there, like for every other spec):
 * turning it on, the sign-in challenge, recovery codes, regenerating them, turning it off, and a super admin's reset.
 * The second group runs on a second server started with ADMIN_2FA_REQUIRED_ROLES=super_admin,finance (the production
 * default), for the rule that those roles can reach nothing but the setup screen and cannot switch it off.
 * Codes come from the authenticator app's algorithm through the e2e helper (`two_factor_code`); Fortify accepts each code
 * once, so a sign-in uses the next 30-second code (steps 1) and a second one the step before it (still accepted, and a different code whatever the clock did in between).
 */
import { PASSWORD } from '../support/commands';

const REQUIRED_SERVER = 'http://localhost:8005'; // ADMIN_2FA_REQUIRED_ROLES=super_admin,finance (a different cookie domain than 127.0.0.1)

const user = (email: string) => cy.sql('select id, two_factor_secret is not null as has_secret, two_factor_confirmed_at is not null as confirmed, two_factor_recovery_codes is not null as has_codes from users where email = ?', [email]).then((rows) => rows[0]);
const audit = (action: string, subject: number) => cy.sql("select causer_id, properties->>'reason' as reason, properties->'context'->>'recovery_codes' as codes_total, properties->'context'->>'recovery_codes_left' as codes_left, properties::text as properties, log_name from activity_log where description = ? and subject_id = ? order by id", [action, subject]);
const codeFor = (email: string, steps = 1) => cy.make<{ code: string }>('two_factor_code', { email, steps }).then((result) => result.code);
/** A code and the absolute 30-second step it belongs to (to ask for the step before it later). */
const codeWithStep = (email: string, steps = 1) => cy.make<{ code: string; step: number }>('two_factor_code', { email, steps });

const formSignIn = (email: string) => {
    cy.visit('/login?lang=en');
    cy.findByRole('button', { name: 'Sign in' }).should('be.visible').and('be.enabled');
    cy.get('html').should('have.attr', 'lang', 'en');
    cy.findByLabelText('Email').should('be.visible').type(email);
    cy.findByLabelText('Password').should('be.visible').type(PASSWORD, { log: false });
    cy.findByRole('button', { name: 'Sign in' }).click();
};
const typeCode = (code: string) => {
    cy.findByLabelText('Authentication code').should('be.visible').clear().type(code);
    cy.findByRole('button', { name: 'Verify code' }).click();
};
/** Signs in through the real form and the challenge, cached for the test. */
const sessionWithTwoFactor = (email: string) => cy.session(['two-factor', email, Cypress.config('baseUrl')], () => {
    formSignIn(email);
    cy.location('pathname').should('eq', '/two-factor-challenge');
    codeFor(email).then(typeCode);
    cy.location('pathname').should('eq', '/admin');
}, { validate: () => { cy.request({ url: '/admin/account', followRedirect: false }).its('status').should('eq', 200); } });
/** Signs in with the password alone (an account that has no two-factor yet), cached for the test. */
const sessionWithPassword = (email: string, landing: string) => cy.session(['password', email, Cypress.config('baseUrl')], () => {
    formSignIn(email);
    cy.location('pathname').should('eq', '/admin');
}, { validate: () => { cy.request({ url: landing, followRedirect: false }).its('status').should('eq', 200); } });

const openSecurity = () => cy.visit('/admin/account?tab=security');
const confirmPassword = (dialogName: string, buttonName: string, password = PASSWORD) => {
    cy.findByRole('dialog', { name: dialogName }).within(() => {
        cy.findByLabelText(/Current password/).type(password, { log: false });
        cy.findByRole('button', { name: buttonName }).click();
    });
};
const recoveryCodesOnScreen = () => cy.findAllByText(/^[A-Za-z0-9]{10}-[A-Za-z0-9]{10}$/).then(($codes) => [...$codes].map((element) => element.textContent as string));

describe('P3-T2 · Two-factor authentication for admins', () => {
    before(() => {
        cy.resetDb();
        cy.make('admin', { email: 'cy-2fa-super@taxikosmos.test', role: 'super_admin', name: 'Zed Super' });
        cy.make('admin', { email: 'cy-2fa-admin@taxikosmos.test', role: 'admin', name: 'Adam Admin' });
    });

    beforeEach(() => {
        Cypress.session.clearAllSavedSessions();
        cy.clearAllCookies();
        cy.make('clear_rate_limits');
    });

    // Criterion: enable endpoints with password confirmation; screens: setup (QR + recovery codes).
    it('turns it on from My Account: the password, then the QR code and recovery codes, then a code from the app', () => {
        cy.make('admin', { email: 'cy-2fa-on@taxikosmos.test', role: 'support', name: 'Olive On' });
        cy.loginAs('cy-2fa-on@taxikosmos.test');
        openSecurity();
        cy.contains('Disabled').should('be.visible');

        // A wrong password changes nothing and the message shows next to the field.
        cy.findByRole('button', { name: 'Enable two-factor' }).click();
        confirmPassword('Enable two-factor authentication', 'Continue', 'Not-The-Password-1');
        cy.findByRole('dialog').within(() => cy.findByRole('alert').should('contain', 'The password is incorrect.'));
        user('cy-2fa-on@taxikosmos.test').then((row) => expect(row).to.include({ has_secret: false, confirmed: false }));

        cy.findByRole('dialog').within(() => {
            cy.findByLabelText(/Current password/).type(PASSWORD, { log: false });
            cy.findByRole('button', { name: 'Continue' }).click();
        });

        // The setup screen: QR code, the secret to type in by hand, eight recovery codes. Nothing is active yet.
        cy.location('pathname').should('eq', '/two-factor-setup');
        cy.contains('Protect your account with a code from an authenticator app.').should('be.visible');
        cy.get('svg').should('exist');
        cy.findByText(/^[A-Z2-7]{16}$/).invoke('text').then((secret) => {
            recoveryCodesOnScreen().then((codes) => {
                expect(codes).to.have.length(8);
                expect(new Set(codes).size).to.equal(8);
            });
            user('cy-2fa-on@taxikosmos.test').then((row) => expect(row).to.include({ has_secret: true, confirmed: false, has_codes: true }));
            // Stored as ciphertext, never as the secret itself.
            cy.sql('select position(? in two_factor_secret) as at from users where email = ?', [secret, 'cy-2fa-on@taxikosmos.test']).its('0.at').should('eq', 0);

            // A wrong code, and the "I have saved these codes" box left empty: each stays on the page with its message next to the field.
            cy.findByLabelText('I have saved these codes').check();
            cy.findByLabelText('Authentication code').type('000000');
            cy.findByRole('button', { name: 'Enable two-factor' }).click();
            cy.findAllByRole('alert').first().should('contain', 'The provided two factor authentication code was invalid.');
            user('cy-2fa-on@taxikosmos.test').then((row) => expect(row.confirmed).to.equal(false));

            cy.findByLabelText('I have saved these codes').uncheck();
            cy.findByLabelText('Authentication code').clear();
            codeFor('cy-2fa-on@taxikosmos.test', 0).then((code) => {
                cy.findByLabelText('Authentication code').type(code);
                cy.findByRole('button', { name: 'Enable two-factor' }).click();
            });
            cy.findAllByRole('alert').should('contain', 'The confirmed saved codes field must be accepted.');
            user('cy-2fa-on@taxikosmos.test').then((row) => expect(row.confirmed).to.equal(false));

            cy.findByLabelText('I have saved these codes').check();
            cy.findByRole('button', { name: 'Enable two-factor' }).click();
        });

        cy.location('search').should('eq', '?tab=security');
        cy.findAllByRole('status').last().should('contain', 'Two-factor authentication is on.');
        cy.reload();
        cy.contains('Enabled').should('be.visible');
        cy.contains('8 recovery codes left').should('be.visible');
        cy.findByRole('button', { name: 'Disable two-factor' }).should('be.visible');
        user('cy-2fa-on@taxikosmos.test').then((row) => {
            expect(row).to.include({ has_secret: true, confirmed: true, has_codes: true });
            audit('admin.two_factor.setup_started', row.id).its('length').should('eq', 1);
            audit('admin.two_factor.enabled', row.id).then((entries) => {
                expect(entries).to.have.length(1);
                expect(entries[0].causer_id).to.equal(row.id);
                expect(entries[0].codes_total).to.equal('8');
            });
        });
    });

    // Criterion: the login flow requires a 2FA code when enabled (and a code works once).
    it('asks for a code after the password, refuses a wrong or a reused code, and accepts a fresh one', () => {
        cy.make('admin', { email: 'cy-2fa-login@taxikosmos.test', role: 'support', name: 'Lena Login', two_factor: true });
        cy.intercept('POST', '/two-factor-challenge').as('challenge');

        formSignIn('cy-2fa-login@taxikosmos.test');
        cy.location('pathname').should('eq', '/two-factor-challenge');
        cy.request({ url: '/admin', followRedirect: false }).its('status').should('be.oneOf', [302, 303]); // the password alone opened nothing

        typeCode('000000');
        cy.wait('@challenge');
        cy.findByRole('alert').should('contain', 'The provided two factor authentication code was invalid.');
        cy.location('pathname').should('eq', '/two-factor-challenge');

        codeWithStep('cy-2fa-login@taxikosmos.test', 1).then(({ code, step }) => {
            cy.wrap(code).as('used');
            cy.wrap(step).as('usedStep');
            typeCode(code);
        });
        cy.location('pathname').should('eq', '/admin');
        cy.contains('header', 'Lena Login').should('be.visible');

        // The same code does not work a second time, on a new sign-in; the code of the step before it does.
        cy.clearAllCookies();
        formSignIn('cy-2fa-login@taxikosmos.test');
        cy.get<string>('@used').then(typeCode);
        cy.wait('@challenge');
        cy.findByRole('alert').should('contain', 'The provided two factor authentication code was invalid.');
        cy.get<number>('@usedStep').then((step) => cy.make<{ code: string }>('two_factor_code', { email: 'cy-2fa-login@taxikosmos.test', at: step - 1 })).then(({ code }) => typeCode(code));
        cy.location('pathname').should('eq', '/admin');

        user('cy-2fa-login@taxikosmos.test').then((row) => {
            audit('auth.two_factor_failed', row.id).then((entries) => {
                expect(entries.length).to.be.at.least(2);
                expect(entries[0].log_name).to.equal('auth');
            });
            audit('auth.login', row.id).its('length').should('eq', 2);
        });
    });

    it('does not let the challenge be reached without the password step', () => {
        cy.request({ url: '/two-factor-challenge', followRedirect: false }).then((response) => {
            expect(response.status).to.be.oneOf([302, 303]);
            expect(String(response.headers.location)).to.match(/\/login$/);
        });
    });

    // Criterion: throttle on guessing codes.
    it('answers the 6th code in a minute with 429 and Retry-After, and signs nobody in', () => {
        cy.make('admin', { email: 'cy-2fa-limit@taxikosmos.test', role: 'support', name: 'Rita Limit', two_factor: true });
        cy.intercept('POST', '/two-factor-challenge').as('challenge');
        formSignIn('cy-2fa-limit@taxikosmos.test');
        cy.location('pathname').should('eq', '/two-factor-challenge');

        for (let attempt = 1; attempt <= 5; attempt++) {
            typeCode('000000');
            cy.wait('@challenge').its('response.statusCode').should('be.oneOf', [302, 303]);
            cy.findByRole('alert').should('contain', 'The provided two factor authentication code was invalid.');
        }

        codeFor('cy-2fa-limit@taxikosmos.test', 1).then(typeCode);
        cy.wait('@challenge').then(({ response }) => {
            expect(response?.statusCode).to.equal(429);
            expect(response?.headers['retry-after']).to.match(/^\d+$/);
        });
        cy.contains('Too many requests').should('be.visible');
        cy.request({ url: '/admin', followRedirect: false }).its('status').should('be.oneOf', [302, 303]);
    });

    // Criterion: 8 single-use recovery codes; using one is recorded in the activity log.
    it('signs in with a recovery code once, leaves seven, and records it without the code', () => {
        cy.make('admin', { email: 'cy-2fa-recover@taxikosmos.test', role: 'support', name: 'Ria Recover', two_factor: true });
        cy.make<{ codes: string[] }>('two_factor_recovery_codes', { email: 'cy-2fa-recover@taxikosmos.test' }).then(({ codes }) => {
            expect(codes).to.have.length(8);

            formSignIn('cy-2fa-recover@taxikosmos.test');
            cy.findByRole('button', { name: 'Use a recovery code instead' }).click();
            cy.findByLabelText('Recovery code').type(codes[0]);
            cy.findByRole('button', { name: 'Verify code' }).click();
            cy.location('pathname').should('eq', '/admin');

            // The same code, on a new sign-in, is refused.
            cy.clearAllCookies();
            formSignIn('cy-2fa-recover@taxikosmos.test');
            cy.findByRole('button', { name: 'Use a recovery code instead' }).click();
            cy.findByLabelText('Recovery code').type(codes[0]);
            cy.findByRole('button', { name: 'Verify code' }).click();
            cy.findByRole('alert').should('contain', 'The provided two factor recovery code was invalid.');
            cy.location('pathname').should('eq', '/two-factor-challenge');

            // Another code works; the count goes down.
            cy.findByLabelText('Recovery code').clear().type(codes[1]);
            cy.findByRole('button', { name: 'Verify code' }).click();
            cy.location('pathname').should('eq', '/admin');
            openSecurity();
            cy.contains('6 recovery codes left').should('be.visible');

            user('cy-2fa-recover@taxikosmos.test').then((row) => audit('auth.recovery_code_used', row.id).then((entries) => {
                expect(entries).to.have.length(2);
                expect(entries[0].causer_id).to.equal(row.id);
                expect(entries[0].log_name).to.equal('auth');
                expect(entries.map((entry) => entry.codes_left)).to.deep.equal(['7', '6']);
                codes.forEach((code) => expect(entries.map((entry) => entry.properties).join(' ')).not.to.contain(code));
            }));
        });
    });

    // Criterion: recovery codes can be regenerated, which invalidates the old set.
    it('regenerates the recovery codes behind the password: eight new ones shown once, the old ones dead', () => {
        cy.make('admin', { email: 'cy-2fa-regen@taxikosmos.test', role: 'support', name: 'Gena Regen', two_factor: true });
        cy.make<{ codes: string[] }>('two_factor_recovery_codes', { email: 'cy-2fa-regen@taxikosmos.test' }).then(({ codes: oldCodes }) => {
            sessionWithTwoFactor('cy-2fa-regen@taxikosmos.test');
            openSecurity();

            cy.findByRole('button', { name: 'Regenerate recovery codes' }).click();
            confirmPassword('Regenerate recovery codes', 'Regenerate recovery codes', 'Not-The-Password-1');
            cy.findByRole('dialog').within(() => cy.findByRole('alert').should('contain', 'The password is incorrect.'));
            cy.make<{ codes: string[] }>('two_factor_recovery_codes', { email: 'cy-2fa-regen@taxikosmos.test' }).its('codes').should('deep.equal', oldCodes);

            cy.findByRole('dialog').within(() => {
                cy.findByLabelText(/Current password/).type(PASSWORD, { log: false });
                cy.findByRole('button', { name: 'Regenerate recovery codes' }).click();
            });
            cy.findByRole('dialog', { name: 'Your new recovery codes' }).within(() => {
                cy.contains('this is the only time they are shown').should('be.visible');
                recoveryCodesOnScreen().then((shown) => {
                    expect(shown).to.have.length(8);
                    expect(shown.filter((code) => oldCodes.includes(code))).to.have.length(0);
                    cy.make<{ codes: string[] }>('two_factor_recovery_codes', { email: 'cy-2fa-regen@taxikosmos.test' }).its('codes').should('deep.equal', shown);
                    cy.wrap(shown).as('newCodes');
                });
                cy.findByRole('button', { name: 'Done' }).click();
            });

            // Shown once: gone after a reload.
            cy.reload();
            cy.findByRole('dialog').should('not.exist');
            cy.contains('8 recovery codes left').should('be.visible');

            // The old set is dead at sign-in, the new set works.
            cy.clearAllCookies();
            formSignIn('cy-2fa-regen@taxikosmos.test');
            cy.findByRole('button', { name: 'Use a recovery code instead' }).click();
            cy.findByLabelText('Recovery code').type(oldCodes[0]);
            cy.findByRole('button', { name: 'Verify code' }).click();
            cy.findByRole('alert').should('contain', 'The provided two factor recovery code was invalid.');
            cy.get<string[]>('@newCodes').then((fresh) => {
                cy.findByLabelText('Recovery code').clear().type(fresh[0]);
                cy.findByRole('button', { name: 'Verify code' }).click();
            });
            cy.location('pathname').should('eq', '/admin');

            user('cy-2fa-regen@taxikosmos.test').then((row) => audit('admin.two_factor.recovery_codes_regenerated', row.id).then((entries) => {
                expect(entries).to.have.length(1);
                expect(entries[0].causer_id).to.equal(row.id);
                oldCodes.forEach((code) => expect(entries[0].properties).not.to.contain(code));
            }));
        });
    });

    // Criterion: enable/disable endpoints with password confirmation.
    it('turns it off behind the password: a wrong password changes nothing, then the password alone signs in', () => {
        cy.make('admin', { email: 'cy-2fa-off@taxikosmos.test', role: 'support', name: 'Dora Off', two_factor: true });
        sessionWithTwoFactor('cy-2fa-off@taxikosmos.test');
        openSecurity();
        cy.contains('Enabled').should('be.visible');

        cy.findByRole('button', { name: 'Disable two-factor' }).click();
        confirmPassword('Disable two-factor authentication', 'Disable two-factor', 'Not-The-Password-1');
        cy.findByRole('dialog').within(() => cy.findByRole('alert').should('contain', 'The password is incorrect.'));
        user('cy-2fa-off@taxikosmos.test').then((row) => expect(row.confirmed).to.equal(true));

        cy.findByRole('dialog').within(() => {
            cy.findByLabelText(/Current password/).type(PASSWORD, { log: false });
            cy.findByRole('button', { name: 'Disable two-factor' }).click();
        });
        cy.findAllByRole('status').last().should('contain', 'Two-factor authentication is off.');
        cy.reload();
        cy.contains('Disabled').should('be.visible');
        cy.findByRole('button', { name: 'Enable two-factor' }).should('be.visible');
        user('cy-2fa-off@taxikosmos.test').then((row) => {
            expect(row).to.include({ has_secret: false, confirmed: false, has_codes: false });
            audit('admin.two_factor.disabled', row.id).its('length').should('eq', 1);
        });

        cy.clearAllCookies();
        formSignIn('cy-2fa-off@taxikosmos.test');
        cy.location('pathname').should('eq', '/admin');
    });

    // Criterion: a super_admin can reset another admin's 2FA (activity-logged), for lost devices.
    it('lets a super admin reset another admin\'s two-factor with their password and a reason, and logs it', () => {
        cy.make('admin', { email: 'cy-2fa-lost@taxikosmos.test', role: 'support', name: 'Aaron Lostphone', two_factor: true });
        cy.make('admin', { email: 'cy-2fa-plain@taxikosmos.test', role: 'dispatcher', name: 'Ada Plain' });
        cy.loginAs('cy-2fa-super@taxikosmos.test');
        cy.visit('/admin/settings/users');

        cy.contains('tr', 'Ada Plain').within(() => cy.findByRole('button', { name: /Reset two-factor for/ }).should('not.exist')); // nothing to reset
        cy.findByRole('button', { name: 'Reset two-factor for Zed Super' }).should('not.exist'); // not for yourself
        cy.findByRole('button', { name: 'Reset two-factor for Aaron Lostphone' }).click();
        cy.findByRole('dialog', { name: 'Reset two-factor authentication' }).within(() => {
            cy.findByRole('button', { name: 'Reset two-factor' }).should('be.disabled');
            cy.findByLabelText(/Current password/).type('Not-The-Password-1', { log: false });
            cy.findByLabelText(/Reason/).type('Phone lost on a trip');
            cy.findByRole('button', { name: 'Reset two-factor' }).click();
            cy.findByRole('alert').should('contain', 'The password is incorrect.');
        });
        user('cy-2fa-lost@taxikosmos.test').then((row) => expect(row.confirmed).to.equal(true));

        cy.findByRole('dialog').within(() => {
            cy.findByLabelText(/Current password/).type(PASSWORD, { log: false });
            cy.findByRole('button', { name: 'Reset two-factor' }).click();
        });
        cy.findAllByRole('status').last().should('contain', 'Two-factor authentication was reset.');
        cy.reload();
        cy.contains('tr', 'Aaron Lostphone').should('contain', 'Disabled');

        user('cy-2fa-lost@taxikosmos.test').then((row) => {
            expect(row).to.include({ has_secret: false, confirmed: false, has_codes: false });
            audit('admin.two_factor.reset', row.id).then((entries) => {
                expect(entries).to.have.length(1);
                expect(entries[0].reason).to.equal('Phone lost on a trip');
                cy.sql('select id from users where email = ?', ['cy-2fa-super@taxikosmos.test']).its('0.id').then((superId) => expect(entries[0].causer_id).to.equal(superId));
            });
        });

        // The reset admin signs in with the password alone again.
        cy.clearAllCookies();
        formSignIn('cy-2fa-lost@taxikosmos.test');
        cy.location('pathname').should('eq', '/admin');
    });

    it('requires the reason and refuses everyone but a super admin: no action in the UI, 403 on the direct request, a guest is sent to sign in', () => {
        cy.make('admin', { email: 'cy-2fa-target@taxikosmos.test', role: 'support', name: 'Abel Target', two_factor: true });
        user('cy-2fa-target@taxikosmos.test').then((target) => {
            // A guest.
            cy.request({ method: 'POST', url: `/admin/settings/users/${target.id}/two-factor/reset`, body: { password: PASSWORD, reason: 'x' }, followRedirect: false, failOnStatusCode: false }).its('status').should('be.oneOf', [302, 303, 419]);

            // An admin without admins.manage: the Users page and the reset are both 403.
            cy.loginAs('cy-2fa-admin@taxikosmos.test');
            cy.request({ url: '/admin/settings/users', failOnStatusCode: false }).its('status').should('eq', 403);
            cy.visit('/admin/account');
            cy.adminRequest('POST', `/admin/settings/users/${target.id}/two-factor/reset`, { password: PASSWORD, reason: 'x' }).its('status').should('eq', 403);

            // A super admin without a reason: nothing is reset.
            cy.loginAs('cy-2fa-super@taxikosmos.test');
            cy.visit('/admin/settings/users');
            cy.findByRole('button', { name: 'Reset two-factor for Abel Target' }).click();
            cy.findByRole('dialog').within(() => {
                cy.findByLabelText(/Current password/).type(PASSWORD, { log: false });
                cy.findByRole('button', { name: 'Reset two-factor' }).should('be.disabled');
                cy.findByLabelText(/Reason/).type('   ');
                cy.findByRole('button', { name: 'Reset two-factor' }).should('be.disabled');
            });
            cy.adminRequest('POST', `/admin/settings/users/${target.id}/two-factor/reset`, { password: PASSWORD, reason: '' }).its('status').should('be.oneOf', [302, 303, 422]);
            cy.adminRequest('POST', '/admin/settings/users/99999999/two-factor/reset', { password: PASSWORD, reason: 'x' }).its('status').should('eq', 404);
            user('cy-2fa-target@taxikosmos.test').then((row) => expect(row.confirmed).to.equal(true));
            audit('admin.two_factor.reset', target.id).should('have.length', 0);
        });
    });

    it('closes Fortify\'s own two-factor routes: nothing but our endpoints can change it', () => {
        cy.make('admin', { email: 'cy-2fa-routes@taxikosmos.test', role: 'support', name: 'Rob Routes', two_factor: true });
        sessionWithTwoFactor('cy-2fa-routes@taxikosmos.test');
        cy.visit('/admin/account');

        ['POST /user/two-factor-authentication', 'DELETE /user/two-factor-authentication', 'POST /user/confirmed-two-factor-authentication', 'POST /user/two-factor-recovery-codes'].forEach((route) => {
            const [method, path] = route.split(' ');
            cy.adminRequest(method, path, { password: PASSWORD, code: '123456' }).its('status').should('eq', 404);
        });
        ['/user/two-factor-qr-code', '/user/two-factor-secret-key', '/user/two-factor-recovery-codes'].forEach((path) => cy.request({ url: path, failOnStatusCode: false }).its('status').should('eq', 404));
        user('cy-2fa-routes@taxikosmos.test').then((row) => expect(row).to.include({ has_secret: true, confirmed: true }));
    });

    it('answers a guest on every two-factor endpoint with the sign-in page, and a wrong password with an error, nothing changed', () => {
        ['/two-factor-setup'].forEach((path) => cy.request({ url: path, followRedirect: false }).then((response) => {
            expect(response.status).to.be.oneOf([302, 303]);
            expect(String(response.headers.location)).to.match(/\/login$/);
        }));

        cy.make('admin', { email: 'cy-2fa-valid@taxikosmos.test', role: 'support', name: 'Vera Valid' });
        cy.loginAs('cy-2fa-valid@taxikosmos.test');
        cy.visit('/two-factor-setup');
        cy.findByRole('button', { name: 'Generate QR code' }).should('be.disabled');
        cy.findByLabelText('Current password').type('Not-The-Password-1', { log: false });
        cy.findByRole('button', { name: 'Generate QR code' }).click();
        cy.findByRole('alert').should('contain', 'The password is incorrect.');
        user('cy-2fa-valid@taxikosmos.test').then((row) => expect(row).to.include({ has_secret: false, confirmed: false }));
        // Confirming without having started is a conflict, not an error page.
        cy.adminRequest('POST', '/two-factor-setup/confirm', { code: '123456', confirmed_saved_codes: true }).its('status').should('be.oneOf', [302, 303, 409]);
        user('cy-2fa-valid@taxikosmos.test').then((row) => expect(row.confirmed).to.equal(false));
    });

    // Criterion: super_admin and finance must have it, and cannot disable it (the production default, on its own server).
    describe('with the rule on for super_admin and finance (ADMIN_2FA_REQUIRED_ROLES, the default outside tests)', { baseUrl: REQUIRED_SERVER }, () => {
        before(() => {
            cy.task('e2e:server:start', { port: 8005, env: { ADMIN_2FA_REQUIRED_ROLES: 'super_admin,finance' } });
            cy.request({ url: 'http://127.0.0.1:8005/up', retryOnNetworkFailure: true, failOnStatusCode: false }).its('status').should('eq', 200);
        });

        after(() => {
            cy.task('e2e:server:stop');
        });

        it('sends a finance admin without it to the setup screen from every admin page, and refuses their writes', () => {
            cy.make('admin', { email: 'cy-2fa-fin@taxikosmos.test', role: 'finance', name: 'Fiona Finance' });
            formSignIn('cy-2fa-fin@taxikosmos.test');

            cy.location('pathname').should('eq', '/two-factor-setup');
            cy.contains('Your role requires two-factor authentication.').should('be.visible');
            ['/admin', '/admin/drivers', '/admin/account', '/admin/riders', '/horizon'].forEach((path) => {
                cy.request({ url: path, followRedirect: false, failOnStatusCode: false }).then((response) => {
                    expect(response.status, path).to.be.oneOf([302, 303]);
                    expect(String(response.headers.location), path).to.match(/\/two-factor-setup$/);
                });
            });
            cy.visit('/admin/drivers');
            cy.location('pathname').should('eq', '/two-factor-setup');
            cy.request({ method: 'PATCH', url: '/admin/account/locale', body: { locale: 'ru' }, headers: { Accept: 'application/json' }, failOnStatusCode: false }).its('status').should('be.oneOf', [403, 419]);
            cy.adminRequest('PATCH', '/admin/account/locale', { locale: 'ru' }).its('status').should('eq', 403);

            // Signing out is still possible from the setup screen.
            cy.findByRole('button', { name: 'Sign out' }).click();
            cy.location('pathname').should('eq', '/login');
        });

        it('opens the admin once the setup is finished, and then offers no way to switch it off', () => {
            cy.make('admin', { email: 'cy-2fa-fin2@taxikosmos.test', role: 'finance', name: 'Finn Finance' });
            formSignIn('cy-2fa-fin2@taxikosmos.test');
            cy.location('pathname').should('eq', '/two-factor-setup');

            cy.findByLabelText('Current password').type(PASSWORD, { log: false });
            cy.findByRole('button', { name: 'Generate QR code' }).click();
            cy.findByLabelText('Authentication code').should('be.visible');
            // Half-finished does not count: the admin is still kept on the setup screen.
            cy.request({ url: '/admin', followRedirect: false }).its('status').should('be.oneOf', [302, 303]);
            codeFor('cy-2fa-fin2@taxikosmos.test', 0).then((code) => cy.findByLabelText('Authentication code').type(code));
            cy.findByLabelText('I have saved these codes').check();
            cy.findByRole('button', { name: 'Enable two-factor' }).click();

            cy.location('search').should('eq', '?tab=security');
            cy.contains('Enabled').should('be.visible');
            cy.contains('Your role requires two-factor authentication.').should('be.visible');
            cy.findByRole('button', { name: 'Disable two-factor' }).should('not.exist');
            cy.findByRole('button', { name: 'Regenerate recovery codes' }).should('be.visible');
            cy.visit('/admin/drivers');
            cy.location('pathname').should('eq', '/admin/drivers');

            // Directly: refused, with the reason, and still on.
            cy.adminRequest('DELETE', '/admin/account/two-factor', { password: PASSWORD }).its('status').should('eq', 403);
            cy.adminRequest('DELETE', '/admin/account/two-factor', {}).its('status').should('eq', 403);
            user('cy-2fa-fin2@taxikosmos.test').then((row) => {
                expect(row).to.include({ has_secret: true, confirmed: true });
                audit('admin.two_factor.disabled', row.id).should('have.length', 0);
            });
        });

        it('sends a finance admin back to the setup screen after a super admin reset their two-factor', () => {
            cy.make('admin', { email: 'cy-2fa-fin3@taxikosmos.test', role: 'finance', name: 'Fay Finance', two_factor: true });
            cy.make('admin', { email: 'cy-2fa-boss@taxikosmos.test', role: 'super_admin', name: 'Bo Boss', two_factor: true });

            // Fay is signed in; her cookies are kept to come back with the same session after the reset.
            sessionWithTwoFactor('cy-2fa-fin3@taxikosmos.test');
            cy.visit('/admin/account');
            cy.location('pathname').should('eq', '/admin/account');
            cy.getCookies().then((cookies) => cy.wrap(cookies).as('fayCookies'));

            sessionWithTwoFactor('cy-2fa-boss@taxikosmos.test');
            cy.visit('/admin/settings/users');
            cy.findByRole('button', { name: 'Reset two-factor for Fay Finance' }).click();
            cy.findByRole('dialog').within(() => {
                cy.findByLabelText(/Current password/).type(PASSWORD, { log: false });
                cy.findByLabelText(/Reason/).type('Lost the phone');
                cy.findByRole('button', { name: 'Reset two-factor' }).click();
            });
            cy.findAllByRole('status').last().should('contain', 'Two-factor authentication was reset.');

            cy.clearAllCookies();
            cy.get<Cypress.Cookie[]>('@fayCookies').then((cookies) => cookies.forEach((cookie) => cy.setCookie(cookie.name, cookie.value, { path: cookie.path, httpOnly: cookie.httpOnly, secure: cookie.secure, sameSite: cookie.sameSite })));
            cy.visit('/admin/drivers');
            cy.location('pathname').should('eq', '/two-factor-setup');
            cy.contains('Your role requires two-factor authentication.').should('be.visible');
        });
    });
});

export {};
