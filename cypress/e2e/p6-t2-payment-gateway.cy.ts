/**
 * P6-T2 · PaymentGatewayInterface, mobile side: the rider's saved cards (GET/POST/DELETE /api/v1/payment-methods, POST …/{id}/default) and the
 * outstanding balance (GET /api/v1/account/outstanding, POST …/pay with an Idempotency-Key). Real HTTP against the running app with real OTP
 * sign-ins; the card gateway is the no-network fake (PAYMENT_DRIVER=fake) with its test tokens (docs/payments.md). Failed trips are made through
 * the real card flow (hold, ride, declined capture) by the failed_card_trip fixture.
 */
export {};

const CODE = '123456';
const RIDER = '+37491770052';
const OTHER_RIDER = '+37491770053';
const DRIVER = '+37491770054';

interface Card { id: number; brand: string; last4: string; exp_month: number; exp_year: number; is_default: boolean; is_expired: boolean }

const signIn = (phone: string, app: 'rider' | 'driver' = 'rider') =>
    cy.request({ method: 'POST', url: '/api/v1/auth/otp/request', body: { phone, app }, failOnStatusCode: false }).its('status').should('eq', 202).then(() =>
        cy.request({ method: 'POST', url: '/api/v1/auth/otp/verify', body: { phone, code: CODE, app, device_id: `cypress-p6t2-${app}` }, failOnStatusCode: false }).then((response) => {
            expect(response.status, `sign-in of ${phone}`).to.equal(200);
            return response.body.access_token as string;
        }));
const call = (method: string, path: string, token: string | null, body?: Record<string, unknown>, headers: Record<string, string> = {}) =>
    cy.request({ method, url: path, body, failOnStatusCode: false, headers: { Accept: 'application/json', ...(token ? { Authorization: `Bearer ${token}` } : {}), ...headers } });
const pay = (token: string, key: string | null, body: Record<string, unknown> = {}) => call('POST', '/api/v1/account/outstanding/pay', token, body, key ? { 'Idempotency-Key': key } : {});
const sql = (query: string, bindings: unknown[] = []) => cy.sql(query, bindings);
const cardsOf = (userId: number) => sql('select id, is_default, deleted_at is not null as removed, gateway_payment_method_id from payment_methods where user_id = ? order by id', [userId]);
const chargesOf = (userId: number) => sql("select id, type, status, amount, trip_id, payment_method_id, idempotency_key from transactions where user_id = ? and type = 'charge' order by id", [userId]);

/** A rider who has just signed in: their id and token. */
const newRider = (phone: string, name: string) => cy.make<{ id: number }>('rider', { name, phone }).then((person) => signIn(phone).then((token) => ({ id: person.id, token })));

