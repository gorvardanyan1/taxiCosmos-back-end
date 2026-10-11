import { execFileSync } from 'node:child_process';
import { defineConfig } from 'cypress';

/**
 * End-to-end tests against the real app running in the `e2e` environment (.env.e2e: its own
 * database, cache, streams and log drivers). See docs/e2e.md.
 */
function artisan(command: string, args: string[]): unknown {
    const output = execFileSync('php', ['artisan', command, ...args], {
        cwd: process.cwd(),
        env: { ...process.env, APP_ENV: 'e2e' },
        encoding: 'utf8',
    });
    const last = output.trim().split('\n').pop() ?? '';

    return last === '' ? null : JSON.parse(last);
}

export default defineConfig({
    e2e: {
        baseUrl: process.env.CYPRESS_BASE_URL ?? 'http://127.0.0.1:8001',
        specPattern: 'cypress/e2e/**/*.cy.ts',
        supportFile: 'cypress/support/e2e.ts',
        viewportWidth: 1440,
        viewportHeight: 900,
        video: false,
        screenshotOnRunFailure: true,
        screenshotsFolder: 'cypress/screenshots',
        downloadsFolder: 'cypress/downloads',
        defaultCommandTimeout: 8000,
        retries: { runMode: 0, openMode: 0 },
        setupNodeEvents(on) {
            on('task', {
                'e2e:reset': () => artisan('e2e:reset', []),
                'e2e:make': ({ fixture, attributes }: { fixture: string; attributes: Record<string, unknown> }) =>
                    artisan('e2e:make', [fixture, JSON.stringify(attributes ?? {})]),
                'e2e:sql': ({ query, bindings }: { query: string; bindings: unknown[] }) =>
                    artisan('e2e:sql', [query, JSON.stringify(bindings ?? [])]),
            });
        },
    },
});
