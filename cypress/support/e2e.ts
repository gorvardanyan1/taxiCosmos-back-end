import '@testing-library/cypress/add-commands';
import './commands';

/**
 * Every test fails on a console error in the app (React warnings, failed prop types, runtime
 * errors that were caught and logged). Uncaught exceptions already fail tests by default.
 */
let consoleErrors: string[] = [];

Cypress.on('window:before:load', (win) => {
    const original = win.console.error.bind(win.console);
    win.console.error = (...args: unknown[]) => {
        consoleErrors.push(args.map(String).join(' '));
        original(...args);
    };
});

beforeEach(() => {
    consoleErrors = [];
});

afterEach(() => {
    cy.then(() => expect(consoleErrors, 'console errors in the app').to.deep.equal([]));
});
