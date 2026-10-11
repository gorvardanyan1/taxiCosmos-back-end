/**
 * P5-T3 · City/zone management (admin side).
 * Create / edit / deactivate / reactivate zones with a GeoJSON polygon, polygon validation
 * (ST_IsValid, too few points), unique codes, no hard delete, audit entries and permissions.
 * (The point-in-polygon locator and the zone's local "today" have no HTTP surface yet: they are
 * used by later tasks and covered by PHPUnit.)
 */
const square = (lng: number, lat: number, half = 0.05) => JSON.stringify({
    type: 'Polygon',
    coordinates: [[[lng - half, lat - half], [lng + half, lat - half], [lng + half, lat + half], [lng - half, lat + half], [lng - half, lat - half]]],
});
const bowtie = JSON.stringify({ type: 'Polygon', coordinates: [[[44.4, 40.1], [44.6, 40.3], [44.6, 40.1], [44.4, 40.3], [44.4, 40.1]]] });
const twoPoints = JSON.stringify({ type: 'Polygon', coordinates: [[[44.4, 40.1], [44.6, 40.1], [44.4, 40.1]]] });

const zoneButton = (name: string) => cy.contains('button[aria-pressed]', name);
const zoneRow = (code: string) => cy.sql('select id, name, timezone, currency, priority, is_active, ST_IsValid(polygon) as valid, ST_NPoints(polygon) as points from zones where code = ?', [code]).then((rows) => rows[0]);