describe('P6-T2 · Payment methods and outstanding balance API', () => {
    before(() => {
        cy.resetDb();
    });

    beforeEach(() => {
        cy.make('clear_rate_limits');
    });

    // AC: GET/POST/DELETE /api/v1/payment-methods and POST /api/v1/payment-methods/{id}/default
    it('saves a card from a gateway token, makes the first one default and never returns gateway ids', () => {
        newRider(RIDER, 'Ani Card').then(({ id, token }) => {
            call('POST', '/api/v1/payment-methods', token, { token: 'tok_visa' }).then((r) => {
                expect(r.status).to.equal(201);
                expect(r.body.data).to.deep.equal({ id: r.body.data.id, brand: 'visa', last4: '4242', exp_month: 12, exp_year: new Date().getFullYear() + 3, is_default: true, is_expired: false });
                expect(JSON.stringify(r.body)).not.to.match(/fake_pm_|fake_cus_|gateway/);
            });
            call('POST', '/api/v1/payment-methods', token, { token: 'tok_mastercard' }).then((r) => {
                expect(r.status).to.equal(201);
                expect(r.body.data).to.include({ brand: 'mastercard', last4: '4444', is_default: false });
            });

            cardsOf(id).then((rows) => {
                expect(rows).to.have.length(2);
                expect(rows.map((row) => row.is_default)).to.deep.equal([true, false]);
                // Only the gateway's token is stored, never a card number.
                rows.forEach((row) => expect(row.gateway_payment_method_id).to.match(/^fake_pm_ok_[a-z0-9]{16}$/));
            });
            call('GET', '/api/v1/payment-methods', token).then((r) => {
                expect(r.status).to.equal(200);
                expect(r.body.data.map((card: Card) => [card.brand, card.is_default])).to.deep.equal([['visa', true], ['mastercard', false]]);
            });
        });
    });

    it('moves the default and promotes the newest card when the default is removed', () => {
        signIn(RIDER).then((token) => {
            call('GET', '/api/v1/payment-methods', token).then((list) => {
                const [visa, mastercard] = list.body.data as Card[];

                call('POST', `/api/v1/payment-methods/${mastercard.id}/default`, token).then((r) => {
                    expect(r.status).to.equal(200);
                    expect(r.body.data).to.include({ id: mastercard.id, is_default: true });
                });
                call('GET', '/api/v1/payment-methods', token).its('body.data').then((cards: Card[]) => {
                    expect(cards.map((card) => [card.id, card.is_default])).to.deep.equal([[mastercard.id, true], [visa.id, false]]);
                });

                call('DELETE', `/api/v1/payment-methods/${mastercard.id}`, token).its('status').should('eq', 204);
                sql('select id, is_default, deleted_at is not null as removed from payment_methods where id in (?, ?) order by id', [visa.id, mastercard.id]).then((rows) => {
                    expect(rows).to.deep.equal([{ id: visa.id, is_default: true, removed: false }, { id: mastercard.id, is_default: false, removed: true }]);
                });
                call('GET', '/api/v1/payment-methods', token).its('body.data').then((cards: Card[]) => expect(cards.map((card) => card.id)).to.deep.equal([visa.id]));
            });
        });
    });

    it('refuses a token the gateway does not accept, a card number and an empty body, and saves nothing', () => {
        newRider(OTHER_RIDER, 'Bob Token').then(({ id, token }) => {
            call('POST', '/api/v1/payment-methods', token, { token: 'tok_invalid' }).then((r) => {
                expect(r.status).to.equal(422);
                expect(r.body.errors.token).to.deep.equal(['This card could not be saved. Check it or try another card.']);
            });
            call('POST', '/api/v1/payment-methods', token, { token: '4242424242424242' }).then((r) => {
                expect(r.status).to.equal(422);
                expect(r.body.errors).to.have.property('token');
            });
            call('POST', '/api/v1/payment-methods', token, {}).then((r) => {
                expect(r.status).to.equal(422);
                expect(r.body.errors).to.have.property('token');
            });
            sql('select count(*)::int as n from payment_methods where user_id = ?', [id]).its('0.n').should('eq', 0);
        });
    });

    it("answers 404 for another rider's card and leaves it untouched; 401 without a token; 403 from the driver app", () => {
        signIn(RIDER).then((token) => {
            call('GET', '/api/v1/payment-methods', token).its('body.data.0.id').then((cardId: number) => {
                signIn(OTHER_RIDER).then((other) => {
                    call('DELETE', `/api/v1/payment-methods/${cardId}`, other).its('status').should('eq', 404);
                    call('POST', `/api/v1/payment-methods/${cardId}/default`, other).its('status').should('eq', 404);
                });
                sql('select is_default, deleted_at is null as live from payment_methods where id = ?', [cardId]).its('0').should('deep.equal', { is_default: true, live: true });
            });
        });

        call('GET', '/api/v1/payment-methods', null).its('status').should('eq', 401);
        call('POST', '/api/v1/payment-methods', null, { token: 'tok_visa' }).its('status').should('eq', 401);
        call('GET', '/api/v1/account/outstanding', null).its('status').should('eq', 401);
        pay('nope', 'cypress-key-0001').its('status').should('eq', 401);

        cy.make('rider', { name: 'Dan Driver', phone: DRIVER, driver: true }).then(() => signIn(DRIVER, 'driver')).then((driverToken) => {
            call('GET', '/api/v1/payment-methods', driverToken).its('status').should('eq', 403);
            call('POST', '/api/v1/payment-methods', driverToken, { token: 'tok_visa' }).its('status').should('eq', 403);
            call('GET', '/api/v1/account/outstanding', driverToken).its('status').should('eq', 403);
        });
    });

    it('refuses to remove a card that pays for a trip in progress', () => {
        newRider('+37491770055', 'Cara Busy').then(({ id, token }) => {
            call('POST', '/api/v1/payment-methods', token, { token: 'tok_visa' }).its('body.data.id').then((cardId: number) => {
                cy.make('card_trip', { rider_id: id, payment_method_id: cardId });
                call('DELETE', `/api/v1/payment-methods/${cardId}`, token).then((r) => {
                    expect(r.status).to.equal(409);
                    expect(r.body).to.deep.equal({ message: 'This card is paying for a trip in progress. Remove it after the trip.', code: 'card_in_use' });
                });
                sql('select deleted_at is null as live from payment_methods where id = ?', [cardId]).its('0.live').should('eq', true);
            });
        });
    });

    // AC: GET /api/v1/account/outstanding and POST …/pay (idempotency key) settle a failed capture
    it('shows a failed capture as an outstanding balance, in the account and in /me', () => {
        newRider('+37491770056', 'Dina Owes').then(({ id, token }) => {
            cy.make<{ id: number; public_code: string; payment_status: string }>('failed_card_trip', { rider_id: id, fare: 150000 }).then((trip) => {
                expect(trip.payment_status).to.equal('payment_failed');

                call('GET', '/api/v1/account/outstanding', token).then((r) => {
                    expect(r.status).to.equal(200);
                    expect(r.body.data.has_outstanding).to.equal(true);
                    expect(r.body.data.totals).to.deep.equal([{ amount: 150000, currency: 'AMD' }]);
                    expect(r.body.data.trips).to.have.length(1);
                    expect(r.body.data.trips[0]).to.include({ id: trip.id, public_code: trip.public_code, amount: 150000, currency: 'AMD' });
                });
                call('GET', '/api/v1/me', token).its('body.user.rider.outstanding_balance').should('deep.equal', { amount: 150000, currency: 'AMD' });
                // The capture that failed is on record, with the gateway's reason.
                sql("select status, failure_code from transactions where trip_id = ? and type = 'capture'", [trip.id]).its('0').should('deep.equal', { status: 'failed', failure_code: 'card_declined' });
            });
        });
    });

    // AC: trip creation with a card is refused while an outstanding balance exists
    it('refuses a new card trip while money is owed, without asking the gateway', () => {
        sql('select id from users where name = ?', ['Dina Owes']).its('0.id').then((riderId: number) => {
            sql('select id from payment_methods where user_id = ? order by id limit 1', [riderId]).its('0.id').then((cardId: number) => {
                cy.make('hold_card_trip', { rider_id: riderId, payment_method_id: cardId }).then((result) => {
                    expect(result).to.include({ refused: 'outstanding_balance', status: 409 });
                    sql('select count(*)::int as n from transactions where trip_id = ?', [result.trip_id]).its('0.n').should('eq', 0);
                });
            });
        });
    });

    it('needs the Idempotency-Key header and one of the rider\'s own cards', () => {
        signIn('+37491770056').then((token) => {
            sql('select id from users where name = ?', ['Dina Owes']).its('0.id').then((riderId: number) => {
                pay(token, null).then((r) => {
                    expect(r.status).to.equal(422);
                    expect(r.body.errors.idempotency_key).to.deep.equal(['Send an Idempotency-Key header with this payment.']);
                });
                sql('select id from payment_methods where user_id <> ? limit 1', [riderId]).its('0.id').then((foreignCard: number) => {
                    pay(token, 'cypress-pay-foreign-01', { payment_method_id: foreignCard }).then((r) => {
                        expect(r.status).to.equal(422);
                        expect(r.body.errors.payment_method_id).to.deep.equal(['Choose one of your saved cards.']);
                    });
                });
                chargesOf(riderId).should('have.length', 0);
            });
        });
    });

    it('answers 402 for a declined card and keeps the balance; the same key with another card is refused', () => {
        signIn('+37491770056').then((token) => {
            sql('select id from users where name = ?', ['Dina Owes']).its('0.id').then((riderId: number) => {
                call('POST', '/api/v1/payment-methods', token, { token: 'tok_declined', make_default: true }).its('status').should('eq', 201);
                pay(token, 'cypress-pay-declined-01').then((r) => {
                    expect(r.status).to.equal(402);
                    expect(r.body).to.deep.equal({ message: 'The card was declined. Try another card.', code: 'card_declined' });
                });
                chargesOf(riderId).then((rows) => expect(rows.map((row) => row.status)).to.deep.equal(['failed']));
                call('GET', '/api/v1/account/outstanding', token).its('body.data.totals').should('deep.equal', [{ amount: 150000, currency: 'AMD' }]);

                call('POST', '/api/v1/payment-methods', token, { token: 'tok_visa' }).its('body.data.id').then((goodCard: number) => {
                    pay(token, 'cypress-pay-declined-01', { payment_method_id: goodCard }).then((r) => {
                        expect(r.status).to.equal(409);
                        expect(r.body.code).to.equal('idempotency_key_reused');
                    });
                });
                chargesOf(riderId).should('have.length', 1);
            });
        });
    });

    it('pays the balance once even when the same request is sent twice, and the trip becomes paid', () => {
        signIn('+37491770056').then((token) => {
            sql('select id from users where name = ?', ['Dina Owes']).its('0.id').then((riderId: number) => {
                sql("select id from payment_methods where user_id = ? and gateway_payment_method_id like 'fake_pm_ok_%'", [riderId]).its('0.id').then((goodCard: number) => {
                    const key = 'cypress-pay-good-0001';
                    pay(token, key, { payment_method_id: goodCard }).then((r) => {
                        expect(r.status).to.equal(200);
                        expect(r.body.data).to.deep.equal({ has_outstanding: false, totals: [], trips: [] });
                    });
                    // A retry on a flaky connection: same key, same answer, no second charge.
                    pay(token, key, { payment_method_id: goodCard }).then((r) => {
                        expect(r.status).to.equal(200);
                        expect(r.body.data.has_outstanding).to.equal(false);
                    });

                    chargesOf(riderId).then((rows) => {
                        const succeeded = rows.filter((row) => row.status === 'succeeded');
                        expect(succeeded).to.have.length(1);
                        expect(succeeded[0]).to.include({ amount: 150000, payment_method_id: goodCard });
                        expect(succeeded[0].idempotency_key).to.equal(`${key}:trip:${succeeded[0].trip_id}`);
                        sql('select payment_status from trips where id = ?', [succeeded[0].trip_id]).its('0.payment_status').should('eq', 'paid');
                    });
                    call('GET', '/api/v1/me', token).its('body.user.rider.outstanding_balance').should('deep.equal', { amount: 0, currency: 'AMD' });

                    pay(token, 'cypress-pay-good-0002').then((r) => {
                        expect(r.status).to.equal(409);
                        expect(r.body.code).to.equal('nothing_outstanding');
                    });
                    // Now a card trip goes through: the hold is the estimate plus 20%.
                    cy.make('hold_card_trip', { rider_id: riderId, payment_method_id: goodCard }).should('deep.include', { held: 180000, payment_status: 'authorized' });
                });
            });
        });
    });

    it('limits card writes per rider (429 with Retry-After) while reading stays open', () => {
        newRider('+37491770057', 'Eva Limit').then(({ token }) => {
            Cypress._.times(10, () => call('POST', '/api/v1/payment-methods', token, { token: 'tok_invalid' }).its('status').should('eq', 422));
            call('POST', '/api/v1/payment-methods', token, { token: 'tok_visa' }).then((r) => {
                expect(r.status).to.equal(429);
                expect(r.headers).to.have.property('retry-after');
            });
            call('GET', '/api/v1/payment-methods', token).its('status').should('eq', 200);
        });
    });
});
