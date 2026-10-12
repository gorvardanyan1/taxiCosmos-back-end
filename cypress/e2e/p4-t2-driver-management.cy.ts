/**
 * P4-T2 · Admin driver management: the real Drivers list (search, filters, sorts), the detail page and its tabs,
 * editing (reason required), suspend / reactivate (reason required, refused while on a trip), vehicles,
 * earnings and trips from the trips table, and masked bank accounts that only finance can reveal (every reveal logged).
 * Document review is covered by p2-t3; the earnings ledger itself arrives with P11-T2.
 */
const CODE = '123456';
const driverSession = (phone: string) =>
    cy.api('POST', '/api/v1/auth/otp/request', { phone, app: 'driver' }).its('status').should('eq', 202).then(() =>
        cy.api('POST', '/api/v1/auth/otp/verify', { phone, code: CODE, app: 'driver', device_id: 'cypress-driver-device' }).then((response) => {
            expect(response.status).to.equal(200);
            return response.body.access_token as string;
        }));

interface DriverFixture { driver_id: number; user_id: number; name: string; phone: string; code: string }

const profile = (id: number) => cy.sql('select p.availability, p.license_expiry::text as license_expiry, p.home_zone_id, u.name, u.phone is not null as has_phone, u.status, u.suspension_reason from driver_profiles p join users u on u.id = p.user_id where p.id = ?', [id]).then((rows) => rows[0]);
const audit = (action: string, subject: number) => cy.sql("select causer_id, properties->>'reason' as reason, properties::text as properties, attribute_changes::text as changes, attribute_changes->'attributes'->>'license_number' as license_change from activity_log where description = ? and subject_id = ? order by id", [action, subject]);
const rowOf = (name: string) => cy.contains('tbody tr', name);

