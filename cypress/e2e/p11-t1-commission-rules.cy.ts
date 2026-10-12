/**
 * P11-T1 · Commission rules: effective-dated, versioned on save, shown on the Commission page with a
 * per-rule history and the commission preview. The calculator's arithmetic (types, caps, surge, tax) is
 * covered by PHPUnit and Vitest; here the browser, the database, the audit log and the permission are.
 */
const rulesOf = (zoneId: number) => cy.sql(
    'select id, vehicle_class, type, rate_bps, fixed_amount, min_commission, max_commission, applies_to_surge, currency, effective_from::text as effective_from, effective_to::text as effective_to, created_by from commission_rules where zone_id = ? order by id',
    [zoneId],
);

describe('P11-T1 · Commission rules', () => {
    let admin: { id: number; name: string };

    before(() => {
        cy.resetDb();
        cy.make('admin', { email: 'cy-commission-admin@taxikosmos.test', role: 'admin', name: 'Cora Commission' }).then((a) => (admin = a));
        cy.make('admin', { email: 'cy-commission-support@taxikosmos.test', role: 'support' });
    });

    beforeEach(() => {
        cy.loginAs('cy-commission-admin@taxikosmos.test');
    });

    // Criterion: POST /admin/commission-rules, effective-dated; shown on the Commission page.
    it('adds the first rule of a zone from the form and lists it as current', () => {
        cy.make('zone', { name: 'Comm One', code: 'COMM-1', lat: 41.0, lng: 44.0 }).then((zone) => {
            cy.visit('/admin/commission-rules?filter[search]=Comm One');
            cy.contains('No commission rules').should('be.visible');
            cy.intercept('POST', '/admin/commission-rules').as('save');

            cy.findByRole('button', { name: 'New version' }).click();
            cy.findByRole('dialog', { name: 'New commission rule' }).within(() => {
                cy.findByLabelText(/^Zone/).select('Comm One');
                cy.findByLabelText(/^Vehicle class/).select('Economy');
                cy.findByLabelText(/^Rate/).type('18');
                cy.findByLabelText(/^Minimum commission/).type('500');
                cy.findByLabelText(/^Maximum commission/).type('8000');
                cy.findByRole('button', { name: 'Save new version' }).dblclick();
            });

            cy.wait('@save').its('request.body').should('include', { zone_id: zone.id, vehicle_class: 'economy', type: 'percentage', rate_bps: 1800, fixed_amount: 0, min_commission: 500, max_commission: 8000, applies_to_surge: true });
            cy.findAllByRole('status').last().should('contain', 'Commission rule saved. The new terms apply from now.');
            cy.findByRole('dialog').should('not.exist');
            cy.contains('tr', 'Comm One').should('contain', 'Economy').and('contain', '18%').and('contain', 'AMD 500 / AMD 8,000').and('contain', 'Yes');

            cy.reload();
            cy.contains('tr', 'Comm One').should('contain', '18%');
            rulesOf(zone.id).then((rows) => {
                expect(rows, 'a double-click saves one rule').to.have.length(1);
                expect(rows[0]).to.include({ vehicle_class: 'economy', type: 'percentage', rate_bps: 1800, fixed_amount: 0, min_commission: 500, max_commission: 8000, applies_to_surge: true, currency: 'AMD', effective_to: null, created_by: admin.id });
            });
            cy.sql("select causer_id, properties->'context'->>'vehicle_class' as class from activity_log where description = 'commission_rule.created' and properties->'context'->>'zone' = 'COMM-1'").then((rows) => {
                expect(rows).to.have.length(1);
                expect(rows[0]).to.include({ causer_id: admin.id, class: 'economy' });
            });
        });
    });

    it('stores the zone-wide rule when the class is left as every class, and a fixed rule without a rate', () => {
        cy.make('zone', { name: 'Comm Fixed', code: 'COMM-FIX', lat: 41.2, lng: 44.2 }).then((zone) => {
            cy.visit('/admin/commission-rules');
            cy.findByRole('button', { name: 'New version' }).click();
            cy.findByRole('dialog').within(() => {
                cy.findByLabelText(/^Zone/).select('Comm Fixed');
                cy.findByLabelText(/^Type/).select('Fixed');
                cy.findByLabelText(/^Rate/).should('not.exist');
                cy.findByLabelText(/^Fixed amount/).type('2500');
                cy.findByRole('button', { name: 'Save new version' }).click();
            });

            cy.findAllByRole('status').last().should('contain', 'Commission rule saved.');
            rulesOf(zone.id).then((rows) => {
                expect(rows).to.have.length(1);
                expect(rows[0]).to.include({ vehicle_class: null, type: 'fixed', rate_bps: 0, fixed_amount: 2500, min_commission: 0, max_commission: null });
            });
            cy.contains('tr', 'Comm Fixed').should('contain', 'Every class').and('contain', 'AMD 2,500');
        });
    });

    // Criterion: effective-dated exactly like fare_rules: editing closes the current row and inserts a new one.
    it('a new version closes the old row without changing it and starts when the old one ends', () => {
        cy.make('zone', { name: 'Comm Two', code: 'COMM-2', lat: 41.4, lng: 44.4 }).then((zone) => {
            cy.make('commission_rule', { zone_code: 'COMM-2', vehicle_class: 'economy', rate_bps: 1800, min_commission: 500, max_commission: 8000 }).then((first) => {
                cy.visit('/admin/commission-rules?filter[search]=Comm Two');
                cy.intercept('POST', '/admin/commission-rules').as('save');

                cy.findByRole('button', { name: 'New version of the Comm Two rule' }).click();
                cy.findByRole('dialog', { name: 'New version of the Comm Two rule' }).within(() => {
                    cy.findByLabelText(/^Zone/).should('be.disabled');
                    cy.findByLabelText(/^Vehicle class/).should('be.disabled').and('have.value', 'economy');
                    cy.findByLabelText(/^Rate/).should('have.value', '18').clear().type('22');
                    cy.findByLabelText(/^Minimum commission/).should('have.value', '500');
                    cy.findByLabelText('Commission applies to the surge part of the fare').should('be.checked').uncheck();
                    cy.findByRole('button', { name: 'Save new version' }).click();
                });

                cy.wait('@save').its('request.body').should('include', { zone_id: zone.id, vehicle_class: 'economy', rate_bps: 2200, applies_to_surge: false, min_commission: 500 });
                cy.findAllByRole('status').last().should('contain', 'Commission rule saved.');
                cy.contains('tr', 'Comm Two').should('contain', '22%').and('not.contain', '18%').and('contain', 'No');

                rulesOf(zone.id).then((rows) => {
                    expect(rows, 'a new row, not an update').to.have.length(2);
                    expect(rows[0].id).to.equal(first.id);
                    expect(rows[0], 'the old row keeps its terms').to.include({ rate_bps: 1800, applies_to_surge: true });
                    expect(rows[0].effective_to, 'and is closed').not.to.equal(null);
                    expect(rows[1]).to.include({ rate_bps: 2200, applies_to_surge: false, effective_to: null, created_by: admin.id });
                    expect(rows[1].effective_from, 'no gap and no overlap').to.equal(rows[0].effective_to);
                });
                cy.sql("select attribute_changes->'old'->>'rate_bps' as was, attribute_changes->'attributes'->>'rate_bps' as now, causer_id from activity_log where description = 'commission_rule.versioned' and properties->'context'->>'zone' = 'COMM-2'").then((rows) => {
                    expect(rows).to.have.length(1);
                    expect(rows[0]).to.include({ was: '1800', now: '2200', causer_id: admin.id });
                });
            });
        });
    });

    it('saving the terms already in force changes nothing', () => {
        cy.make('zone', { name: 'Comm Same', code: 'COMM-SAME', lat: 41.6, lng: 44.6 }).then((zone) => {
            cy.make('commission_rule', { zone_code: 'COMM-SAME' });
            cy.visit('/admin/commission-rules?filter[search]=Comm Same');

            cy.findByRole('button', { name: 'New version of the Comm Same rule' }).click();
            cy.findByRole('dialog').within(() => cy.findByRole('button', { name: 'Save new version' }).click());

            cy.findAllByRole('status').last().should('contain', 'Nothing changed: these terms are already in force.');
            rulesOf(zone.id).then((rows) => expect(rows).to.have.length(1));
            cy.sql("select count(*)::int as n from activity_log where description like 'commission_rule.%' and properties->'context'->>'zone' = 'COMM-SAME'").its('0.n').should('eq', 0);
        });
    });

    // Criterion: per-rule history view; "which rule applied at timestamp T" is answerable from the table.
    it('shows the history of a rule and answers which rule applied at a past moment', () => {
        cy.make('zone', { name: 'Comm History', code: 'COMM-HIST', lat: 41.8, lng: 44.8 }).then((zone) => {
            cy.make('commission_rule', { zone_code: 'COMM-HIST', rate_bps: 1500, effective_from: '2026-01-01 00:00:00', effective_to: '2026-03-01 00:00:00' });
            cy.make('commission_rule', { zone_code: 'COMM-HIST', rate_bps: 1800, effective_from: '2026-03-01 00:00:00' });
            cy.visit('/admin/commission-rules?filter[search]=Comm History');

            cy.findByRole('button', { name: 'History of the Comm History rule' }).click();
            cy.location('search').should('contain', 'history=');
            cy.findByRole('dialog', { name: 'History of the Comm History rule' }).within(() => {
                cy.get('tbody tr').should('have.length', 2);
                cy.get('tbody tr').eq(0).should('contain', '18%').and('contain', 'Mar 1 · 04:00').and('contain', 'Current');
                cy.get('tbody tr').eq(1).should('contain', '15%').and('contain', 'Jan 1 · 04:00').and('contain', 'Mar 1 · 04:00');
            });

            cy.reload();
            cy.findByRole('dialog', { name: 'History of the Comm History rule' }).should('be.visible');
            cy.findByRole('dialog').within(() => cy.findByRole('button', { name: 'Close' }).click());
            cy.findByRole('dialog').should('not.exist');
            cy.location('search').should('not.contain', 'history');

            const rateAt = (moment: string) => cy.sql(
                'select rate_bps from commission_rules where zone_id = ? and vehicle_class is null and effective_from <= ?::timestamp and (effective_to is null or effective_to > ?::timestamp)',
                [zone.id, moment, moment],
            );
            rateAt('2026-02-15 12:00:00').its('0.rate_bps').should('eq', 1500);
            rateAt('2026-02-28 23:59:59').its('0.rate_bps').should('eq', 1500);
            rateAt('2026-03-01 00:00:00').its('0.rate_bps').should('eq', 1800);
            rateAt('2025-12-31 23:59:59').should('have.length', 0);
        });
    });

    // Criterion: the commission preview.
    it('previews the commission and the driver earnings for a fare, for the chosen rule', () => {
        cy.make('zone', { name: 'Comm Preview', code: 'COMM-PRE', lat: 42.0, lng: 45.0 }).then(() => {
            cy.make('commission_rule', { zone_code: 'COMM-PRE', vehicle_class: 'economy', rate_bps: 1800, min_commission: 500, max_commission: 8000 });
            cy.make('commission_rule', { zone_code: 'COMM-PRE', vehicle_class: 'comfort', type: 'fixed', rate_bps: 0, fixed_amount: 2500 });
            cy.visit('/admin/commission-rules?filter[search]=Comm Preview');

            // the first rule in force is the newest (comfort, fixed 2,500) unless another is chosen
            cy.findByLabelText('Rule to preview').select('Comm Preview · Economy');
            cy.location('search').should('contain', 'preview=');

            cy.findByTestId('preview-commission').should('have.text', 'AMD 2,250'); // 18 % of 12,500
            cy.findByTestId('preview-earnings').should('have.text', 'AMD 10,250');
            cy.findByLabelText('Trip fare').clear().type('1000');
            cy.findByTestId('preview-commission').should('have.text', 'AMD 500'); // 18 % = 180, lifted to the minimum
            cy.findByTestId('preview-earnings').should('have.text', 'AMD 500');
            cy.findByLabelText('Trip fare').clear().type('100000');
            cy.findByTestId('preview-commission').should('have.text', 'AMD 8,000'); // capped at the maximum
            cy.findByLabelText('Trip fare').clear().type('abc');
            cy.contains('Enter a valid fare.').should('be.visible');
        });
    });

    // Criterion: a rule change never alters an already-completed trip's commission.
    it('keeps a completed trip\'s commission when the rule changes afterwards', () => {
        cy.make('zone', { name: 'Comm Trip', code: 'COMM-TRIP', lat: 42.2, lng: 45.2 }).then((zone) => {
            cy.make('commission_rule', { zone_code: 'COMM-TRIP', rate_bps: 2000 }).then((rule) => {
                cy.make('completed_trip', { zone_code: 'COMM-TRIP' }).then((trip) => {
                    const tripRow = () => cy.sql('select commission_rule_id, commission_amount, driver_earnings_amount, fare_amount from trips where id = ?', [trip.id]).its('0');
                    tripRow().should('deep.equal', { commission_rule_id: rule.id, commission_amount: 298, driver_earnings_amount: 1192, fare_amount: 1490 }); // 20 % of 1,490

                    cy.visit('/admin/commission-rules?filter[search]=Comm Trip');
                    cy.findByRole('button', { name: 'New version of the Comm Trip rule' }).click();
                    cy.findByRole('dialog').within(() => {
                        cy.findByLabelText(/^Rate/).clear().type('50');
                        cy.findByRole('button', { name: 'Save new version' }).click();
                    });
                    cy.findAllByRole('status').last().should('contain', 'Commission rule saved.');
                    cy.contains('tr', 'Comm Trip').should('contain', '50%');

                    rulesOf(zone.id).then((rows) => expect(rows).to.have.length(2));
                    tripRow().should('deep.equal', { commission_rule_id: rule.id, commission_amount: 298, driver_earnings_amount: 1192, fare_amount: 1490 });
                    cy.sql('select rate_bps from commission_rules where id = ?', [rule.id]).its('0.rate_bps').should('eq', 2000);
                });
            });
        });
    });

    // Validation: next to the field, nothing saved.
    it('shows invalid input next to the field and saves nothing', () => {
        cy.make('zone', { name: 'Comm Invalid', code: 'COMM-BAD', lat: 42.4, lng: 45.4 }).then((zone) => {
            cy.visit('/admin/commission-rules');
            cy.findByRole('button', { name: 'New version' }).click();

            cy.findByRole('dialog').within(() => {
                cy.findByLabelText(/^Zone/).select('Comm Invalid');
                cy.findByRole('button', { name: 'Save new version' }).click(); // the rate is empty
                cy.findByLabelText(/^Rate/).closest('div').should('contain', 'Enter a percentage from 0 to 100');

                cy.findByLabelText(/^Rate/).type('101');
                cy.findByLabelText(/^Minimum commission/).type('12.5'); // AMD has no decimals
                cy.findByRole('button', { name: 'Save new version' }).click();
                cy.findByLabelText(/^Rate/).closest('div').should('contain', 'Enter a percentage from 0 to 100');
                cy.findByLabelText(/^Minimum commission/).closest('div').should('contain', 'Enter a valid amount.');

                // the server's own rule: the maximum cannot be below the minimum
                cy.findByLabelText(/^Rate/).clear().type('18');
                cy.findByLabelText(/^Minimum commission/).clear().type('600');
                cy.findByLabelText(/^Maximum commission/).type('500');
                cy.findByRole('button', { name: 'Save new version' }).click();
                cy.findByLabelText(/^Maximum commission/).closest('div').should('contain', 'must be greater than or equal to');
            });

            cy.findByRole('dialog').should('be.visible');
            rulesOf(zone.id).then((rows) => expect(rows).to.have.length(0));
            cy.sql("select count(*)::int as n from activity_log where description like 'commission_rule.%' and properties->'context'->>'zone' = 'COMM-BAD'").its('0.n').should('eq', 0);
        });
    });

    // List filters.
    it('filters the list by vehicle class and by zone search', () => {
        cy.make('zone', { name: 'Comm Filter A', code: 'COMM-FA', lat: 42.6, lng: 45.6 });
        cy.make('zone', { name: 'Comm Filter B', code: 'COMM-FB', lat: 42.8, lng: 45.8 });
        cy.make('commission_rule', { zone_code: 'COMM-FA', vehicle_class: 'economy' });
        cy.make('commission_rule', { zone_code: 'COMM-FB' });
        cy.visit('/admin/commission-rules?filter[search]=Comm Filter');

        cy.get('tbody tr').should('have.length', 2);
        cy.findByLabelText('All classes').select('Every class');
        cy.get('tbody tr').should('have.length', 1).and('contain', 'Comm Filter B');
        cy.location('search').should('contain', 'filter%5Bvehicle_class%5D=all');
        cy.findByLabelText('All classes').select('Economy');
        cy.get('tbody tr').should('have.length', 1).and('contain', 'Comm Filter A');
    });

    // Permission: fares.manage. Support does not have it.
    it('refuses the page and the save to a role without fares.manage, and sends a guest to sign in', () => {
        cy.make('zone', { name: 'Comm Guarded', code: 'COMM-GRD', lat: 43.0, lng: 46.0 }).then((zone) => {
            const body = { zone_id: zone.id, vehicle_class: 'economy', type: 'percentage', rate_bps: 1, fixed_amount: 0, min_commission: 0, max_commission: null, applies_to_surge: true };

            cy.loginAs('cy-commission-support@taxikosmos.test');
            cy.visit('/admin/commission-rules', { failOnStatusCode: false });
            cy.findByRole('heading', { name: "You don't have access" }).should('be.visible');
            cy.request({ url: '/admin/commission-rules', failOnStatusCode: false }).its('status').should('eq', 403);
            cy.adminRequest('POST', '/admin/commission-rules', body).its('status').should('eq', 403);
            rulesOf(zone.id).then((rows) => expect(rows).to.have.length(0));

            Cypress.session.clearAllSavedSessions();
            cy.clearAllCookies();
            cy.request({ url: '/admin/commission-rules', followRedirect: false, failOnStatusCode: false }).then((response) => {
                expect(response.status).to.equal(302);
                expect(response.redirectedToUrl).to.contain('/login');
            });
        });
    });
});

export {};
