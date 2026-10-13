/**
 * P5-T1 · GeoProvider: the maps endpoints of the rider and driver apps, GET /api/v1/geo/*.
 * The e2e environment runs the keyless fake provider (MAPS_PROVIDER=fake); the Google and 2GIS requests, mappings and
 * caching are covered by PHPUnit with the HTTP client faked. Here: the real server, real tokens, the contract the apps rely on.
 */
const CODE = '123456';
const otpSignIn = (phone: string, app = 'rider') =>
    cy.api('POST', '/api/v1/auth/otp/request', { phone, app }).its('status').should('eq', 202).then(() =>
        cy.api('POST', '/api/v1/auth/otp/verify', { phone, code: CODE, app, device_id: `cypress-${app}-device` }).then((response) => {
            expect(response.status, `sign-in of ${phone}`).to.equal(200);
            return response.body as { access_token: string };
        }));

const geo = (path: string, token?: string) => cy.api('GET', `/api/v1/geo/${path}`, undefined, token);

describe('P5-T1 · Geo API', () => {
    let rider: string;
    let driver: string;

    before(() => {
        cy.resetDb();
        otpSignIn('+37491510001').then((s) => (rider = s.access_token));
        otpSignIn('+37491510002', 'driver').then((s) => (driver = s.access_token));
    });

    beforeEach(() => cy.make('clear_rate_limits'));

    // Criterion: autocomplete(query, near, session) returns normalised suggestions.
    it('suggests places for what the rider types, in the neutral shape', () => {
        geo('autocomplete?q=Abovyan&lat=40.18&lng=44.51&session=cy-session_1', rider).then((response) => {
            expect(response.status).to.equal(200);
            expect(response.body.suggestions).to.have.length(3);
            expect(response.body.suggestions[0]).to.have.all.keys('place_id', 'text', 'primary_text', 'secondary_text');
            expect(response.body.suggestions[0]).to.include({ place_id: 'fake-abovyan-1', primary_text: 'Abovyan street 1' });
        });
        geo('autocomplete?q=nowhere', rider).then((response) => {
            expect(response.status).to.equal(200);
            expect(response.body).to.deep.equal({ suggestions: [] });
        });
    });

    // Criterion: placeDetails turns a suggestion into coordinates.
    it('resolves a suggestion to a place with coordinates, and says 404 for an unknown one', () => {
        geo('places/fake-abovyan-1', rider).then((response) => {
            expect(response.status).to.equal(200);
            expect(response.body.place).to.include({ place_id: 'fake-abovyan-1' });
            expect(response.body.place.location).to.have.all.keys('lat', 'lng');
            expect(response.body.place.location.lat).to.be.within(40.1, 40.3);
        });
        geo('places/fake-nowhere-1', rider).then((response) => {
            expect(response.status).to.equal(404);
            expect(response.body).to.deep.equal({ message: 'We could not find that place.', code: 'place_not_found' });
        });
    });

    // Criterion: reverseGeocode names the pickup pin.
    it('names the pickup pin, and 404s where there is nothing', () => {
        geo('reverse?lat=40.18&lng=44.51', rider).then((response) => {
            expect(response.status).to.equal(200);
            expect(response.body.place.location).to.deep.equal({ lat: 40.18, lng: 44.51 });
            expect(response.body.place.address).to.contain('40.18');
        });
        geo('reverse?lat=0&lng=0', rider).then((response) => {
            expect(response.status).to.equal(404);
            expect(response.body.code).to.equal('place_not_found');
        });
    });

    // Criterion: getRoute / estimateEta: distance, duration, polyline.
    it('gives a route preview with distance, duration and a polyline, and 404s without a route', () => {
        geo('route?from=40.18,44.51&to=40.19,44.51', rider).then((response) => {
            expect(response.status).to.equal(200);
            expect(response.body.route).to.include({ distance_meters: 1446, duration_seconds: 174 });
            expect(response.body.route.polyline).to.be.a('string').and.not.be.empty;
        });
        geo('route?from=40.18,44.51&to=0,0', rider).then((response) => {
            expect(response.status).to.equal(404);
            expect(response.body).to.deep.equal({ message: 'We could not find a route between these points.', code: 'route_not_found' });
        });
    });

    // Who may: rider and driver apps, nobody else.
    it('serves drivers too, and refuses guests and junk tokens', () => {
        geo('autocomplete?q=Abovyan', driver).its('status').should('eq', 200);
        geo('autocomplete?q=Abovyan').its('status').should('eq', 401);
        geo('autocomplete?q=Abovyan', 'not-a-token').its('status').should('eq', 401);
        geo('reverse?lat=40.18&lng=44.51').its('status').should('eq', 401);
        geo('route?from=40.18,44.51&to=40.19,44.51').its('status').should('eq', 401);
        geo('places/fake-abovyan-1').its('status').should('eq', 401);
    });

    // Who may: a social account that has not added its phone yet holds a limited session, which cannot use the maps.
    it('refuses a session that still has to add its phone number', () => {
        cy.make('pending_mobile_session', { name: 'Geo Pending', email: 'cy-geo-pending@taxikosmos.test' }).then((pending) => {
            expect(pending.needs_phone).to.equal(true);
            for (const path of ['autocomplete?q=Abovyan', 'places/fake-abovyan-1', 'reverse?lat=40.18&lng=44.51', 'route?from=40.18,44.51&to=40.19,44.51']) {
                geo(path, pending.access_token).its('status').should('eq', 403);
            }
        });
    });

    // Validation: nothing malformed reaches the provider (it is billed per call).
    it('rejects malformed input with 422 and the field named', () => {
        geo('autocomplete', rider).then((r) => {
            expect(r.status).to.equal(422);
            expect(r.body.errors).to.have.property('q');
        });
        geo('autocomplete?q=a', rider).its('body.errors').should('have.property', 'q');
        geo(`autocomplete?q=${'a'.repeat(121)}`, rider).its('body.errors').should('have.property', 'q');
        geo('autocomplete?q=abc&lat=40.1', rider).its('body.errors').should('have.property', 'lng');
        geo('autocomplete?q=abc&lat=91&lng=44', rider).its('body.errors').should('have.property', 'lat');
        geo('autocomplete?q=abc&lat=40&lng=181', rider).its('body.errors').should('have.property', 'lng');
        geo('autocomplete?q=abc&session=a/b', rider).its('body.errors').should('have.property', 'session');
        geo('reverse?lat=40.1', rider).its('body.errors').should('have.property', 'lng');
        geo('reverse?lat=91&lng=44', rider).its('body.errors').should('have.property', 'lat');
        geo('reverse?lat=10&lng=181', rider).its('body.errors').should('have.property', 'lng');
        geo('route?from=here&to=40.19,44.51', rider).its('body.errors').should('have.property', 'from');
        geo('route?from=40.18,44.51', rider).its('body.errors').should('have.property', 'to');
        geo('autocomplete?q=ab', rider).its('status').should('eq', 200);
    });

    it('cannot be walked out of the place path', () => {
        geo('places/..', rider).its('status').should('eq', 404);
        geo('places/.hidden', rider).its('status').should('eq', 404);
    });

    // Rate limit: 60 a minute per user, counted across all four endpoints.
    it('limits a rider to 60 calls a minute and leaves the driver alone', () => {
        for (let i = 0; i < 60; i++) {
            geo('reverse?lat=40.18&lng=44.51', rider).its('status').should('eq', 200);
        }
        geo('reverse?lat=40.18&lng=44.51', rider).then((limited) => {
            expect(limited.status).to.equal(429);
            expect(limited.headers).to.have.property('retry-after');
        });
        geo('autocomplete?q=Abovyan', rider).its('status').should('eq', 429);
        geo('autocomplete?q=Abovyan', driver).its('status').should('eq', 200);
    });
});

export {};
