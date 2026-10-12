/**
 * P5-T2 · Fare estimation: effective-dated fare rules and POST /api/v1/trips/fare-estimate.
 * Admin: saving a fare rule never edits a row, it adds a version (the old one is closed), with a per-class
 * history and effective-from/to columns. API: one estimate per class in the pickup zone, a stored
 * quote, "outside service area". The FareCalculator's arithmetic is covered by PHPUnit; here the
 * numbers come from a route of 0.01 degrees of latitude: 1446 m, 174 s (economy 800, comfort 1076).
 */
const CODE = '123456';
const otpSignIn = (phone: string, app = 'rider') =>
    cy.api('POST', '/api/v1/auth/otp/request', { phone, app }).its('status').should('eq', 202).then(() =>
        cy.api('POST', '/api/v1/auth/otp/verify', { phone, code: CODE, app, device_id: `cypress-${app}-device` }).then((response) => {
            expect(response.status, `sign-in of ${phone}`).to.equal(200);
            return response.body as { access_token: string; user: { id: number } };
        }));
const estimate = (token: string, pickup = { lat: 40.5, lng: 44.0 }, dropoff = { lat: 40.51, lng: 44.0 }) =>
    cy.api('POST', '/api/v1/trips/fare-estimate', { pickup, dropoff }, token);

const rulesOf = (zoneId: number) => cy.sql(
    'select id, vehicle_class, base_fare, per_km, per_minute, min_fare, tax_rate_bps, tax_inclusive, currency, effective_from::text as effective_from, effective_to::text as effective_to, created_by from fare_rules where zone_id = ? order by id',
    [zoneId],
);

