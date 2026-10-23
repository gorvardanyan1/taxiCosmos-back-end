/**
 * P7-T6 · Mobile trip API: POST /api/v1/trips, the rider's trip reads and cancel, the driver's arrived / start / complete / cancel, the
 * driver's trips and earnings. Real server, real OTP sign-ins, real PostgreSQL, Redis and MongoDB, the real realtime stream; dispatch (P7-T7)
 * does not exist yet, so the moment a driver accepts is the same TripService::assignDriver call it will make (the `assign_driver` fixture).
 * Cash is the only payment method (MVP). The pricing arithmetic and every edge are covered by PHPUnit; here the running API is driven
 * the way the two apps drive it.
 */
// A module of its own, so its helpers (signIn, key…) do not clash with those of the other specs.
export {};

const CODE = '123456';
const signIn = (phone: string, app: 'driver' | 'rider' = 'rider') =>
    cy.api('POST', '/api/v1/auth/otp/request', { phone, app }).its('status').should('eq', 202).then(() =>
        cy.api('POST', '/api/v1/auth/otp/verify', { phone, code: CODE, app, device_id: `cypress-${app}-device` }).then((response) => {
            expect(response.status, `sign-in of ${phone}`).to.equal(200);
            return response.body.access_token as string;
        }));

const PICKUP = { lat: 40.18, lng: 44.51, address: 'Republic Square, Yerevan' };
const DROPOFF = { lat: 40.19, lng: 44.51, address: 'Cascade, Yerevan' };
let keys = 0;
const key = () => `cy-p7t6-${Date.now()}-${++keys}`;

interface Driver { driver_id: number; user_id: number; name: string; phone: string }

const quote = (token: string) =>
    cy.api('POST', '/api/v1/trips/fare-estimate', { pickup: { lat: PICKUP.lat, lng: PICKUP.lng }, dropoff: { lat: DROPOFF.lat, lng: DROPOFF.lng } }, token).then((r) => {
        expect(r.status).to.equal(201);
        return r.body.quote_id as string;
    });
const booking = (quoteId: string, extra: Record<string, unknown> = {}) => ({ quote_id: quoteId, vehicle_class: 'economy', payment_method: 'cash', pickup: PICKUP, dropoff: DROPOFF, ...extra });
const post = (path: string, token: string, body: Record<string, unknown> = {}, idempotencyKey: string | null = key()) =>
    cy.request({ method: 'POST', url: path, body, failOnStatusCode: false, headers: { Authorization: `Bearer ${token}`, Accept: 'application/json', ...(idempotencyKey === null ? {} : { 'Idempotency-Key': idempotencyKey }) } });
const get = (path: string, token: string) => cy.api('GET', path, undefined, token);

const approvedDriver = (name: string, phone: string) =>
    cy.make<Driver>('driver', { name, phone, verification_status: 'approved', availability: 'online', zone_code: 'P7T6', vehicles: [{ make: 'Toyota', model: 'Camry', plate: `76 ${name.slice(0, 2).toUpperCase()} 760`, primary: true }] });
const tripRow = (id: number) => cy.sql('select status, payment_status, driver_id, vehicle_id, fare_amount, estimated_fare_amount, commission_amount, driver_earnings_amount, distance_meters, duration_seconds, cancelled_by, rider_id from trips where id = ?', [id]).then((rows) => rows[0]);
const riderId = (token: string) => cy.api('GET', '/api/v1/me', undefined, token).then((r) => r.body.user.id as number);
const tripsOf = (riderId: number) => cy.sql('select id, status from trips where rider_id = ? order by id', [riderId]);
const statusEvents = (tripId: number) =>
    cy.make<{ fields: { payload: string } }[]>('stream', { type: 'trip.status_changed' }).then((entries) =>
        entries.map((entry) => JSON.parse(entry.fields.payload)).filter((payload) => payload.trip_id === tripId).map((payload) => `${payload.from_status}>${payload.to_status}`));
const availability = (driverId: number) => cy.sql('select availability from driver_profiles where id = ?', [driverId]).then((rows) => rows[0].availability as string);