describe('P5-T3 · Zones', () => {
    let admin: { id: number };

    before(() => {
        cy.resetDb();
        cy.make('admin', { email: 'cy-zones-admin@taxikosmos.test', role: 'admin', name: 'Zed Zoner' }).then((a) => (admin = a));
        cy.make('admin', { email: 'cy-zones-support@taxikosmos.test', role: 'support' });
    });

    beforeEach(() => cy.loginAs('cy-zones-admin@taxikosmos.test'));

    // Criterion: GET/POST /admin/zones.
    it('creates a zone from a pasted GeoJSON polygon and shows it selected', () => {
        cy.visit('/admin/zones');
        cy.findByRole('heading', { name: 'Zones & Fares' }).should('be.visible');
        cy.intercept('POST', '/admin/zones').as('create');

        cy.findByRole('button', { name: '+ Add Zone' }).click();
        cy.findByRole('dialog', { name: 'Add zone' }).within(() => {
            cy.findByLabelText(/^Name/).type('Gyumri Center');
            cy.findByLabelText(/^Code/).type('gyumri-c').should('have.value', 'GYUMRI-C');
            cy.findByLabelText(/^Timezone/).select('Asia/Yerevan');
            cy.findByLabelText(/^Currency/).select('AMD');
            cy.findByLabelText(/^Priority/).clear().type('5');
            cy.findByLabelText(/^Polygon/).type(square(43.85, 40.79), { delay: 0, parseSpecialCharSequences: false });
            cy.findByRole('button', { name: 'Create zone' }).dblclick();
        });

        cy.wait('@create').its('request.body').should('include', { name: 'Gyumri Center', code: 'GYUMRI-C', timezone: 'Asia/Yerevan', currency: 'AMD', priority: 5 });
        cy.findAllByRole('status').last().should('contain', 'Zone created.');
        cy.findByRole('dialog').should('not.exist');
        zoneButton('Gyumri Center').should('have.attr', 'aria-pressed', 'true').and('contain', 'Active');
        cy.findByRole('img', { name: 'Gyumri Center service area' }).find('polygon').should('have.length', 1);
        cy.contains('Asia/Yerevan · AMD · priority 5').should('be.visible');

        cy.reload();
        zoneButton('Gyumri Center').should('exist');
        zoneRow('GYUMRI-C').then((row) => expect(row).to.include({ name: 'Gyumri Center', timezone: 'Asia/Yerevan', currency: 'AMD', priority: 5, is_active: true, valid: true }));
        cy.sql("select causer_id from activity_log where description = 'zone.created'").then((rows) => {
            expect(rows, 'a double-click creates one zone').to.have.length(1);
            expect(rows[0].causer_id).to.equal(admin.id);
        });
        cy.sql("select count(*)::int as n from zones where code = 'GYUMRI-C'").its('0.n').should('eq', 1);
    });

    // Criterion: invalid polygons (self-intersecting, fewer than 3 points) are rejected with a validation error.
    it('rejects invalid polygons and other bad input next to the field, and creates nothing', () => {
        cy.make('zone', { name: 'Existing Zone', code: 'TAKEN', lat: 40.18, lng: 44.51 });
        cy.visit('/admin/zones');
        cy.findByRole('button', { name: '+ Add Zone' }).click();

        const submitWith = (polygon: string, field: RegExp, message: RegExp, extra: () => void = () => {}) => {
            cy.findByRole('dialog').within(() => {
                cy.findByLabelText(/^Name/).clear().type('Broken Zone');
                cy.findByLabelText(/^Code/).clear().type('BROKEN');
                cy.findByLabelText(/^Polygon/).clear().type(polygon, { delay: 0, parseSpecialCharSequences: false });
                extra();
                cy.findByRole('button', { name: 'Create zone' }).click();
                cy.findByLabelText(field).parent().find('[role=alert]').invoke('text').should('match', message);
            });
        };

        submitWith(bowtie, /^Polygon/, /^Polygon intersects itself\.$/);
        submitWith(twoPoints, /^Polygon/, /at least 3 distinct points/);
        submitWith('{"type":"Polygon"', /^Polygon/, /not valid JSON/);
        submitWith(JSON.stringify({ type: 'Polygon', coordinates: [[[44.4, 40.1], [44.6, 40.1], [44.6, 40.3], [44.4, 40.2]]] }), /^Polygon/, /ring must be closed/);
        submitWith(JSON.stringify({ type: 'Polygon', coordinates: [[[190, 40.1], [44.6, 40.1], [44.6, 40.3], [190, 40.1]]] }), /^Polygon/, /outside the world/);
        submitWith(square(44.0, 40.0), /^Code/, /already been taken/, () => cy.findByLabelText(/^Code/).clear().type('TAKEN'));
        submitWith(square(44.0, 40.0), /^Code/, /2-32 characters/, () => cy.findByLabelText(/^Code/).clear().type('X'));
        submitWith(square(44.0, 40.0), /^Priority/, /between 0 and 1000/, () => cy.findByLabelText(/^Priority/).clear().type('1001'));

        cy.findByRole('dialog').within(() => {
            cy.findByLabelText(/^Name/).clear();
            cy.findByLabelText(/^Code/).clear().type('NONAME');
            cy.findByLabelText(/^Priority/).clear().type('0');
            cy.findByRole('button', { name: 'Create zone' }).click();
            cy.findByLabelText(/^Name/).parent().find('[role=alert]').should('contain', 'required');
        });

        cy.sql("select count(*)::int as n from zones where code in ('BROKEN', 'NONAME', 'X') or name = 'Broken Zone'").its('0.n').should('eq', 0);
        cy.sql("select name from zones where code = 'TAKEN'").its('0.name').should('eq', 'Existing Zone');
    });

    // Criterion: PATCH /admin/zones/{id}; the code never changes.
    it('edits a zone with the form prefilled, and keeps its code', () => {
        cy.make('zone', { name: 'Vanadzor', code: 'VANADZOR', lat: 40.81, lng: 44.49, priority: 1 }).then((zone) => {
            cy.visit(`/admin/zones?zone=${zone.id}`);
            zoneButton('Vanadzor').should('have.attr', 'aria-pressed', 'true');
            cy.findByRole('button', { name: 'Edit zone' }).click();

            cy.findByRole('dialog', { name: 'Edit Vanadzor' }).within(() => {
                cy.findByLabelText(/^Name/).should('have.value', 'Vanadzor').clear().type('Vanadzor City');
                cy.findByLabelText(/^Code/).should('have.value', 'VANADZOR').and('be.disabled');
                cy.findByLabelText(/^Priority/).should('have.value', '1').clear().type('9');
                cy.findByLabelText(/^Polygon/).invoke('val').should('contain', '"type": "MultiPolygon"');
                cy.findByLabelText(/^Polygon/).clear().type(square(44.49, 40.81, 0.08), { delay: 0, parseSpecialCharSequences: false });
                cy.findByRole('button', { name: 'Save changes' }).click();
            });

            cy.findAllByRole('status').last().should('contain', 'Zone updated.');
            cy.reload();
            zoneButton('Vanadzor City').should('have.attr', 'aria-pressed', 'true');
            zoneRow('VANADZOR').then((row) => expect(row).to.include({ name: 'Vanadzor City', priority: 9, valid: true }));
            cy.sql("select ST_XMax(polygon)::numeric(8,2)::text as xmax from zones where code = 'VANADZOR'").its('0.xmax').should('eq', '44.57');

            cy.adminRequest('PATCH', `/admin/zones/${zone.id}`, { code: 'HIJACK' }).its('status').should('eq', 303);
            cy.pageProps(`/admin/zones?zone=${zone.id}`).its('errors.code').should('eq', 'The zone code cannot be changed.');
            zoneRow('VANADZOR').its('id').should('eq', zone.id);
            cy.sql("select count(*)::int as n from activity_log where description = 'zone.updated' and subject_id = ?", [zone.id]).its('0.n').should('eq', 1);
        });
    });

    // Criterion: POST /admin/zones/{id}/deactivate (no hard delete once trips reference a zone), and reactivation.
    it('deactivates and reactivates a zone after confirmation, never deleting it', () => {
        cy.make('zone', { name: 'Dilijan', code: 'DILIJAN', lat: 40.74, lng: 44.86 }).then((zone) => {
            cy.visit(`/admin/zones?zone=${zone.id}`);

            cy.on('window:confirm', (text) => {
                expect(text).to.match(/^(Deactivate|Reactivate) Dilijan\?/);
                return true;
            });

            cy.findByLabelText('Deactivate zone').click();
            cy.findAllByRole('status').last().should('contain', 'Zone deactivated.');
            zoneButton('Dilijan').should('contain', 'Inactive');
            zoneRow('DILIJAN').its('is_active').should('eq', false);

            cy.findByLabelText('Reactivate zone').click();
            cy.findAllByRole('status').last().should('contain', 'Zone activated.');
            zoneButton('Dilijan').should('contain', 'Active').and('not.contain', 'Inactive');
            zoneRow('DILIJAN').its('is_active').should('eq', true);

            cy.sql("select description from activity_log where subject_id = ? and description like 'zone.%' order by id", [zone.id]).then((rows) => {
                expect(rows.map((r) => r.description)).to.deep.equal(['zone.deactivated', 'zone.activated']);
            });

            // Activating an active zone is a conflict, and there is no delete route at all.
            cy.adminRequest('POST', `/admin/zones/${zone.id}/activate`).its('status').should('eq', 302);
            cy.pageProps(`/admin/zones?zone=${zone.id}`).its('flash.error').should('eq', 'This zone is already active.');
            cy.adminRequest('DELETE', `/admin/zones/${zone.id}`).its('status').should('be.oneOf', [404, 405]);
            cy.sql("select count(*)::int as n from zones where code = 'DILIJAN'").its('0.n').should('eq', 1);
        });
    });

    it('cancelling the confirmation leaves the zone active', () => {
        cy.make('zone', { name: 'Sevan', code: 'SEVAN', lat: 40.55, lng: 45.0 }).then((zone) => {
            cy.visit(`/admin/zones?zone=${zone.id}`);
            cy.on('window:confirm', () => false);
            cy.intercept('POST', `/admin/zones/${zone.id}/deactivate`, cy.spy().as('deactivate'));

            cy.findByLabelText('Deactivate zone').click();

            cy.get('@deactivate').should('not.have.been.called');
            zoneButton('Sevan').should('contain', 'Active');
            zoneRow('SEVAN').its('is_active').should('eq', true);
        });
    });

    // Permission: zones.manage. Support does not have it.
    it('refuses the zones page and every zone write to a role without zones.manage', () => {
        cy.make('zone', { name: 'Guarded', code: 'GUARDED' }).then((zone) => {
            cy.loginAs('cy-zones-support@taxikosmos.test');
            cy.visit('/admin/zones', { failOnStatusCode: false });
            cy.findByRole('heading', { name: "You don't have access" }).should('be.visible');

            cy.adminRequest('POST', '/admin/zones', { name: 'X', code: 'XX', timezone: 'Asia/Yerevan', currency: 'AMD', polygon: square(44, 40) }).its('status').should('eq', 403);
            cy.adminRequest('PATCH', `/admin/zones/${zone.id}`, { name: 'Hacked' }).its('status').should('eq', 403);
            cy.adminRequest('POST', `/admin/zones/${zone.id}/deactivate`).its('status').should('eq', 403);

            zoneRow('GUARDED').then((row) => expect(row).to.include({ name: 'Guarded', is_active: true }));
            cy.sql("select count(*)::int as n from zones where code = 'XX'").its('0.n').should('eq', 0);
        });
    });
});

export {};
