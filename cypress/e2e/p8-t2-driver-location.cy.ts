/**
 * P8-T2 · Driver location ingestion (REST fallback): POST /api/v1/driver/location and POST /api/v1/driver/availability.
 * Real server, real Redis (geo set + last-seen set), real MongoDB history, the real realtime stream, driver tokens from the OTP
 * sign-in. Eligibility rules, boundaries of the stale limit and failures of Redis/Mongo are covered by PHPUnit; here the running
 * app is driven the way the driver app drives it.
 */
const CODE = '123456';
const signIn = (phone: string, app: 'driver' | 'rider' = 'driver') =>
    cy.api('POST', '/api/v1/auth/otp/request', { phone, app }).its('status').should('eq', 202).then(() =>
        cy.api('POST', '/api/v1/auth/otp/verify', { phone, code: CODE, app, device_id: `cypress-${app}-device` }).then((response) => {
            expect(response.status, `sign-in of ${phone}`).to.equal(200);
            return response.body.access_token as string;
        }));

interface Driver { driver_id: number; user_id: number; name: string; phone: string }
interface LocationState { position: [number, number] | null; last_seen: number | null; pings: { coordinates: [number, number]; heading: number | null; speed: number | null; accuracy: number | null; source: string; recorded_at: string }[] }

const approvedDriver = (name: string, phone: string | null, extra: Record<string, unknown> = {}) =>
    cy.make<Driver>('driver', { name, phone, verification_status: 'approved', availability: 'offline', vehicles: [{ make: 'Kia', model: 'Rio', plate: `P8${name.slice(0, 3).toUpperCase()}`, primary: true }], ...extra });

const state = (userId: number) => cy.make<LocationState>('driver_location_state', { driver_user_id: userId });
/** The MongoDB history is written just after the response, so look again a few times before deciding it is missing. */
const stateWithPings = (userId: number, count: number, attempts = 25): Cypress.Chainable<LocationState> =>
    state(userId).then((current) => {
        if (current.pings.length >= count || attempts === 0) return cy.wrap(current);
        return stateWithPings(userId, count, attempts - 1);
    });
const availabilityEvents = (userId: number) =>
    cy.make<{ fields: { payload: string } }[]>('stream', { type: 'driver.availability_changed' }).then((entries) =>
        entries.map((entry) => JSON.parse(entry.fields.payload)).filter((payload) => payload.driver_user_id === userId).map((payload) => `${payload.from_availability}>${payload.to_availability}`));
const locationEvents = (userId: number) =>
    cy.make<{ fields: { payload: string } }[]>('stream', { type: 'driver.location_updated' }).then((entries) =>
        entries.map((entry) => JSON.parse(entry.fields.payload)).filter((payload) => payload.driver_user_id === userId));
const profileOf = (driverId: number) => cy.sql('select availability, last_online_at is not null as has_online_time from driver_profiles where id = ?', [driverId]).then((rows) => rows[0]);

const ping = (token: string, body: Record<string, unknown>) => cy.api('POST', '/api/v1/driver/location', body, token);
const setAvailability = (token: string, availability: unknown) => cy.api('POST', '/api/v1/driver/availability', { availability }, token);