/** A rider who is signed in and named, with a trip requested and a driver who accepted it. */
const matchedTrip = (riderPhone: string, driver: Driver) =>
    signIn(riderPhone).then((riderToken) =>
        quote(riderToken).then((quoteId) =>
            post('/api/v1/trips', riderToken, booking(quoteId)).then((created) => {
                expect(created.status).to.equal(201);
                const id = created.body.data.id as number;
                return cy.make('assign_driver', { trip_id: id, driver_id: driver.driver_id }).then(() =>
                    signIn(driver.phone, 'driver').then((driverToken) => ({ id, riderToken, driverToken })));
            })));

describe('P7-T6 · Mobile trip API', () => {
    // One driver for each test that leaves a trip running, so no test depends on how another ended.
    let driverA: Driver; // the whole trip
    let driverB: Driver; // the 409s
    let driverC: Driver; // the repeated step
    let driverD: Driver; // ownership: the trip's driver
    let driverE: Driver; // ownership: another driver
    let driverF: Driver; // gives a trip back
    let driverG: Driver; // the rider cancels
    let driverH: Driver; // the ETA

    before(() => {
        cy.resetDb();
        cy.make('zone', { name: 'P7T6 Yerevan', code: 'P7T6', lat: 40.18, lng: 44.51 });
        cy.make('fare_rule', { zone_code: 'P7T6' });
        cy.make('commission_rule', { zone_code: 'P7T6' });
        approvedDriver('Karen Petrosyan', '+37491760011').then((d) => (driverA = d));
        approvedDriver('Levon Avetisyan', '+37491760012').then((d) => (driverB = d));
        approvedDriver('Mher Sargsyan', '+37491760015').then((d) => (driverC = d));
        approvedDriver('Narek Hakobyan', '+37491760016').then((d) => (driverD = d));
        approvedDriver('Ofelia Grigoryan', '+37491760017').then((d) => (driverE = d));
        approvedDriver('Pargev Manukyan', '+37491760018').then((d) => (driverF = d));
        approvedDriver('Raffi Vardanyan', '+37491760019').then((d) => (driverG = d));
        approvedDriver('Sevak Harutyunyan', '+37491760020').then((d) => (driverH = d));
    });

    beforeEach(() => cy.make('clear_rate_limits'));

    // Criterion: double-submitting POST /api/v1/trips with the same Idempotency-Key creates one trip.
    it('creates one trip when the same booking is sent twice with the same key, and gives the first answer back', () => {
        signIn('+37491760001').then((token) => {
            quote(token).then((quoteId) => {
                const idempotencyKey = key();
                post('/api/v1/trips', token, booking(quoteId), idempotencyKey).then((first) => {
                    expect(first.status).to.equal(201);
                    post('/api/v1/trips', token, booking(quoteId), idempotencyKey).then((second) => {
                        expect(second.status).to.equal(201);
                        expect(second.headers['idempotent-replayed']).to.equal('true');
                        expect(second.body).to.deep.equal(first.body);
                        const id = first.body.data.id as number;
                        tripRow(id).then((row) => expect(row).to.include({ status: 'requested', payment_status: 'unpaid', estimated_fare_amount: 800 }));
                        riderId(token).then((rider) => tripsOf(rider).then((rows) => expect(rows, 'one trip, not two').to.have.length(1)));
                        statusEvents(id).should('deep.equal', ['null>requested']);
                    });
                });
            });
        });
    });

    it('refuses a second booking with a new key while a trip is active, a reused key with another body, and a missing key', () => {
        signIn('+37491760002').then((token) => {
            quote(token).then((quoteId) => {
                const idempotencyKey = key();
                post('/api/v1/trips', token, booking(quoteId), idempotencyKey).then((first) => {
                    const id = first.body.data.id as number;
                    quote(token).then((another) => {
                        post('/api/v1/trips', token, booking(another)).then((r) => {
                            expect(r.status).to.equal(409);
                            expect(r.body.code).to.equal('active_trip_exists');
                            expect(r.body.trip.id).to.equal(id);
                        });
                        post('/api/v1/trips', token, booking(another, { vehicle_class: 'comfort' }), idempotencyKey).then((r) => {
                            expect(r.status).to.equal(422);
                            expect(r.body.code).to.equal('idempotency_key_reused');
                        });
                        post('/api/v1/trips', token, booking(another), null).then((r) => {
                            expect(r.status).to.equal(422);
                            expect(r.body.code).to.equal('idempotency_key_required');
                        });
                    });
                    riderId(token).then((rider) => tripsOf(rider).then((rows) => expect(rows.map((row: { id: number }) => row.id), 'still the one trip').to.deep.equal([id])));
                });
            });
        });
    });

    // Criterion: a full happy path (estimate > request > offer accepted > arrived > start > complete > paid), for cash (card is not offered in the MVP).
    it('runs a whole cash trip from the estimate to paid, with the receipt, the history, the earnings, the events and the push to the rider', () => {
        const pushToken = 'ExponentPushToken[cy-p7t6-rider-0123456789]';
        signIn('+37491760003').then((riderToken) => {
            cy.api('POST', '/api/v1/devices', { push_token: pushToken, platform: 'android' }, riderToken).its('status').should('eq', 201);
            quote(riderToken).then((quoteId) => {
                post('/api/v1/trips', riderToken, booking(quoteId)).then((created) => {
                    expect(created.status).to.equal(201);
                    const id = created.body.data.id as number;
                    expect(created.body.data.fare).to.deep.equal({ currency: 'AMD', estimated: 800, final: null });
                    get('/api/v1/trips/current', riderToken).its('body.data.id').should('eq', id);

                    // A driver accepts (dispatch).
                    cy.make('assign_driver', { trip_id: id, driver_id: driverA.driver_id });
                    availability(driverA.driver_id).should('eq', 'on_trip');
                    get(`/api/v1/trips/${id}`, riderToken).then((r) => {
                        expect(r.body.data.status).to.equal('matched');
                        expect(r.body.data.driver.name).to.equal('Karen Petrosyan');
                        expect(r.body.data.vehicle).to.include({ make: 'Toyota', model: 'Camry' });
                        expect(JSON.stringify(r.body)).not.to.contain(driverA.phone);
                    });

                    signIn(driverA.phone, 'driver').then((driverToken) => {
                        get('/api/v1/driver/trips/current', driverToken).its('body.data.id').should('eq', id);
                        post(`/api/v1/driver/trips/${id}/arrived`, driverToken).its('body.data.status').should('eq', 'arrived');
                        post(`/api/v1/driver/trips/${id}/start`, driverToken).its('body.data.status').should('eq', 'in_progress');
                        // The ride took ten minutes and the driver's pings run along it: 0.009 degrees of latitude (about 1000.8 m) a minute apart.
                        cy.make('age_trip', { trip_id: id, seconds: 600 });
                        cy.make('add_pings', { driver_user_id: driverA.user_id, driver_id: driverA.driver_id, points: [[40.18, 44.51, 540], [40.189, 44.51, 480], [40.198, 44.51, 420]] });
                        post(`/api/v1/driver/trips/${id}/complete`, driverToken).then((done) => {
                            expect(done.status).to.equal(200);
                            const trip = done.body.data;
                            expect(trip.status).to.equal('completed');
                            expect(trip.payment).to.deep.equal({ method: 'cash', status: 'paid' });
                            expect(trip.earnings.amount + trip.earnings.commission, 'the split adds up to the fare').to.equal(trip.fare.final);
                            expect(trip.distance_meters, 'driven distance from the pings, not the quoted route (1446 m)').to.be.within(1990, 2010);
                            expect(trip.duration_seconds).to.be.within(600, 605);
                            tripRow(id).then((row) => expect(row).to.include({ status: 'completed', payment_status: 'paid', fare_amount: trip.fare.final, driver_earnings_amount: trip.earnings.amount, commission_amount: trip.earnings.commission }));

                            get(`/api/v1/trips/${id}/receipt`, riderToken).then((r) => {
                                expect(r.body.data.total).to.equal(trip.fare.final);
                                const lines = r.body.data.breakdown;
                                expect(lines.base + lines.distance + lines.time + lines.waiting + lines.minimum_adjustment + lines.booking_fee + lines.surge + lines.tax).to.equal(r.body.data.total);
                            });
                            get('/api/v1/trips/current', riderToken).its('status').should('eq', 204);
                            get('/api/v1/trips', riderToken).its('body.data.0.id').should('eq', id);
                            get('/api/v1/driver/trips', driverToken).its('body.data.0.earnings.amount').should('eq', trip.earnings.amount);
                            get('/api/v1/driver/earnings', driverToken).then((r) => {
                                expect(r.body.data.currency).to.equal('AMD');
                                expect(r.body.data.today.trips).to.equal(1);
                                expect(r.body.data.today.earnings).to.equal(trip.earnings.amount);
                            });
                        });
                    });
                    availability(driverA.driver_id).should('eq', 'online');
                    statusEvents(id).should('deep.equal', ['null>requested', 'requested>matched', 'matched>arrived', 'arrived>in_progress', 'in_progress>completed']);
                    cy.make<{ data: { type: string } }[]>('sent_pushes', { push_token: pushToken }).then((pushes) => expect(pushes.map((p) => p.data.type)).to.deep.equal(['trip_matched', 'trip_arrived', 'trip_completed']));
                });
            });
        });
    });

    it('refuses card and wallet for now, with the message next to the field, and creates no trip', () => {
        signIn('+37491760004').then((token) => {
            quote(token).then((quoteId) => {
                ['card', 'wallet'].forEach((method) => post('/api/v1/trips', token, booking(quoteId, { payment_method: method })).then((r) => {
                    expect(r.status).to.equal(422);
                    expect(r.body.errors.payment_method[0]).to.equal('Only cash is available for now.');
                }));
            });
            get('/api/v1/trips/current', token).its('status').should('eq', 204);
        });
    });

    it('refuses an expired quote, another route than the quote, and an unknown quote', () => {
        signIn('+37491760005').then((token) => {
            quote(token).then((quoteId) => {
                post('/api/v1/trips', token, booking(quoteId, { pickup: { lat: 40.185, lng: 44.51, address: 'Elsewhere' } })).then((r) => { expect(r.status).to.equal(422); expect(r.body.code).to.equal('quote_route_changed'); });
                post('/api/v1/trips', token, booking('00000000-0000-4000-8000-000000000000')).then((r) => { expect(r.status).to.equal(404); expect(r.body.code).to.equal('quote_not_found'); });
                cy.make('expire_fare_quote', { quote_id: quoteId });
                post('/api/v1/trips', token, booking(quoteId)).then((r) => { expect(r.status).to.equal(410); expect(r.body.code).to.equal('quote_expired'); });
            });
            get('/api/v1/trips/current', token).its('status').should('eq', 204);
        });
    });

    // Criterion: status calls that are not allowed for the current status return 409 with the current trip resource.
    it('answers a step the trip cannot take with 409 and the trip as it is, and changes nothing', () => {
        matchedTrip('+37491760006', driverB).then(({ id, riderToken, driverToken }) => {
            post(`/api/v1/driver/trips/${id}/start`, driverToken).then((r) => {
                expect(r.status).to.equal(409);
                expect(r.body.code).to.equal('trip_state_conflict');
                expect(r.body.trip).to.include({ id, status: 'matched' });
            });
            post(`/api/v1/driver/trips/${id}/complete`, driverToken).its('status').should('eq', 409);
            post(`/api/v1/driver/trips/${id}/arrived`, driverToken).its('status').should('eq', 200);
            post(`/api/v1/driver/trips/${id}/arrived`, driverToken).then((r) => { expect(r.status).to.equal(409); expect(r.body.trip.status).to.equal('arrived'); });
            post(`/api/v1/driver/trips/${id}/start`, driverToken).its('status').should('eq', 200);
            post(`/api/v1/trips/${id}/cancel`, riderToken, { reason: 'Too slow' }).then((r) => {
                expect(r.status).to.equal(409);
                expect(r.body.trip).to.include({ id, status: 'in_progress' });
            });
            post(`/api/v1/driver/trips/${id}/cancel`, driverToken, { reason: 'Changed my mind' }).its('status').should('eq', 409);
            tripRow(id).then((row) => expect(row).to.include({ status: 'in_progress', cancelled_by: null }));
            post(`/api/v1/driver/trips/${id}/complete`, driverToken).its('status').should('eq', 200);
            post(`/api/v1/driver/trips/${id}/complete`, driverToken).then((r) => { expect(r.status).to.equal(409); expect(r.body.trip.status).to.equal('completed'); });
            statusEvents(id).should('deep.equal', ['null>requested', 'requested>matched', 'matched>arrived', 'arrived>in_progress', 'in_progress>completed']);
        });
    });

    it('answers a repeated step with the same key with the first answer', () => {
        matchedTrip('+37491760007', driverC).then(({ id, driverToken }) => {
            const idempotencyKey = key();
            post(`/api/v1/driver/trips/${id}/arrived`, driverToken, {}, idempotencyKey).then((first) => {
                expect(first.status).to.equal(200);
                post(`/api/v1/driver/trips/${id}/arrived`, driverToken, {}, idempotencyKey).then((second) => {
                    expect(second.status).to.equal(200);
                    expect(second.headers['idempotent-replayed']).to.equal('true');
                    expect(second.body).to.deep.equal(first.body);
                });
            });
            statusEvents(id).should('deep.equal', ['null>requested', 'requested>matched', 'matched>arrived']);
            post(`/api/v1/driver/trips/${id}/start`, driverToken, {}, null).then((r) => { expect(r.status).to.equal(422); expect(r.body.code).to.equal('idempotency_key_required'); });
        });
    });

    // Criterion: a rider cannot see another rider's trip; a driver can only act on trips assigned to them.
    it('keeps every trip to its own rider and its own driver', () => {
        matchedTrip('+37491760008', driverD).then(({ id, riderToken, driverToken }) => {
            signIn('+37491760009').then((otherRider) => {
                [`/api/v1/trips/${id}`, `/api/v1/trips/${id}/receipt`].forEach((path) => get(path, otherRider).its('status').should('eq', 404));
                post(`/api/v1/trips/${id}/cancel`, otherRider).its('status').should('eq', 404);
                get('/api/v1/trips/current', otherRider).its('status').should('eq', 204);
                get('/api/v1/trips', otherRider).its('body.data').should('have.length', 0);
                get('/api/v1/driver/trips/current', otherRider).its('status').should('eq', 403);
            });
            signIn(driverE.phone, 'driver').then((other) => {
                ['arrived', 'start', 'complete', 'cancel'].forEach((step) => post(`/api/v1/driver/trips/${id}/${step}`, other, { reason: 'Not mine' }).its('status').should('eq', 404));
                get('/api/v1/driver/trips/current', other).its('status').should('eq', 204);
                get('/api/v1/driver/trips', other).its('body.data').should('have.length', 0);
                get('/api/v1/trips/current', other).its('status').should('eq', 403);
            });
            cy.request({ method: 'POST', url: `/api/v1/driver/trips/${id}/arrived`, failOnStatusCode: false, headers: { 'Idempotency-Key': key() } }).its('status').should('eq', 401);
            cy.request({ method: 'GET', url: `/api/v1/trips/${id}`, failOnStatusCode: false }).its('status').should('eq', 401);
            tripRow(id).then((row) => expect(row).to.include({ status: 'matched' }));
            get(`/api/v1/trips/${id}`, riderToken).its('status').should('eq', 200);
            post(`/api/v1/driver/trips/${id}/arrived`, driverToken).its('status').should('eq', 200);
        });
    });

    it('lets a driver give a trip back before the ride: it returns to dispatch with no driver, the reason is kept and the driver is free', () => {
        matchedTrip('+37491760010', driverF).then(({ id, riderToken, driverToken }) => {
            post(`/api/v1/driver/trips/${id}/cancel`, driverToken).then((r) => { expect(r.status).to.equal(422); expect(r.body.errors.reason).to.exist; });
            post(`/api/v1/driver/trips/${id}/cancel`, driverToken, { reason: 'Flat tyre' }).then((r) => {
                expect(r.status).to.equal(200);
                expect(r.body.data.status).to.equal('requested');
            });
            tripRow(id).then((row) => expect(row).to.include({ status: 'requested', driver_id: null, vehicle_id: null }));
            cy.sql('select reason, trip_status from trip_driver_cancellations where trip_id = ?', [id]).then((rows) => expect(rows).to.deep.equal([{ reason: 'Flat tyre', trip_status: 'matched' }]));
            availability(driverF.driver_id).should('eq', 'online');
            get(`/api/v1/trips/${id}`, riderToken).then((r) => { expect(r.body.data.status).to.equal('requested'); expect(r.body.data.driver).to.equal(null); });
            statusEvents(id).should('deep.equal', ['null>requested', 'requested>matched', 'matched>requested']);
        });
    });

    it('lets a rider cancel, frees the driver, and then lets them book again', () => {
        matchedTrip('+37491760013', driverG).then(({ id, riderToken }) => {
            const idempotencyKey = key();
            post(`/api/v1/trips/${id}/cancel`, riderToken, { reason: 'Plans changed' }, idempotencyKey).then((r) => {
                expect(r.status).to.equal(200);
                expect(r.body.data).to.include({ status: 'cancelled', cancelled_by: 'rider', cancel_reason: 'Plans changed' });
            });
            post(`/api/v1/trips/${id}/cancel`, riderToken, { reason: 'Plans changed' }, idempotencyKey).then((r) => { expect(r.status).to.equal(200); expect(r.headers['idempotent-replayed']).to.equal('true'); });
            availability(driverG.driver_id).should('eq', 'online');
            tripRow(id).then((row) => expect(row).to.include({ status: 'cancelled', cancelled_by: 'rider' }));
            get('/api/v1/trips', riderToken).its('body.data.0').should('include', { id, status: 'cancelled' });
            quote(riderToken).then((quoteId) => post('/api/v1/trips', riderToken, booking(quoteId)).its('status').should('eq', 201));
        });
    });

    it('shows the rider how far the driver is while they are on the way', () => {
        matchedTrip('+37491760014', driverH).then(({ id, riderToken, driverToken }) => {
            get(`/api/v1/trips/${id}`, riderToken).its('body.data.eta_to_pickup_seconds').should('eq', null);
            // 0.01 degrees of latitude from the pickup: 1446 m by the route estimate, 174 s at 30 km/h.
            cy.api('POST', '/api/v1/driver/location', { lat: 40.17, lng: 44.51 }, driverToken).its('status').should('eq', 200);
            get(`/api/v1/trips/${id}`, riderToken).its('body.data.eta_to_pickup_seconds').should('eq', 174);
        });
    });

    // Criterion: trip resources are documented with JSON examples for the mobile team.
    it('documents every endpoint with JSON examples in docs/trips-api.md', () => {
        cy.readFile('docs/trips-api.md').then((doc: string) => {
            ['POST /trips`', 'GET /trips/current`', 'GET /trips/{id}`', 'GET /trips`', 'POST /trips/{id}/cancel`', 'GET /trips/{id}/receipt`', 'GET /driver/trips/current`', 'GET /driver/trips`', 'POST /driver/trips/{id}/arrived`, `/start`', 'POST /driver/trips/{id}/complete`', 'POST /driver/trips/{id}/cancel`', 'GET /driver/earnings`']
                .forEach((heading) => expect(doc, heading).to.contain(heading));
            expect((doc.match(/```json/g) ?? []).length).to.be.at.least(4);
        });
    });
});