describe('P4-T2 · Driver management', () => {
    let support: { id: number };
    let finance: { id: number };

    before(() => {
        cy.resetDb();
        cy.make('admin', { email: 'cy-drivers-support@taxikosmos.test', role: 'support', name: 'Sam Support' }).then((a) => (support = a));
        cy.make('admin', { email: 'cy-drivers-finance@taxikosmos.test', role: 'finance', name: 'Fiona Finance' }).then((a) => (finance = a));
        cy.make('admin', { email: 'cy-drivers-dispatch@taxikosmos.test', role: 'dispatcher', name: 'Dan Dispatch' });
        cy.make('zone', { name: 'Drivers North', code: 'DRV-N', lat: 41.0, lng: 44.0 });
        cy.make('zone', { name: 'Drivers South', code: 'DRV-S', lat: 39.0, lng: 44.0 });
    });

    beforeEach(() => cy.make('clear_rate_limits'));

    // Criterion: GET /admin/drivers (paginated, filterable).
    it('lists real drivers with their vehicle, status, availability, rating, trips and earnings', () => {
        cy.loginAs('cy-drivers-support@taxikosmos.test');
        cy.make('driver', {
            name: 'Lia Listed', phone: '+37491550001', zone_code: 'DRV-N', verification_status: 'approved', availability: 'online', rating: 4.5,
            vehicles: [{ make: 'Toyota', model: 'Camry', plate: 'LIA1', primary: true }], completed_trips: [120000, 80000],
        });

        cy.visit('/admin/drivers?filter[search]=Lia Listed');

        rowOf('Lia Listed').should('contain', 'Toyota Camry').and('contain', 'Approved').and('contain', 'Online').and('contain', '4.5').and('contain', 'AMD 200,000').and('contain', '2');
        cy.contains('1 driver').should('be.visible');
    });

    it('filters by verification status, availability, zone, vehicle class and expiring documents, and keeps them in the URL', () => {
        cy.loginAs('cy-drivers-support@taxikosmos.test');
        const soon = new Date(Date.now() + 5 * 86_400_000).toISOString().slice(0, 10);
        cy.make('driver', { name: 'Fil Alpha', zone_code: 'DRV-N', verification_status: 'approved', availability: 'online', vehicles: [{ make: 'Kia', model: 'Forte', plate: 'FIL1', class: 'comfort', primary: true }], document_expires: soon });
        cy.make('driver', { name: 'Fil Beta', zone_code: 'DRV-S', verification_status: 'rejected', availability: 'offline', vehicles: [{ make: 'Skoda', model: 'Octavia', plate: 'FIL2', class: 'economy', primary: true }] });

        cy.visit('/admin/drivers?filter[search]=Fil');
        cy.get('tbody tr').should('have.length', 2);

        cy.findByLabelText('All Statuses').select('Approved');
        cy.location('search').should('contain', 'filter%5Bverification_status%5D=approved');
        cy.get('tbody tr').should('have.length', 1).and('contain', 'Fil Alpha');
        cy.findByLabelText('All Statuses').select('All Statuses');

        cy.findByLabelText('Any availability').select('Offline');
        cy.get('tbody tr').should('have.length', 1).and('contain', 'Fil Beta');
        cy.findByLabelText('Any availability').select('Any availability');

        cy.findByLabelText('All zones').select('Drivers North');
        cy.get('tbody tr').should('have.length', 1).and('contain', 'Fil Alpha');
        cy.findByLabelText('All zones').select('All zones');

        cy.findByLabelText('All vehicle classes').select('Economy');
        cy.get('tbody tr').should('have.length', 1).and('contain', 'Fil Beta');
        cy.findByLabelText('All vehicle classes').select('All vehicle classes');

        cy.findByLabelText('Documents expiring').select('Documents expiring within 7 days');
        cy.get('tbody tr').should('have.length', 1).and('contain', 'Fil Alpha');

        cy.reload();
        cy.findByLabelText('Documents expiring').should('have.value', '7');
        cy.get('tbody tr').should('have.length', 1).and('contain', 'Fil Alpha');
    });

    it('finds a driver by exact license number, phone and code, and by name', () => {
        cy.loginAs('cy-drivers-support@taxikosmos.test');
        cy.make<DriverFixture>('driver', { name: 'Sea Searchable', phone: '+37491550002', license_number: 'AM-DL 7788' }).then((driver) => {
            cy.visit('/admin/drivers');
            const search = (text: string) => cy.findByPlaceholderText(/Search by name, phone, code or exact license/).clear().type(`${text}{enter}`);

            search('am-dl7788');
            rowOf('Sea Searchable').should('be.visible');
            search('am-dl77');
            cy.contains('No drivers found').should('be.visible');
            search('091 550 002');
            rowOf('Sea Searchable').should('be.visible');
            search(driver.code);
            rowOf('Sea Searchable').should('be.visible');
            search('searchab');
            rowOf('Sea Searchable').should('be.visible');
        });
    });

    it('sorts by name and by trips', () => {
        cy.loginAs('cy-drivers-support@taxikosmos.test');
        // Newest first is the default order, so the newest driver (Zed, three trips) leads until a sort is chosen.
        cy.make('driver', { name: 'Sor Amy', completed_trips: [1000] });
        cy.make('driver', { name: 'Sor Zed', completed_trips: [1000, 1000, 1000] });
        cy.visit('/admin/drivers?filter[search]=Sor');
        cy.get('tbody tr').first().should('contain', 'Sor Zed');

        cy.get('thead').contains('button', 'Driver').click();
        cy.location('search').should('contain', 'sort=name').and('not.contain', 'sort=-name');
        cy.get('tbody tr').first().should('contain', 'Sor Amy');
        cy.get('thead').contains('button', 'Driver').click();
        cy.location('search').should('contain', 'sort=-name');
        cy.get('tbody tr').first().should('contain', 'Sor Zed');
        cy.get('thead').contains('button', 'Trips').click();
        cy.location('search').should('contain', 'sort=trips_count').and('not.contain', 'sort=-trips_count');
        cy.get('tbody tr').first().should('contain', 'Sor Amy');
        cy.get('thead').contains('button', 'Trips').click();
        cy.location('search').should('contain', 'sort=-trips_count');
        cy.get('tbody tr').first().should('contain', 'Sor Zed');
    });

    // Criterion: GET /admin/drivers/{id} and the vehicles URL.
    it('opens a driver from the list and deep-links to the vehicles tab', () => {
        cy.loginAs('cy-drivers-support@taxikosmos.test');
        cy.make<DriverFixture>('driver', { name: 'Dee Detail', zone_code: 'DRV-N', license_number: 'AM-DL 9090', license_expiry: '2028-05-01', vehicles: [{ make: 'Kia', model: 'Rio', plate: 'DEE1', primary: true }] }).then((driver) => {
            cy.visit('/admin/drivers?filter[search]=Dee Detail');
            cy.contains('a', 'Dee Detail').click();
            cy.location('pathname').should('eq', `/admin/drivers/${driver.driver_id}`);
            cy.findByRole('heading', { name: 'Dee Detail' }).should('be.visible');
            cy.contains('License').parent().should('contain', '•••• 9090').and('contain', 'May 1, 2028');
            cy.contains('Home zone').parent().should('contain', 'Drivers North');
            cy.get('body').invoke('text').should('not.contain', 'AM-DL');

            cy.visit(`/admin/drivers/${driver.driver_id}/vehicles`);
            cy.findByRole('tab', { name: 'Vehicles' }).should('have.attr', 'aria-selected', 'true');
            cy.contains('Kia Rio · 2').should('be.visible');
            cy.visit('/admin/drivers/999999', { failOnStatusCode: false });
            cy.findByRole('heading', { name: /not found/i }).should('be.visible');
        });
    });

    // Criterion: PATCH /admin/drivers/{id} (reason required).
    it('edits contact and licence details with a reason, saved after a reload and audited without the sensitive values', () => {
        cy.loginAs('cy-drivers-support@taxikosmos.test');
        cy.make<DriverFixture>('driver', { name: 'Ed Editable', phone: '+37491550003', license_number: 'AM-DL 4242', license_expiry: '2028-05-01' }).then((driver) => {
            cy.visit(`/admin/drivers/${driver.driver_id}`);
            cy.intercept('PATCH', `/admin/drivers/${driver.driver_id}`).as('save');

            cy.findByRole('button', { name: 'Edit' }).click();
            cy.findByRole('dialog', { name: 'Edit Ed Editable' }).within(() => {
                cy.findByRole('button', { name: 'Save changes' }).should('be.disabled');
                cy.findByLabelText('Name').clear().type('Ed Edited');
                cy.findByLabelText('Phone').clear().type('091 550 004');
                cy.findByLabelText('License number').type('AM-DL 5555');
                cy.findByLabelText('License expiry').type('2030-01-31');
                cy.findByRole('button', { name: 'Save changes' }).should('be.disabled'); // no reason yet
                cy.findByLabelText(/Reason for the change/).type('Driver renewed their licence');
                cy.findByRole('button', { name: 'Save changes' }).click();
            });

            cy.wait('@save').its('request.body').should('deep.equal', { name: 'Ed Edited', phone: '091 550 004', license_number: 'AM-DL 5555', license_expiry: '2030-01-31', reason: 'Driver renewed their licence' });
            cy.findAllByRole('status').last().should('contain', 'Driver updated.');
            cy.reload();
            cy.findByRole('heading', { name: 'Ed Edited' }).should('be.visible');
            cy.contains('License').parent().should('contain', '•••• 5555').and('contain', 'Jan 31, 2030');
            profile(driver.driver_id).then((row) => expect(row).to.include({ name: 'Ed Edited', license_expiry: '2030-01-31' }));
            cy.sql('select phone_hash is not null as ok from users where id = ?', [driver.user_id]).its('0.ok').should('eq', true);
            audit('driver.updated', driver.driver_id).then((rows) => {
                expect(rows).to.have.length(1);
                expect(rows[0].causer_id).to.equal(support.id);
                const text = `${rows[0].properties} ${rows[0].changes}`;
                expect(rows[0].reason).to.equal('Driver renewed their licence');
                expect(text).to.contain('Ed Edited');
                ['AM-DL', '4242', '5555', '550003', '550004'].forEach((secret) => expect(text, secret).not.to.contain(secret));
                expect(rows[0].license_change).to.equal('changed');
            });
        });
    });

    it('shows a duplicate phone next to the field and changes nothing', () => {
        cy.loginAs('cy-drivers-support@taxikosmos.test');
        cy.make('driver', { name: 'Dup Owner', phone: '+37491550005' });
        cy.make<DriverFixture>('driver', { name: 'Dup Wanter', phone: '+37491550006' }).then((driver) => {
            cy.visit(`/admin/drivers/${driver.driver_id}`);
            cy.findByRole('button', { name: 'Edit' }).click();
            cy.findByRole('dialog').within(() => {
                cy.findByLabelText('Phone').clear().type('+37491550005');
                cy.findByLabelText(/Reason for the change/).type('Trying');
                cy.findByRole('button', { name: 'Save changes' }).click();
                cy.findByLabelText('Phone').closest('div').should('contain', 'Another account already uses this phone number.');
            });
            cy.findByRole('dialog').should('be.visible');
            cy.sql('select phone_hash from users where id = ?', [driver.user_id]).then(() => audit('driver.updated', driver.driver_id).should('have.length', 0));
        });
    });

    // Criterion: POST /admin/drivers/{id}/suspend and /reactivate (reason required).
    it('suspends with a reason: offline, signed out of the app, audited; a double-click is one suspension; then reactivates', () => {
        cy.loginAs('cy-drivers-support@taxikosmos.test');
        cy.make<DriverFixture>('driver', { name: 'Sue Suspend', phone: '+37491770039', availability: 'online' }).then((driver) => {
            driverSession(driver.phone).then((token) => {
                cy.visit(`/admin/drivers/${driver.driver_id}`);
                cy.intercept('POST', `/admin/drivers/${driver.driver_id}/suspend`).as('suspend');

                cy.findByRole('button', { name: 'Suspend' }).click();
                cy.findByRole('button', { name: 'Confirm Suspension' }).should('be.disabled');
                cy.findByLabelText(/Reason/).type('Repeated complaints');
                cy.findByRole('button', { name: 'Confirm Suspension' }).dblclick();

                cy.wait('@suspend').its('response.statusCode').should('eq', 302);
                cy.findAllByRole('status').last().should('contain', 'Driver suspended.');
                cy.contains('Suspended: Repeated complaints').should('be.visible');
                profile(driver.driver_id).then((row) => expect(row).to.include({ status: 'suspended', suspension_reason: 'Repeated complaints', availability: 'offline' }));
                cy.api('POST', '/api/v1/auth/logout', {}, token).its('status').should('eq', 401);
                audit('driver.suspended', driver.driver_id).then((rows) => {
                    expect(rows, 'a double-click suspends once').to.have.length(1);
                    expect(rows[0].causer_id).to.equal(support.id);
                    expect(rows[0].reason).to.equal('Repeated complaints');
                });

                cy.visit('/admin/drivers?filter[search]=Sue Suspend');
                rowOf('Sue Suspend').should('contain', 'Suspended');

                cy.visit(`/admin/drivers/${driver.driver_id}`);
                cy.findByRole('button', { name: 'Reactivate' }).click();
                cy.findByLabelText(/Reason/).type('Appeal accepted');
                cy.findByRole('button', { name: 'Confirm Reactivation' }).click();
                cy.findAllByRole('status').last().should('contain', 'Driver reactivated.');
                profile(driver.driver_id).then((row) => expect(row).to.include({ status: 'active', availability: 'offline' }));
                audit('driver.reactivated', driver.driver_id).its('0.reason').should('eq', 'Appeal accepted');
            });
        });
    });

    it('suspends from the list menu', () => {
        cy.loginAs('cy-drivers-support@taxikosmos.test');
        cy.make<DriverFixture>('driver', { name: 'Men Menu' }).then((driver) => {
            cy.visit('/admin/drivers?filter[search]=Men Menu');
            cy.findByLabelText('Actions for Men Menu').click();
            cy.findByRole('button', { name: /Suspend/ }).click();
            cy.findByLabelText(/Reason/).type('Failed vehicle inspection');
            cy.findByRole('button', { name: 'Confirm Suspension' }).click();

            cy.findAllByRole('status').last().should('contain', 'Driver suspended.');
            rowOf('Men Menu').should('contain', 'Suspended');
            profile(driver.driver_id).its('status').should('eq', 'suspended');
        });
    });

    it('refuses to suspend a driver who is on a trip, with a clear message, and changes nothing', () => {
        cy.loginAs('cy-drivers-support@taxikosmos.test');
        cy.make<DriverFixture>('driver', { name: 'Tom Trip', availability: 'on_trip', live_trip: 'in_progress' }).then((driver) => {
            cy.visit(`/admin/drivers/${driver.driver_id}`);

            cy.findByRole('button', { name: 'Suspend' }).click();
            cy.findByLabelText(/Reason/).type('Complaints');
            cy.findByRole('button', { name: 'Confirm Suspension' }).click();

            cy.findAllByRole('status').last().should('contain', 'This driver is on a trip. Force-cancel or reassign the trip first.');
            profile(driver.driver_id).then((row) => expect(row).to.include({ status: 'active', availability: 'on_trip' }));
            audit('driver.suspended', driver.driver_id).should('have.length', 0);
        });
    });

    // Criterion: vehicles (GET list, POST add, PATCH edit, set-primary).
    it('adds a vehicle, which becomes primary, and rejects a duplicate plate next to the field', () => {
        cy.loginAs('cy-drivers-support@taxikosmos.test');
        cy.make<DriverFixture>('driver', { name: 'Vee Vehicles' }).then((driver) => {
            cy.visit(`/admin/drivers/${driver.driver_id}/vehicles`);
            cy.contains('No vehicles yet').should('be.visible');
            cy.intercept('POST', `/admin/drivers/${driver.driver_id}/vehicles`).as('add');

            cy.findByRole('button', { name: '+ Add vehicle' }).click();
            cy.findByRole('dialog', { name: 'Add vehicle' }).within(() => {
                cy.findByLabelText(/^Make/).type('Toyota');
                cy.findByLabelText(/^Model/).type('Camry');
                cy.findByLabelText(/^Color/).type('White');
                cy.findByLabelText(/^Plate number/).type('11 aa 111');
                cy.findByLabelText(/^Vehicle class/).select('Comfort');
                cy.findByRole('button', { name: 'Add vehicle' }).click();
            });

            cy.wait('@add').its('request.body').should('include', { make: 'Toyota', model: 'Camry', plate_number: '11 AA 111', vehicle_class: 'comfort', status: 'active' });
            cy.findAllByRole('status').last().should('contain', 'Vehicle added.');
            cy.contains('Toyota Camry').should('be.visible');
            cy.contains('Primary').should('be.visible');
            cy.sql('select plate_number, status, is_primary, vehicle_class from vehicles where driver_id = ?', [driver.driver_id]).then((rows) => {
                expect(rows).to.have.length(1);
                expect(rows[0]).to.include({ plate_number: '11AA111', status: 'active', is_primary: true, vehicle_class: 'comfort' });
            });

            cy.findByRole('button', { name: '+ Add vehicle' }).click();
            cy.findByRole('dialog').within(() => {
                cy.findByLabelText(/^Make/).type('Kia');
                cy.findByLabelText(/^Model/).type('Rio');
                cy.findByLabelText(/^Color/).type('Red');
                cy.findByLabelText(/^Plate number/).type('11AA111');
                cy.findByRole('button', { name: 'Add vehicle' }).click();
                cy.findByLabelText(/^Plate number/).closest('div').should('contain', 'Another vehicle already has this plate number.');
            });
            cy.sql('select count(*)::int as n from vehicles where driver_id = ?', [driver.driver_id]).its('0.n').should('eq', 1);
            audit('driver.vehicle.added', driver.driver_id).should('have.length', 0); // the vehicle is the audit subject, not the driver
        });
    });

    it('edits a vehicle, makes another primary, and a deactivated primary stops being primary', () => {
        cy.loginAs('cy-drivers-support@taxikosmos.test');
        cy.make<DriverFixture>('driver', { name: 'Pri Primary', vehicles: [{ make: 'Toyota', model: 'Camry', plate: 'PRI1', primary: true }, { make: 'Kia', model: 'Forte', plate: 'PRI2' }, { make: 'Skoda', model: 'Octavia', plate: 'PRI3', status: 'pending_review' }] }).then((driver) => {
            cy.visit(`/admin/drivers/${driver.driver_id}/vehicles`);
            const flag = (plate: string) => cy.sql('select is_primary, status, color from vehicles where driver_id = ? and plate_number = ?', [driver.driver_id, plate]).then((rows) => rows[0]);

            cy.findAllByRole('button', { name: 'Set as primary' }).should('have.length', 1); // the pending vehicle cannot be primary
            cy.findByRole('button', { name: 'Edit Kia Forte' }).click();
            cy.findByRole('dialog', { name: 'Edit Kia Forte' }).within(() => {
                cy.findByLabelText(/^Color/).clear().type('Green');
                cy.findByRole('button', { name: 'Save changes' }).click();
            });
            cy.findAllByRole('status').last().should('contain', 'Vehicle updated.');
            flag('PRI2').its('color').should('eq', 'Green');

            cy.findByRole('button', { name: 'Set as primary' }).click();
            cy.findAllByRole('status').last().should('contain', 'Primary vehicle changed.');
            flag('PRI2').its('is_primary').should('eq', true);
            flag('PRI1').its('is_primary').should('eq', false);

            cy.findByRole('button', { name: 'Edit Kia Forte' }).click();
            cy.findByRole('dialog').within(() => {
                cy.findByLabelText(/^Status/).select('Inactive');
                cy.findByRole('button', { name: 'Save changes' }).click();
            });
            cy.findAllByRole('status').last().should('contain', 'Vehicle updated.');
            flag('PRI2').then((row) => expect(row).to.include({ is_primary: false, status: 'inactive' }));
            cy.sql('select count(*)::int as n from vehicles where driver_id = ? and is_primary', [driver.driver_id]).its('0.n').should('eq', 0);
            cy.sql("select count(*)::int as n from activity_log where description in ('driver.vehicle.updated', 'driver.vehicle.primary_set') and properties->'context'->>'driver' = ?", [driver.code]).its('0.n').should('eq', 3);
        });
    });

    // Criterion: Earnings tab (from completed trips until the ledger exists) and trips.
    it('shows the earnings from completed trips with a note about the pending ledger, and the trip history', () => {
        cy.loginAs('cy-drivers-finance@taxikosmos.test');
        cy.make<DriverFixture>('driver', { name: 'Ern Earnings', zone_code: 'DRV-N', completed_trips: [120000, 80000], live_trip: 'matched' }).then((driver) => {
            cy.visit(`/admin/drivers/${driver.driver_id}?tab=earnings`);

            cy.contains('Current balance').parent().should('contain', 'AMD 200,000');
            cy.contains('This week').parent().should('contain', 'AMD 200K');
            cy.contains('Showing earnings from completed trips').should('be.visible');
            cy.contains('trip_earning', { matchCase: false }).should('not.exist');
            cy.findAllByText('Trip earning').should('have.length', 2);
            cy.contains('+ AMD 120,000').should('be.visible');
            cy.findByRole('button', { name: 'Add adjustment' }).should('be.disabled');

            cy.findByRole('tab', { name: 'Trip History' }).click();
            cy.get('main').contains('a', /^TK-/).should('have.length.at.least', 1);
            cy.contains('Matched').should('be.visible');
            cy.contains('Completed').should('be.visible');
        });
    });

    // Criterion: bank accounts masked; full numbers only for finance, with an activity-log entry per reveal.
    it('shows bank accounts masked and lets only finance reveal the full number, logging every reveal', () => {
        const number = 'AM1234567890 4821';
        cy.make<DriverFixture>('driver', { name: 'Ban Banker', bank_account: { bank_name: 'Ameriabank', account_number: number } }).then((driver) => {
            // support: masked, no way to reveal
            cy.loginAs('cy-drivers-support@taxikosmos.test');
            cy.visit(`/admin/drivers/${driver.driver_id}?tab=bank-accounts`);
            cy.contains('•••• 4821').should('be.visible');
            cy.findByRole('button', { name: /Reveal account/ }).should('be.disabled');
            cy.get('body').invoke('html').should('not.contain', '12345678');
            cy.sql('select id from driver_bank_accounts where driver_id = ?', [driver.driver_id]).its('0.id').then((accountId) => {
                cy.adminRequest('POST', `/admin/drivers/${driver.driver_id}/bank-accounts/${accountId}/reveal`).its('status').should('eq', 403);
                cy.sql("select count(*)::int as n from activity_log where description = 'driver.bank_account.revealed'").its('0.n').should('eq', 0);

                // finance: reveals, logged each time
                cy.loginAs('cy-drivers-finance@taxikosmos.test');
                cy.visit(`/admin/drivers/${driver.driver_id}?tab=bank-accounts`);
                cy.get('body').invoke('html').should('not.contain', '12345678');
                cy.intercept('POST', `/admin/drivers/${driver.driver_id}/bank-accounts/${accountId}/reveal`).as('reveal');

                cy.findByRole('button', { name: /Reveal account/ }).click();
                cy.wait('@reveal').then(({ response }) => {
                    expect(response?.statusCode).to.equal(200);
                    expect(response?.headers['cache-control']).to.contain('no-store');
                    expect(response?.body).to.deep.equal({ account_number: 'AM12345678904821' });
                });
                cy.findByTestId('revealed-account-number').should('have.text', 'AM12345678904821');
                cy.findByRole('button', { name: 'Hide' }).click();
                cy.findByTestId('revealed-account-number').should('not.exist');
                cy.findByRole('button', { name: /Reveal account/ }).click();
                cy.wait('@reveal');
                cy.findByTestId('revealed-account-number').should('be.visible');

                cy.sql("select causer_id, properties->'context'->>'account_last4' as last4, properties::text as text from activity_log where description = 'driver.bank_account.revealed' and subject_id = ? order by id", [accountId]).then((rows) => {
                    expect(rows, 'one entry per reveal').to.have.length(2);
                    rows.forEach((row) => {
                        expect(row.causer_id).to.equal(finance.id);
                        expect(row.last4).to.equal('4821');
                        expect(row.text).not.to.contain('12345678');
                    });
                });
            });
        });
    });

    // A deleted account keeps its records for review, but cannot be edited, suspended or reactivated.
    it('offers no edit or suspend for a driver whose account was deleted, and refuses direct requests', () => {
        cy.loginAs('cy-drivers-support@taxikosmos.test');
        cy.make<DriverFixture>('driver', { name: 'Dee Deleted', phone: '091 660 001', deleted: true }).then((driver) => {
            cy.visit(`/admin/drivers/${driver.driver_id}`);
            cy.contains('Deleted account').should('be.visible');
            cy.findByRole('button', { name: 'Edit' }).should('be.disabled');
            cy.findByRole('button', { name: /^(Suspend|Reactivate)$/ }).should('not.exist');

            const message = "This driver's account was deleted. It can no longer be changed.";
            for (const [method, path, body] of [
                ['PATCH', '', { name: 'Revived Name', reason: 'x' }],
                ['POST', '/suspend', { reason: 'x' }],
                ['POST', '/reactivate', { reason: 'x' }],
            ] as const) {
                // The Inertia request is sent back to the page with the refusal as its error flash.
                cy.adminRequest(method, `/admin/drivers/${driver.driver_id}${path}`, body).its('status').should('be.oneOf', [302, 303]);
                cy.pageProps(`/admin/drivers/${driver.driver_id}`).its('flash.error').should('eq', message);
            }

            cy.sql('select u.name, u.status, u.deleted_at is not null as deleted from driver_profiles p join users u on u.id = p.user_id where p.id = ?', [driver.driver_id])
                .its('0').should('include', { name: 'Dee Deleted', status: 'active', deleted: true });
            cy.sql("select count(*)::int as n from activity_log where description like 'driver.%' and subject_id = ?", [driver.driver_id]).its('0.n').should('eq', 0);
        });
    });

    // Permissions: drivers.view only (dispatcher) sees the pages but no write actions; direct writes are 403; a guest signs in first.
    it('gives a view-only role no write actions and refuses its direct requests', () => {
        cy.make<DriverFixture>('driver', { name: 'Vio View', vehicles: [{ make: 'Kia', model: 'Rio', plate: 'VIO1', primary: true }] }).then((driver) => {
            cy.loginAs('cy-drivers-dispatch@taxikosmos.test');
            cy.visit(`/admin/drivers/${driver.driver_id}`);
            cy.findByRole('button', { name: 'Edit' }).should('be.disabled');
            cy.findByRole('button', { name: /^(Suspend|Reactivate)$/ }).should('not.exist');
            cy.visit(`/admin/drivers/${driver.driver_id}?tab=vehicles`);
            cy.findByRole('button', { name: '+ Add vehicle' }).should('be.disabled');
            cy.findByRole('button', { name: 'Edit Kia Rio' }).should('be.disabled');
            cy.visit('/admin/drivers?filter[search]=Vio View');
            cy.findByLabelText('Actions for Vio View').click();
            cy.findByRole('button', { name: /View Detail/ }).should('be.visible');
            cy.findByRole('button', { name: /Suspend/ }).should('not.exist');

            const vehicleBody = { make: 'X', model: 'Y', year: 2020, color: 'Z', plate_number: 'HACK1', vehicle_class: 'economy' };
            cy.adminRequest('PATCH', `/admin/drivers/${driver.driver_id}`, { name: 'Hacked', reason: 'x' }).its('status').should('eq', 403);
            cy.adminRequest('POST', `/admin/drivers/${driver.driver_id}/suspend`, { reason: 'x' }).its('status').should('eq', 403);
            cy.adminRequest('POST', `/admin/drivers/${driver.driver_id}/reactivate`, { reason: 'x' }).its('status').should('eq', 403);
            cy.adminRequest('POST', `/admin/drivers/${driver.driver_id}/vehicles`, vehicleBody).its('status').should('eq', 403);
            profile(driver.driver_id).then((row) => expect(row).to.include({ name: 'Vio View', status: 'active' }));
            cy.sql('select count(*)::int as n from vehicles where driver_id = ?', [driver.driver_id]).its('0.n').should('eq', 1);

            Cypress.session.clearAllSavedSessions();
            cy.clearAllCookies();
            cy.request({ url: `/admin/drivers/${driver.driver_id}`, followRedirect: false, failOnStatusCode: false }).then((response) => {
                expect(response.status).to.equal(302);
                expect(response.redirectedToUrl).to.contain('/login');
            });
        });
    });
});

export {};