describe('P8-T2 · Driver location and availability', () => {
    before(() => cy.resetDb());

    beforeEach(() => cy.make('clear_rate_limits'));

    // Criterion: the online switch is refused while unverified, expired, or without an active primary vehicle.
    it('refuses to go online for an unapproved driver, an expired one and one without a vehicle, and says why', () => {
        approvedDriver('Pending Pat', '+37491520001', { verification_status: 'pending' }).then((pending) => {
            approvedDriver('Expired Eli', '+37491520002', { verification_status: 'expired' }).then((expired) => {
                approvedDriver('Bare Bo', '+37491520003', { vehicles: [] }).then((bare) => {
                    signIn(pending.phone).then((token) => setAvailability(token, 'online').then((r) => { expect(r.status).to.equal(403); expect(r.body.code).to.equal('driver_not_verified'); }));
                    signIn(expired.phone).then((token) => setAvailability(token, 'online').then((r) => { expect(r.status).to.equal(403); expect(r.body.code).to.equal('documents_expired'); }));
                    signIn(bare.phone).then((token) => setAvailability(token, 'online').then((r) => { expect(r.status).to.equal(409); expect(r.body.code).to.equal('no_active_vehicle'); expect(r.body.message).to.equal('Add an approved primary vehicle to go online.'); }));

                    [pending, expired, bare].forEach((driver) => {
                        profileOf(driver.driver_id).then((row) => expect(row.availability, `${driver.name} stays offline`).to.equal('offline'));
                        availabilityEvents(driver.user_id).should('deep.equal', []);
                    });
                });
            });
        });
    });

    // Criterion: POST /api/v1/driver/availability; publishes driver.availability_changed. POST /api/v1/driver/location writes Redis and Mongo.
    it('goes online, reports positions into Redis, MongoDB and the stream, and goes offline again', () => {
        approvedDriver('Flow Fay', '+37491520004').then((driver) => {
            signIn(driver.phone).then((token) => {
                // Offline: no position accepted, nothing stored.
                ping(token, { lat: 40.1792, lng: 44.4991 }).then((r) => { expect(r.status).to.equal(409); expect(r.body).to.deep.equal({ message: 'Go online before sending your location.', code: 'driver_offline' }); });
                state(driver.user_id).then((s) => expect(s).to.deep.equal({ position: null, last_seen: null, pings: [] }));

                setAvailability(token, 'online').then((r) => { expect(r.status).to.equal(200); expect(r.body).to.deep.equal({ availability: 'online' }); });
                profileOf(driver.driver_id).then((row) => expect(row).to.include({ availability: 'online', has_online_time: true }));
                availabilityEvents(driver.user_id).should('deep.equal', ['offline>online']);
                setAvailability(token, 'online').its('status').should('eq', 200);
                availabilityEvents(driver.user_id).should('deep.equal', ['offline>online']); // a second tap changes and publishes nothing

                // Online: the position goes to Redis (with its time), to MongoDB, and to the realtime stream.
                ping(token, { lat: 40.1792, lng: 44.4991, heading: 270.4, speed: 12.5, accuracy: 8 }).then((r) => {
                    expect(r.status).to.equal(200);
                    expect(r.body.recorded_at).to.match(/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/);
                    stateWithPings(driver.user_id, 1).then((s) => {
                        expect(s.position![0]).to.be.closeTo(44.4991, 0.0001);
                        expect(s.position![1]).to.be.closeTo(40.1792, 0.0001);
                        expect(s.last_seen!).to.be.closeTo(Math.floor(Date.now() / 1000), 30);
                        expect(s.pings).to.have.length(1);
                        expect(s.pings[0]).to.deep.include({ heading: 270, speed: 12.5, accuracy: 8, source: 'rest', recorded_at: r.body.recorded_at });
                        expect(s.pings[0].coordinates).to.deep.equal([44.4991, 40.1792]);
                    });
                    locationEvents(driver.user_id).then((events) => {
                        expect(events).to.have.length(1);
                        expect(events[0]).to.include({ latitude: 40.1792, longitude: 44.4991, heading: 270, recorded_at: r.body.recorded_at });
                    });
                });

                // The next ping moves the same entry; history keeps both.
                ping(token, { lat: 40.2, lng: 44.6 }).its('status').should('eq', 200);
                stateWithPings(driver.user_id, 2).then((s) => { expect(s.position![1]).to.be.closeTo(40.2, 0.0001); expect(s.pings).to.have.length(2); });

                // Offline: the position leaves Redis and no more pings are accepted.
                setAvailability(token, 'offline').then((r) => expect(r.body).to.deep.equal({ availability: 'offline' }));
                state(driver.user_id).then((s) => { expect(s.position).to.equal(null); expect(s.last_seen).to.equal(null); expect(s.pings, 'history is kept').to.have.length(2); });
                availabilityEvents(driver.user_id).should('deep.equal', ['offline>online', 'online>offline']);
                ping(token, { lat: 40.2, lng: 44.6 }).its('body.code').should('eq', 'driver_offline');
            });
        });
    });

    // Criterion: only accepted while the driver is online AND verified.
    it('refuses a position from a driver who is online but not approved, or whose papers ran out, and stores nothing', () => {
        approvedDriver('Online Ollie', '+37491520012', { verification_status: 'pending', availability: 'online' }).then((pending) => {
            approvedDriver('Lapsed Lea', '+37491520013', { verification_status: 'expired', availability: 'online' }).then((expired) => {
                [[pending, 'driver_not_verified'], [expired, 'documents_expired']].forEach(([driver, code]) => {
                    const d = driver as Driver;
                    signIn(d.phone).then((token) => ping(token, { lat: 40.1, lng: 44.5 }).then((r) => { expect(r.status, d.name).to.equal(403); expect(r.body.code).to.equal(code); }));
                    state(d.user_id).then((s) => expect(s, `${d.name}: nothing stored`).to.deep.equal({ position: null, last_seen: null, pings: [] }));
                    locationEvents(d.user_id).should('deep.equal', []);
                    profileOf(d.driver_id).then((row) => expect(row.availability).to.equal('online'));
                });
            });
        });
    });

    // Criterion: coordinates outside valid ranges are rejected.
    it('rejects coordinates and numbers out of range and stores nothing', () => {
        approvedDriver('Range Ray', '+37491520005').then((driver) => {
            signIn(driver.phone).then((token) => {
                setAvailability(token, 'online').its('status').should('eq', 200);
                const bad: [Record<string, unknown>, string][] = [
                    [{ lng: 44.5 }, 'lat'], [{ lat: 40.1 }, 'lng'], [{ lat: 91, lng: 44.5 }, 'lat'], [{ lat: 85.2, lng: 44.5 }, 'lat'], [{ lat: -90.1, lng: 44.5 }, 'lat'],
                    [{ lat: 40.1, lng: 180.5 }, 'lng'], [{ lat: 40.1, lng: -181 }, 'lng'], [{ lat: 'north', lng: 44.5 }, 'lat'], [{ lat: 40.1, lng: 44.5, heading: 361 }, 'heading'],
                    [{ lat: 40.1, lng: 44.5, speed: -1 }, 'speed'], [{ lat: 40.1, lng: 44.5, accuracy: -1 }, 'accuracy'],
                ];
                bad.forEach(([body, field]) => ping(token, body).then((r) => { expect(r.status, JSON.stringify(body)).to.equal(422); expect(r.body.errors).to.have.property(field); }));
                state(driver.user_id).then((s) => expect(s).to.deep.equal({ position: null, last_seen: null, pings: [] }));
                locationEvents(driver.user_id).should('deep.equal', []);

                // The edges are fine.
                ping(token, { lat: 85.05112878, lng: -180, heading: 360 }).its('status').should('eq', 200);
                setAvailability(token, 'busy').then((r) => { expect(r.status).to.equal(422); expect(r.body.errors).to.have.property('availability'); });
                setAvailability(token, 'on_trip').its('status').should('eq', 422);
            });
        });
    });

    // Criterion: rate limited per driver.
    it('limits pings to 30 a minute per driver and leaves another driver alone', () => {
        approvedDriver('Spam Sid', '+37491520006').then((spammer) => {
            approvedDriver('Calm Cat', '+37491520008').then((calm) => {
                signIn(spammer.phone).then((token) => {
                    signIn(calm.phone).then((calmToken) => {
                        setAvailability(token, 'online').its('status').should('eq', 200);
                        setAvailability(calmToken, 'online').its('status').should('eq', 200);
                        cy.make('clear_rate_limits');
                        for (let i = 0; i < 30; i++) ping(token, { lat: 40.1, lng: 44.5 }).its('status').should('eq', 200);
                        ping(token, { lat: 40.1, lng: 44.5 }).then((r) => { expect(r.status).to.equal(429); expect(r.headers).to.have.property('retry-after'); });
                        ping(calmToken, { lat: 40.1, lng: 44.5 }).its('status').should('eq', 200);
                        stateWithPings(spammer.user_id, 30).then((s) => expect(s.pings, 'the refused ping is not stored').to.have.length(30));
                    });
                });
            });
        });
    });

    // Criterion: auth required (Sanctum, driver app).
    it('refuses guests, bad tokens and the rider app on both endpoints', () => {
        signIn('+37491520007', 'rider').then((riderToken) => {
            [['/api/v1/driver/location', { lat: 40.1, lng: 44.5 }], ['/api/v1/driver/availability', { availability: 'online' }]].forEach(([path, body]) => {
                cy.api('POST', path as string, body as Record<string, unknown>).its('status').should('eq', 401);
                cy.api('POST', path as string, body as Record<string, unknown>, 'not-a-token').its('status').should('eq', 401);
                cy.api('POST', path as string, body as Record<string, unknown>, riderToken).its('status').should('eq', 403);
            });
        });
    });

    it('refuses the rider app token of someone who is also an approved online driver, and stores nothing', () => {
        approvedDriver('Dual Dee', '+37491520011', { availability: 'online' }).then((dual) => {
            signIn(dual.phone, 'rider').then((riderAppToken) => {
                ping(riderAppToken, { lat: 40.1, lng: 44.5 }).its('status').should('eq', 403);
                setAvailability(riderAppToken, 'offline').its('status').should('eq', 403);
                state(dual.user_id).then((s) => expect(s).to.deep.equal({ position: null, last_seen: null, pings: [] }));
                profileOf(dual.driver_id).then((row) => expect(row.availability, 'unchanged').to.equal('online'));
            });
        });
    });

    // Criterion: a scheduled job marks drivers offline after 5 minutes without a ping.
    it('marks a driver offline after five quiet minutes and removes the position, but never touches a driver on a trip or a recent ping', () => {
        approvedDriver('Quiet Quin', '+37491520009').then((quiet) => {
            approvedDriver('Fresh Fran', '+37491520010').then((fresh) => {
                approvedDriver('Trip Tom', null, { availability: 'on_trip' }).then((onTrip) => {
                    signIn(quiet.phone).then((quietToken) => signIn(fresh.phone).then((freshToken) => {
                        [quietToken, freshToken].forEach((token) => {
                            setAvailability(token, 'online').its('status').should('eq', 200);
                            ping(token, { lat: 40.1, lng: 44.5 }).its('status').should('eq', 200);
                        });
                        availabilityEvents(onTrip.user_id).as('tripEventsBefore'); // the fixture itself put this driver on a trip
                        cy.make('age_driver_ping', { driver_user_id: quiet.user_id, seconds: 301 });
                        cy.make('age_driver_ping', { driver_user_id: onTrip.user_id, seconds: 3600 });

                        cy.make<{ marked_offline: number; pruned: number }>('sweep_stale_drivers').then((result) => expect(result.marked_offline).to.equal(1));

                        // The quiet driver is offline, announced, and gone from Redis.
                        profileOf(quiet.driver_id).then((row) => expect(row.availability).to.equal('offline'));
                        availabilityEvents(quiet.user_id).should('deep.equal', ['offline>online', 'online>offline']);
                        state(quiet.user_id).then((s) => { expect(s.position).to.equal(null); expect(s.last_seen).to.equal(null); });
                        // A recent ping and a trip are left alone.
                        profileOf(fresh.driver_id).then((row) => expect(row.availability).to.equal('online'));
                        state(fresh.user_id).then((s) => expect(s.position).not.to.equal(null));
                        profileOf(onTrip.driver_id).then((row) => expect(row.availability).to.equal('on_trip'));
                        state(onTrip.user_id).then((s) => expect(s.last_seen, 'a driver on a trip keeps their entry').not.to.equal(null));
                        cy.get<string[]>('@tripEventsBefore').then((before) => availabilityEvents(onTrip.user_id).should('deep.equal', before));
                        // A second sweep has nothing left to do.
                        cy.make<{ marked_offline: number }>('sweep_stale_drivers').then((result) => expect(result.marked_offline).to.equal(0));
                    }));
                });
            });
        });
    });
});

export {};
