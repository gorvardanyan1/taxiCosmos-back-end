/**
 * P14-T4 · App config: GET /api/v1/app-config, the public start-up call of both apps, against the real server in the e2e
 * environment (the minimum versions are the config defaults: 1.0.0 for every app and platform). The contract the apps rely on:
 * the update gate, feature flags, support and legal blocks, vehicle classes, cache headers, and no secrets.
 */
const config = (query: string, extraHeaders: Record<string, string> = {}) =>
    cy.request({
        url: `/api/v1/app-config?${query}`,
        failOnStatusCode: false,
        headers: { Accept: 'application/json', ...extraHeaders },
    });

describe('P14-T4 · App config', () => {
    beforeEach(() => cy.make('clear_rate_limits'));

    // Criterion: a version below the minimum returns force_update = true.
    it('tells a build below the minimum to update, and lets the minimum and newer ones run', () => {
        config('app=rider&platform=ios&version=0.9.0').then((response) => {
            expect(response.status).to.equal(200);
            expect(response.body.update).to.include({ force_update: true, update_available: true, minimum_version: '1.0.0' });
        });
        config('app=rider&platform=ios&version=1.0.0').then((response) => {
            expect(response.body.update).to.include({ force_update: false, minimum_version: '1.0.0' });
        });
        config('app=driver&platform=android&version=1.10.0').then((response) => {
            expect(response.body.update.force_update, '1.10.0 is newer than 1.0.0').to.equal(false);
        });
    });

    // Criterion: a missing or malformed version is treated as unsupported.
    it('treats a missing or malformed version as unsupported', () => {
        for (const query of [
            'app=rider&platform=android',
            'app=rider&platform=android&version=',
            'app=rider&platform=android&version=banana',
            'app=rider&platform=android&version=1.2.3-beta',
            'app=rider&platform=android&version=1.2.3.4',
        ]) {
            config(query).then((response) => {
                expect(response.status, query).to.equal(200);
                expect(response.body.update.force_update, query).to.equal(true);
            });
        }
    });

    // Criterion: the response carries the flags, support, legal and vehicle blocks in a stable shape.
    it('answers with the documented contract, with the MVP features switched off', () => {
        config('app=rider&platform=ios&version=1.0.0').then((response) => {
            expect(response.status).to.equal(200);
            expect(response.body).to.have.all.keys('app', 'platform', 'update', 'features', 'support', 'legal', 'vehicle_classes');
            expect(response.body).to.include({ app: 'rider', platform: 'ios' });
            expect(response.body.update).to.have.all.keys('minimum_version', 'latest_version', 'force_update', 'update_available', 'store_url');
            expect(response.body.features).to.deep.equal({
                card_payments: false, wallet: false, masked_calls: false, sos: false, trip_share: false,
                social_login: { google: false, apple: false, facebook: false },
            });
            expect(response.body.support).to.have.all.keys('phone', 'email');
            expect(response.body.legal.terms).to.have.all.keys('en', 'hy', 'ru');
            expect(response.body.legal.privacy).to.have.all.keys('en', 'hy', 'ru');
            expect(response.body.vehicle_classes.map((c: { id: string }) => c.id)).to.deep.equal(['economy', 'comfort', 'business']);
        });
    });

    // Criterion: the response contains no secrets.
    it('sends nothing but the documented fields, and no token is needed', () => {
        config('app=driver&platform=ios&version=1.0.0', { Authorization: 'Bearer 1|not-a-real-token' }).then((response) => {
            expect(response.status).to.equal(200);
            const text = JSON.stringify(response.body).toLowerCase();
            for (const secret of ['password', 'secret', 'api_key', 'apikey', 'token', 'sk_live', 'base64:']) {
                expect(text, `the answer must not contain "${secret}"`).not.to.contain(secret);
            }
        });
    });

    // Criterion: the response is cached.
    it('is publicly cacheable and answers 304 to a client that already has it', () => {
        config('app=rider&platform=ios&version=1.0.0').then((response) => {
            expect(response.headers['cache-control']).to.match(/public/).and.to.match(/max-age=\d+/);
            expect(String(response.headers['vary']).toLowerCase()).to.contain('accept-language');
            const etag = String(response.headers['etag']);
            expect(etag).not.to.equal('');

            config('app=rider&platform=ios&version=1.0.0', { 'If-None-Match': etag }).then((again) => {
                expect(again.status).to.equal(304);
                expect(again.body).to.equal('');
            });
        });
    });

    it('names the vehicle classes in the language of the request', () => {
        config('app=rider&platform=ios&version=1.0.0', { 'Accept-Language': 'ru' }).then((response) => {
            expect(response.headers['content-language']).to.equal('ru');
            expect(response.body.vehicle_classes.map((c: { name: string }) => c.name)).to.deep.equal(['Эконом', 'Комфорт', 'Бизнес']);
        });
        config('app=rider&platform=ios&version=1.0.0').then((response) => {
            expect(response.body.vehicle_classes.map((c: { name: string }) => c.name)).to.deep.equal(['Economy', 'Comfort', 'Business']);
        });
    });

    it('rejects an unknown app or platform with a validation error on that field', () => {
        config('app=admin&platform=ios&version=1.0.0').then((response) => {
            expect(response.status).to.equal(422);
            expect(response.body.errors).to.have.property('app');
        });
        config('app=rider&platform=windows&version=1.0.0').then((response) => {
            expect(response.status).to.equal(422);
            expect(response.body.errors).to.have.property('platform');
        });
        config('version=1.0.0').then((response) => {
            expect(response.status).to.equal(422);
            expect(response.body.errors).to.have.keys('app', 'platform');
        });
    });

    // Criterion: the endpoint is rate limited.
    it('is rate limited per address with a Retry-After', () => {
        const ask = (n: number): Cypress.Chainable<number> =>
            n === 0
                ? cy.wrap(0)
                : config('app=rider&platform=ios&version=1.0.0').then((response) => {
                    expect(response.status, `request ${61 - n}`).to.equal(200);

                    return ask(n - 1);
                });

        ask(60).then(() => {
            config('app=rider&platform=ios&version=1.0.0').then((response) => {
                expect(response.status).to.equal(429);
                expect(Number(response.headers['retry-after'])).to.be.greaterThan(0);
            });
        });
    });
});
