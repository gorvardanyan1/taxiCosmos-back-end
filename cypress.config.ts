import { execFileSync, spawn, type ChildProcess } from 'node:child_process';
import { join } from 'node:path';
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

/**
 * Extra app servers for specs that need a different .env (the IP allowlist, P9-T3). Always the e2e environment,
 * only on 127.0.0.1:8002-8009 (8001 is the main e2e server), only the variables the spec names, stopped after the spec.
 */
const extraServers = new Map<number, ChildProcess>();

function startServer(port: number, env: Record<string, string>): number {
    if (!Number.isInteger(port) || port < 8002 || port > 8009) {
        throw new Error(`e2e:server:start only uses ports 8002-8009, got ${port}`);
    }

    const child = spawn('php', ['-S', `127.0.0.1:${port}`, '../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php'], {
        cwd: join(process.cwd(), 'public'),
        env: { ...process.env, ...env, APP_ENV: 'e2e' },
        stdio: 'ignore',
    });
    extraServers.set(port, child);

    return port;
}

function stopServers(): null {
    extraServers.forEach((child) => child.kill());
    extraServers.clear();

    return null;
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
            on('after:run', () => {
                stopServers();
            });
            on('task', {
                'e2e:server:start': ({ port, env }: { port: number; env: Record<string, string> }) => startServer(port, env),
                'e2e:server:stop': () => stopServers(),
                'e2e:reset': () => artisan('e2e:reset', []),
                'e2e:make': ({ fixture, attributes }: { fixture: string; attributes: Record<string, unknown> }) =>
                    artisan('e2e:make', [fixture, JSON.stringify(attributes ?? {})]),
                'e2e:sql': ({ query, bindings }: { query: string; bindings: unknown[] }) =>
                    artisan('e2e:sql', [query, JSON.stringify(bindings ?? [])]),
            });
        },
    },
});
