/// <reference types="cypress" />

export const PASSWORD = 'Cypress-Pass!2026x';

export interface Admin {
    id: number;
    email: string;
    name: string;
    role: string;
    password: string;
}

export interface ApiResponse<T = any> {
    status: number;
    body: T;
    headers: Record<string, string | string[]>;
}

declare global {
    // eslint-disable-next-line @typescript-eslint/no-namespace
    namespace Cypress {
        interface Chainable {
            /** Fresh e2e database (schema + roles), empty cache, streams and e2e files. */
            resetDb(): Chainable<null>;
            /** Run a named fixture from tests/E2e/E2eFixtures.php and yield its JSON result. */
            make<T = any>(fixture: string, attributes?: Record<string, unknown>): Chainable<T>;
            /** One read-only SELECT against the e2e database; yields the rows. */
            sql<T = any>(query: string, bindings?: unknown[]): Chainable<T[]>;
            /** Create an admin with this role (or reuse the given email) and sign in through the real login form. */
            loginAs(email: string, password?: string): Chainable<void>;
            /** JSON request that never fails on status, for API and direct-URL checks. */
            api<T = any>(method: string, path: string, body?: Cypress.RequestBody, token?: string): Chainable<ApiResponse<T>>;
            /** The Inertia props of a page, as the server renders them for this session (errors, flash, data). */
            pageProps<T = any>(path: string): Chainable<T>;
            /** A same-session request to an admin URL with the CSRF token, like the app would send. */
            adminRequest<T = any>(method: string, path: string, body?: Cypress.RequestBody): Chainable<ApiResponse<T>>;
        }
    }
}

Cypress.Commands.add('resetDb', () => cy.task('e2e:reset', null, { log: false, timeout: 60000 }));

Cypress.Commands.add('make', (fixture: string, attributes: Record<string, unknown> = {}) =>
    cy.task<any>('e2e:make', { fixture, attributes }, { log: false, timeout: 30000 }).then((result) => {
        Cypress.log({ name: 'make', message: fixture });
        return result;
    }));

Cypress.Commands.add('sql', (query: string, bindings: unknown[] = []) => {
    Cypress.log({ name: 'sql', message: query });

    return cy.task<any[]>('e2e:sql', { query, bindings }, { log: false, timeout: 30000 });
});

Cypress.Commands.add('loginAs', (email: string, password: string = PASSWORD) => {
    cy.session(
        ['admin', email],
        () => {
            cy.visit('/login');
            cy.findByLabelText('Email').type(email);
            cy.findByLabelText('Password').type(password, { log: false });
            cy.findByRole('button', { name: 'Sign in' }).click();
            cy.location('pathname').should('eq', '/admin');
        },
        {
            validate: () => {
                cy.request({ url: '/admin/account', followRedirect: false }).its('status').should('eq', 200);
            },
        },
    );
});

Cypress.Commands.add('api', (method: string, path: string, body?: Cypress.RequestBody, token?: string) =>
    cy.request({
        method,
        url: path,
        body,
        failOnStatusCode: false,
        headers: { Accept: 'application/json', ...(token ? { Authorization: `Bearer ${token}` } : {}) },
    }).then((response) => ({ status: response.status, body: response.body, headers: response.headers }) as ApiResponse));

Cypress.Commands.add('pageProps', (path: string) =>
    cy.request({ url: path }).then((response) => {
        const match = /data-page="([^"]*)"/.exec(String(response.body));
        expect(match, `Inertia page data on ${path}`).not.to.equal(null);
        const textarea = document.createElement('textarea');
        textarea.innerHTML = match![1];

        return JSON.parse(textarea.value).props;
    }));

Cypress.Commands.add('adminRequest', (method: string, path: string, body?: Cypress.RequestBody) =>
    cy.getCookie('XSRF-TOKEN').then((cookie) =>
        cy.request({
            method,
            url: path,
            body,
            failOnStatusCode: false,
            followRedirect: false,
            headers: {
                Accept: 'text/html, application/xhtml+xml',
                'X-Requested-With': 'XMLHttpRequest',
                'X-Inertia': 'true',
                'X-XSRF-TOKEN': decodeURIComponent(cookie?.value ?? ''),
            },
        }).then((response) => ({ status: response.status, body: response.body, headers: response.headers }) as ApiResponse)));

export {};