describe('P5-T2 · Fare estimation', () => {
    let admin: { id: number; name: string };

    before(() => {
        cy.resetDb();
        cy.make('admin', { email: 'cy-fares-admin@taxikosmos.test', role: 'admin', name: 'Fay Fares' }).then((a) => (admin = a));
        cy.make('admin', { email: 'cy-fares-support@taxikosmos.test', role: 'support' });
    });

    beforeEach(() => cy.make('clear_rate_limits'));

    // ---- admin: Zones & Fares ----

    // Criterion: admin screen, effective-from/to columns; saving creates a rule row.
    it('adds the first fare rule of a class from the form and shows it as current', () => {
        cy.loginAs('cy-fares-admin@taxikosmos.test');
        cy.make('zone', { name: 'Fares UI One', code: 'FARES-UI-1', lat: 41.0, lng: 44.0 }).then((zone) => {
            cy.visit(`/admin/zones?zone=${zone.id}`);
            cy.contains('No fare rules for this zone.').should('be.visible');
            cy.intercept('POST', `/admin/zones/${zone.id}/fare-rules`).as('save');

            cy.findByRole('button', { name: /Add fare rule/ }).click();
            cy.findByRole('dialog', { name: 'Add fare rule' }).within(() => {
                cy.findByLabelText(/^Vehicle class/).should('have.value', 'economy');
                cy.findByLabelText(/^Base Fare/).type('500');
                cy.findByLabelText(/^Per km/).type('150');
                cy.findByLabelText(/^Per min/).type('20');
                cy.findByLabelText(/^Min Fare/).type('800');
                cy.findByRole('button', { name: 'Add fare rule' }).dblclick();
            });

            cy.wait('@save').its('response.statusCode').should('eq', 302);
            cy.findAllByRole('status').last().should('contain', 'Fare rule saved. The new prices apply from now.');
            cy.findByRole('dialog').should('not.exist');
            cy.contains('tr', 'Economy').should('contain', 'AMD 500').and('contain', 'AMD 150').and('contain', 'AMD 800').and('contain', 'Current');

            cy.reload();
            cy.contains('tr', 'Economy').should('contain', 'AMD 500').and('contain', 'Current');
            rulesOf(zone.id).then((rows) => {
                expect(rows, 'a double-click saves one rule').to.have.length(1);
                expect(rows[0]).to.include({ vehicle_class: 'economy', base_fare: 500, per_km: 150, per_minute: 20, min_fare: 800, tax_rate_bps: 0, tax_inclusive: false, currency: 'AMD', effective_to: null, created_by: admin.id });
            });
            cy.sql("select causer_id, properties->'context'->>'vehicle_class' as class from activity_log where description = 'fare_rule.created' and properties->'context'->>'zone' = 'FARES-UI-1'").then((rows) => {
                expect(rows).to.have.length(1);
                expect(rows[0]).to.include({ causer_id: admin.id, class: 'economy' });
            });
        });
    });

    // Criterion: admin CRUD creates a new effective-dated row rather than UPDATE-ing an existing one.
    it('editing a price adds a new version and closes the old one without changing it', () => {
        cy.loginAs('cy-fares-admin@taxikosmos.test');
        cy.make('zone', { name: 'Fares UI Two', code: 'FARES-UI-2', lat: 41.2, lng: 44.2 }).then((zone) => {
            cy.make('fare_rule', { zone_code: 'FARES-UI-2', base_fare: 500 }).then((first) => {
                cy.visit(`/admin/zones?zone=${zone.id}`);
                cy.intercept('POST', `/admin/zones/${zone.id}/fare-rules`).as('save');

                cy.findByRole('button', { name: 'New version of Economy' }).click();
                cy.findByRole('dialog', { name: 'New version of Economy' }).within(() => {
                    cy.contains('starts a new version from now').should('be.visible');
                    cy.findByLabelText(/^Vehicle class/).should('be.disabled');
                    cy.findByLabelText(/^Base Fare/).should('have.value', '500').clear().type('650');
                    cy.findByLabelText(/^Tax rate/).clear().type('1800');
                    cy.findByLabelText('Prices already include tax').check();
                    cy.findByRole('button', { name: 'Save new version' }).click();
                });

                cy.wait('@save').its('request.body').should('include', { vehicle_class: 'economy', base_fare: 650, tax_rate_bps: 1800, tax_inclusive: true, per_km: 150, min_fare: 800 });
                cy.findAllByRole('status').last().should('contain', 'Fare rule saved. The new prices apply from now.');
                cy.contains('tr', 'Economy').should('contain', 'AMD 650').and('not.contain', 'AMD 500').and('contain', 'Current');

                rulesOf(zone.id).then((rows) => {
                    expect(rows, 'a new row, not an update').to.have.length(2);
                    expect(rows[0].id).to.equal(first.id);
                    expect(rows[0], 'the old row keeps its price').to.include({ base_fare: 500, tax_rate_bps: 0, tax_inclusive: false });
                    expect(rows[0].effective_to, 'and is closed').not.to.equal(null);
                    expect(rows[1]).to.include({ base_fare: 650, tax_rate_bps: 1800, tax_inclusive: true, effective_to: null, created_by: admin.id });
                    expect(rows[1].effective_from, 'no gap and no overlap').to.equal(rows[0].effective_to);
                });
                cy.sql("select attribute_changes->'old'->>'base_fare' as was, attribute_changes->'attributes'->>'base_fare' as now, causer_id from activity_log where description = 'fare_rule.versioned' and properties->'context'->>'zone' = 'FARES-UI-2'").then((rows) => {
                    expect(rows).to.have.length(1);
                    expect(rows[0]).to.include({ was: '500', now: '650', causer_id: admin.id });
                });

                cy.reload();
                cy.contains('tr', 'Economy').should('contain', 'AMD 650').and('contain', 'Current');
            });
        });
    });

    it('saving the prices already in force changes nothing', () => {
        cy.loginAs('cy-fares-admin@taxikosmos.test');
        cy.make('zone', { name: 'Fares UI Same', code: 'FARES-UI-S', lat: 41.4, lng: 44.4 }).then((zone) => {
            cy.make('fare_rule', { zone_code: 'FARES-UI-S' });
            cy.visit(`/admin/zones?zone=${zone.id}`);

            cy.findByRole('button', { name: 'New version of Economy' }).click();
            cy.findByRole('dialog').within(() => cy.findByRole('button', { name: 'Save new version' }).click());

            cy.findAllByRole('status').last().should('contain', 'Nothing changed: these prices are already in force.');
            rulesOf(zone.id).then((rows) => expect(rows).to.have.length(1));
            cy.sql("select count(*)::int as n from activity_log where description like 'fare_rule.%' and properties->'context'->>'zone' = 'FARES-UI-S'").its('0.n').should('eq', 0);
        });
    });

    // Criterion: "what was the base_fare for zone X, class Y at timestamp T" is answerable from fare_rules history; per-rule history view.
    it('shows the history of a class and answers the price at a past moment from the table', () => {
        cy.loginAs('cy-fares-admin@taxikosmos.test');
        cy.make('zone', { name: 'Fares History', code: 'FARES-HIST', lat: 41.6, lng: 44.6 }).then((zone) => {
            cy.make('fare_rule', { zone_code: 'FARES-HIST', base_fare: 400, effective_from: '2026-01-01 00:00:00', effective_to: '2026-03-01 00:00:00' });
            cy.make('fare_rule', { zone_code: 'FARES-HIST', base_fare: 500, effective_from: '2026-03-01 00:00:00' });
            cy.visit(`/admin/zones?zone=${zone.id}`);

            cy.findByRole('button', { name: 'History of Economy' }).click();
            cy.location('search').should('contain', 'history=economy');
            cy.findByRole('dialog', { name: 'History of Economy' }).within(() => {
                cy.get('tbody tr').should('have.length', 2);
                cy.get('tbody tr').eq(0).should('contain', 'AMD 500').and('contain', 'Mar 1 · 04:00').and('contain', 'Current');
                cy.get('tbody tr').eq(1).should('contain', 'AMD 400').and('contain', 'Jan 1 · 04:00').and('contain', 'Mar 1 · 04:00');
            });

            cy.reload();
            cy.findByRole('dialog', { name: 'History of Economy' }).should('be.visible');
            cy.findByRole('dialog').within(() => cy.findByRole('button', { name: 'Close' }).click());
            cy.findByRole('dialog').should('not.exist');
            cy.location('search').should('not.contain', 'history');

            const baseFareAt = (moment: string) => cy.sql(
                "select base_fare from fare_rules where zone_id = ? and vehicle_class = 'economy' and effective_from <= ?::timestamp and (effective_to is null or effective_to > ?::timestamp)",
                [zone.id, moment, moment],
            );
            baseFareAt('2026-02-15 12:00:00').its('0.base_fare').should('eq', 400);
            baseFareAt('2026-02-28 23:59:59').its('0.base_fare').should('eq', 400);
            baseFareAt('2026-03-01 00:00:00').its('0.base_fare').should('eq', 500); // the old rule ends exactly when the new one starts
            baseFareAt('2025-12-31 23:59:59').should('have.length', 0);
        });
    });

    it('lists who saved each version in the history', () => {
        cy.loginAs('cy-fares-admin@taxikosmos.test');
        cy.make('zone', { name: 'Fares Who', code: 'FARES-WHO', lat: 41.8, lng: 44.8 }).then((zone) => {
            cy.make('fare_rule', { zone_code: 'FARES-WHO' });
            cy.visit(`/admin/zones?zone=${zone.id}`);
            cy.findByRole('button', { name: 'New version of Economy' }).click();
            cy.findByRole('dialog').within(() => {
                cy.findByLabelText(/^Base Fare/).clear().type('777');
                cy.findByRole('button', { name: 'Save new version' }).click();
            });
            cy.findAllByRole('status').last().should('contain', 'Fare rule saved.');

            cy.findByRole('button', { name: 'History of Economy' }).click();
            cy.findByRole('dialog', { name: 'History of Economy' }).within(() => {
                cy.get('tbody tr').eq(0).should('contain', 'AMD 777').and('contain', admin.name);
                cy.get('tbody tr').eq(1).should('contain', 'AMD 500');
            });
        });
    });

    // Validation: the message shows next to the field and nothing is saved.
    it('shows invalid amounts and server errors next to the field and saves nothing', () => {
        cy.loginAs('cy-fares-admin@taxikosmos.test');
        cy.make('zone', { name: 'Fares Invalid', code: 'FARES-BAD', lat: 42.0, lng: 45.0 }).then((zone) => {
            cy.make('fare_rule', { zone_code: 'FARES-BAD' });
            cy.visit(`/admin/zones?zone=${zone.id}`);
            cy.findByRole('button', { name: 'New version of Economy' }).click();

            cy.findByRole('dialog').within(() => {
                cy.findByLabelText(/^Base Fare/).clear().type('12.5'); // AMD has no decimals
                cy.findByLabelText(/^Per km/).clear().type('abc');
                cy.findByRole('button', { name: 'Save new version' }).click();
                cy.findByLabelText(/^Base Fare/).closest('div').should('contain', 'Enter a valid amount.');
                cy.findByLabelText(/^Per km/).closest('div').should('contain', 'Enter a valid amount.');

                cy.findByLabelText(/^Base Fare/).clear().type('600');
                cy.findByLabelText(/^Per km/).clear().type('150');
                cy.findByLabelText(/^Tax rate/).clear().type('10001');
                cy.findByRole('button', { name: 'Save new version' }).click();
                cy.findByLabelText(/^Tax rate/).closest('div').should('contain', 'must be between 0 and 10000');

                // a price so large that price x distance could overflow is refused
                cy.findByLabelText(/^Tax rate/).clear().type('0');
                cy.findByLabelText(/^Per km/).clear().type('1000000001');
                cy.findByRole('button', { name: 'Save new version' }).click();
                cy.findByLabelText(/^Per km/).closest('div').should('contain', 'must not be greater than 1000000000');
            });

            cy.findByRole('dialog').should('be.visible');
            rulesOf(zone.id).then((rows) => {
                expect(rows).to.have.length(1);
                expect(rows[0]).to.include({ base_fare: 500, effective_to: null });
            });
            cy.sql("select count(*)::int as n from activity_log where description = 'fare_rule.versioned' and properties->'context'->>'zone' = 'FARES-BAD'").its('0.n').should('eq', 0);
        });
    });

    // Permission: fares.manage. Support has neither it nor zones.manage.
    it('refuses the page and every fare write to a role without the permission', () => {
        cy.make('zone', { name: 'Fares Guarded', code: 'FARES-GRD', lat: 42.2, lng: 45.2 }).then((zone) => {
            cy.make('fare_rule', { zone_code: 'FARES-GRD' });
            const body = { vehicle_class: 'economy', base_fare: 1, per_km: 1, per_minute: 1, min_fare: 1, waiting_free_minutes: 0, waiting_per_minute: 0, cancellation_fee: 0, cancellation_free_seconds: 0, booking_fee: 0, tax_rate_bps: 0, tax_inclusive: false };

            cy.loginAs('cy-fares-support@taxikosmos.test');
            cy.visit('/admin/zones', { failOnStatusCode: false });
            cy.findByRole('heading', { name: "You don't have access" }).should('be.visible');
            cy.adminRequest('POST', `/admin/zones/${zone.id}/fare-rules`, body).its('status').should('eq', 403);

            rulesOf(zone.id).then((rows) => {
                expect(rows).to.have.length(1);
                expect(rows[0].base_fare).to.equal(500);
            });
        });
    });

    it('sends a guest to the sign-in page', () => {
        Cypress.session.clearAllSavedSessions();
        cy.clearAllCookies();
        cy.request({ url: '/admin/zones', followRedirect: false, failOnStatusCode: false }).then((response) => {
            expect(response.status).to.equal(302);
            expect(response.redirectedToUrl).to.contain('/login');
        });
    });

    // ---- API: POST /api/v1/trips/fare-estimate ----

    // Criterion: one estimate per available vehicle class (range, currency, surge, ETA) plus a quote_id, stored 10 minutes.
    it('returns a priced estimate for every class in the pickup zone and stores a quote for ten minutes', () => {
        cy.make('zone', { name: 'Fares API One', code: 'FARES-API-1', lat: 40.5, lng: 44.0 }).then((zone) => {
            cy.make('fare_rule', { zone_code: 'FARES-API-1' }).then((economy) => {
                cy.make('fare_rule', { zone_code: 'FARES-API-1', vehicle_class: 'comfort', base_fare: 700, per_km: 200, per_minute: 30, min_fare: 1000 });
                otpSignIn('+37491770031').then((session) => {
                    estimate(session.access_token).then((response) => {
                        expect(response.status).to.equal(201);
                        expect(response.body).to.have.all.keys('quote_id', 'expires_at', 'zone', 'currency', 'distance_meters', 'duration_seconds', 'estimates');
                        expect(response.body.quote_id).to.match(/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/);
                        expect(response.body.zone).to.deep.equal({ id: zone.id, name: 'Fares API One' });
                        expect(response.body).to.include({ currency: 'AMD', distance_meters: 1446, duration_seconds: 174 });
                        expect(response.body.estimates).to.deep.equal([
                            { vehicle_class: 'economy', currency: 'AMD', amount: 800, amount_min: 720, amount_max: 880, surge_multiplier: '1.00', eta_to_pickup_seconds: null },
                            { vehicle_class: 'comfort', currency: 'AMD', amount: 1076, amount_min: 968, amount_max: 1184, surge_multiplier: '1.00', eta_to_pickup_seconds: null },
                        ]);
                        expect(JSON.stringify(response.body)).not.to.contain('breakdown');

                        cy.sql("select rider_id, zone_id, distance_meters, duration_seconds, to_char(expires_at - created_at, 'HH24:MI:SS') as lifetime, estimates->0->>'fare_rule_id' as rule_id, estimates->0->'breakdown'->>'total' as total from fare_quotes where id = ?", [response.body.quote_id]).then((rows) => {
                            expect(rows).to.have.length(1);
                            expect(rows[0]).to.include({ rider_id: session.user.id, zone_id: zone.id, distance_meters: 1446, duration_seconds: 174, lifetime: '00:10:00', rule_id: String(economy.id), total: '800' });
                        });
                    });
                });
            });
        });
    });

    // Criterion: the trip is priced with the rule and surge the rider saw.
    it('keeps a quote as it was shown even after the prices change', () => {
        cy.make('zone', { name: 'Fares API Change', code: 'FARES-API-C', lat: 39.5, lng: 44.0 }).then((zone) => {
            cy.make('fare_rule', { zone_code: 'FARES-API-C' }).then((first) => {
                otpSignIn('+37491770032').then((session) => {
                    estimate(session.access_token, { lat: 39.5, lng: 44.0 }, { lat: 39.51, lng: 44.0 }).then((before) => {
                        expect(before.body.estimates[0].amount).to.equal(800);

                        cy.loginAs('cy-fares-admin@taxikosmos.test');
                        cy.adminRequest('POST', `/admin/zones/${zone.id}/fare-rules`, { vehicle_class: 'economy', base_fare: 2000, per_km: 150, per_minute: 20, min_fare: 800, waiting_free_minutes: 3, waiting_per_minute: 30, cancellation_fee: 500, cancellation_free_seconds: 120, booking_fee: 0, tax_rate_bps: 0, tax_inclusive: false }).its('status').should('eq', 302);

                        estimate(session.access_token, { lat: 39.5, lng: 44.0 }, { lat: 39.51, lng: 44.0 }).then((after) => {
                            expect(after.body.estimates[0].amount, 'a new estimate uses the new prices').to.equal(2275);
                            expect(after.body.quote_id).not.to.equal(before.body.quote_id);
                        });
                        cy.sql("select estimates->0->>'fare_rule_id' as rule_id, estimates->0->>'amount' as amount from fare_quotes where id = ?", [before.body.quote_id]).then((rows) => {
                            expect(rows[0], 'the old quote still points at the rule the rider saw').to.deep.equal({ rule_id: String(first.id), amount: '800' });
                        });
                    });
                });
            });
        });
    });

    // Criterion: airport/toll fees by location.
    it('adds a fixed place fee when the pickup is inside its area', () => {
        cy.make('zone', { name: 'Fares API Fee', code: 'FARES-API-F', lat: 38.5, lng: 44.0 });
        cy.make('fare_rule', { zone_code: 'FARES-API-F' });
        cy.make('fare_fee', { lat: 38.5, lng: 44.0, half: 0.003, name: 'Airport pickup', amount: 1000, applies_to: 'pickup' });
        otpSignIn('+37491770033').then((session) => {
            estimate(session.access_token, { lat: 38.5, lng: 44.0 }, { lat: 38.51, lng: 44.0 }).then((inside) => {
                expect(inside.body.estimates[0].amount, '800 + the 1000 fee').to.equal(1800);
                cy.sql("select estimates->0->'breakdown'->'fees'->0->>'name' as name from fare_quotes where id = ?", [inside.body.quote_id]).its('0.name').should('eq', 'Airport pickup');
            });
            // A pickup outside the fee area pays no pickup fee, even if the trip ends inside it.
            estimate(session.access_token, { lat: 38.51, lng: 44.0 }, { lat: 38.5, lng: 44.0 }).its('body.estimates.0.amount').should('eq', 800);
        });
    });

    // Criterion: pickups outside every active zone return a clear "outside service area" error.
    it('refuses a pickup outside every zone, and a zone without prices, and stores no quote', () => {
        cy.make('zone', { name: 'Fares API Empty', code: 'FARES-API-E', lat: 37.5, lng: 44.0 });
        otpSignIn('+37491770034').then((session) => {
            cy.sql('select count(*)::int as n from fare_quotes').its('0.n').then((quotesBefore) => {
                estimate(session.access_token, { lat: 48.85, lng: 2.35 }).then((outside) => {
                    expect(outside.status).to.equal(422);
                    expect(outside.body).to.deep.equal({ message: 'Sorry, we do not serve this pickup location yet.', code: 'outside_service_area' });
                });
                estimate(session.access_token, { lat: 37.5, lng: 44.0 }, { lat: 37.51, lng: 44.0 }).then((empty) => {
                    expect(empty.status).to.equal(422);
                    expect(empty.body).to.deep.equal({ message: 'Prices are not available for this area yet.', code: 'no_fare_rules' });
                });
                cy.sql('select count(*)::int as n from fare_quotes').its('0.n').should('eq', quotesBefore);
            });
        });
    });

    it('answers the error in the rider\'s language', () => {
        otpSignIn('+37491770035').then((session) => {
            cy.request({ method: 'POST', url: '/api/v1/trips/fare-estimate', failOnStatusCode: false, headers: { Authorization: `Bearer ${session.access_token}`, 'Accept-Language': 'ru', Accept: 'application/json' }, body: { pickup: { lat: 48.85, lng: 2.35 }, dropoff: { lat: 48.86, lng: 2.35 } } }).then((response) => {
                expect(response.status).to.equal(422);
                expect(response.body).to.deep.equal({ message: 'К сожалению, мы пока не обслуживаем это место посадки.', code: 'outside_service_area' });
            });
        });
    });

    it('rejects bad coordinates with field errors and requires a rider token', () => {
        otpSignIn('+37491770036').then((session) => {
            cy.api('POST', '/api/v1/trips/fare-estimate', { pickup: { lat: 95, lng: 44 }, dropoff: { lat: 40, lng: 44 } }, session.access_token).then((response) => {
                expect(response.status).to.equal(422);
                expect(response.body.errors).to.have.property('pickup.lat');
            });
            cy.api('POST', '/api/v1/trips/fare-estimate', { pickup: { lat: 40, lng: 44 } }, session.access_token).its('body.errors').should('have.property', 'dropoff');
            cy.api('POST', '/api/v1/trips/fare-estimate', { pickup: { lat: 40.5, lng: 44 }, dropoff: { lat: 40.51, lng: 44 } }).its('status').should('eq', 401);
        });
        otpSignIn('+37491770037', 'driver').then((driver) => {
            estimate(driver.access_token).its('status').should('eq', 403);
        });
    });

    // Rate limit: 30 estimates a minute per rider.
    it('limits estimates to 30 a minute per rider', () => {
        cy.make('zone', { name: 'Fares API Limit', code: 'FARES-API-L', lat: 36.5, lng: 44.0 });
        cy.make('fare_rule', { zone_code: 'FARES-API-L' });
        otpSignIn('+37491770038').then((session) => {
            cy.make('clear_rate_limits');
            for (let i = 0; i < 30; i++) {
                estimate(session.access_token, { lat: 36.5, lng: 44.0 }, { lat: 36.51, lng: 44.0 }).its('status').should('eq', 201);
            }
            estimate(session.access_token, { lat: 36.5, lng: 44.0 }, { lat: 36.51, lng: 44.0 }).then((limited) => {
                expect(limited.status).to.equal(429);
                expect(limited.headers).to.have.property('retry-after');
            });
        });
    });
});

export {};
