/**
 * P7-T5 · Support tickets: the rider and driver apps' /api/v1/support-tickets (over HTTP, with real OTP sign-ins) and the admin
 * Support Tickets page (in the browser, signed in through the real login form). Mail is written to the e2e log (MAIL_MAILER=log) and
 * the queue is synchronous, so the safety alert is read from that log. Refund (P6-T7) and fare adjustment (P7-T2) are not built yet:
 * their buttons are present for the right permissions but disabled.
 */
const CODE = '123456';
const SUPPORT = 'cypress-t5-support@taxikosmos.test';
const COLLEAGUE = 'cypress-t5-colleague@taxikosmos.test';
const FINANCE = 'cypress-t5-finance@taxikosmos.test';
const DISPATCHER = 'cypress-t5-dispatcher@taxikosmos.test';
const BOSS = 'cypress-t5-admin@taxikosmos.test';

interface Person { id: number; phone: string }

const signIn = (phone: string, app: 'rider' | 'driver' = 'rider') =>
    cy.api('POST', '/api/v1/auth/otp/request', { phone, app }).its('status').should('eq', 202).then(() =>
        cy.api('POST', '/api/v1/auth/otp/verify', { phone, code: CODE, app, device_id: `cypress-t5-${app}` }).then((response) => {
            expect(response.status, `sign-in of ${phone}`).to.equal(200);
            return response.body.access_token as string;
        }));
/** A mobile request that can carry an Idempotency-Key. */
const mobile = (method: string, path: string, token: string | null, body?: Record<string, unknown>, key?: string) =>
    cy.request({
        method, url: path, body, failOnStatusCode: false,
        headers: { Accept: 'application/json', ...(token ? { Authorization: `Bearer ${token}` } : {}), ...(key ? { 'Idempotency-Key': key } : {}) },
    });
const ticketRows = (where = 'true', bindings: unknown[] = []) => cy.sql(`select id, code, requester_id, requester_type, trip_id, subject, category, priority, status, assigned_to, resolution, resolved_at is not null as resolved from support_tickets where ${where} order by id`, bindings);
const messages = (ticketId: number) => cy.sql('select author_id, body, is_internal_note from support_ticket_messages where ticket_id = ? order by id', [ticketId]);
const audit = (action: string, ticketId: number) => cy.sql("select causer_id, properties->>'reason' as reason from activity_log where description = ? and subject_id = ? order by id", [action, ticketId]);
const openTicket = (id: number) => cy.visit(`/admin/support-tickets?ticket=${id}&lang=en`);
/** An admin write the way a script would send it: JSON, so a refusal is a real 409 / 422 instead of a redirect with a flash message. */
const adminJson = (method: string, path: string, body: Record<string, unknown>) =>
    cy.getCookie('XSRF-TOKEN').then((cookie) => cy.request({ method, url: path, body, failOnStatusCode: false, headers: { Accept: 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(cookie?.value ?? '') } }));
const queueItem = (subject: string) => cy.findByRole('button', { name: new RegExp(subject) });

describe('P7-T5 · Support tickets', () => {
    let rider: Person;
    let otherRider: Person;
    let driver: Person;
    let riderToken: string;
    let otherToken: string;
    let driverToken: string;
    let support: { id: number };

    before(() => {
        cy.resetDb();
        cy.make('admin', { email: SUPPORT, role: 'support', name: 'Sam Support' }).then((a) => (support = a));
        cy.make('admin', { email: COLLEAGUE, role: 'support', name: 'Casey Colleague' });
        cy.make('admin', { email: BOSS, role: 'admin', name: 'Bo Boss' });
        cy.make('admin', { email: FINANCE, role: 'finance', name: 'Fin Finance' });
        cy.make('admin', { email: DISPATCHER, role: 'dispatcher', name: 'Dee Dispatcher' });
        cy.make('rider', { name: 'Ana Rider', phone: '+37491770025' }).then((r) => (rider = r));
        cy.make('rider', { name: 'Olga Other', phone: '+37491770026' }).then((r) => (otherRider = r));
        cy.make('driver', { name: 'Dan Driver', phone: '+37491770027' }).then((d) => (driver = { id: d.user_id, phone: '+37491770027' }));
        signIn('+37491770025').then((token) => (riderToken = token));
        signIn('+37491770026').then((token) => (otherToken = token));
        signIn('+37491770027', 'driver').then((token) => (driverToken = token));
    });

    beforeEach(() => {
        Cypress.session.clearAllSavedSessions();
        cy.clearAllCookies();
        cy.make('clear_rate_limits');
    });

    // ---- mobile API ---------------------------------------------------------------------------------------------------

    // Criterion: Mobile POST /api/v1/support-tickets.
    it('opens a ticket from the rider app: 201, the stored row and first message, the app taken from the token', () => {
        mobile('POST', '/api/v1/support-tickets', riderToken, { category: 'fare', subject: 'Charged twice', message: 'My card was charged two times.', requester_type: 'driver' }).then((response) => {
            expect(response.status).to.equal(201);
            expect(response.body.data).to.include({ subject: 'Charged twice', category: 'fare', priority: 'normal', status: 'open', resolution: null });
            expect(response.body.data.code).to.match(/^TKT-\d{5}$/);
            ticketRows('id = ?', [response.body.data.id]).then((rows) => expect(rows[0]).to.include({ requester_id: rider.id, requester_type: 'rider', category: 'fare', priority: 'normal', status: 'open', assigned_to: null }));
            messages(response.body.data.id).then((rows) => expect(rows).to.deep.equal([{ author_id: rider.id, body: 'My card was charged two times.', is_internal_note: false }]));
        });
    });

    it('opens a ticket from the driver app as a driver ticket', () => {
        mobile('POST', '/api/v1/support-tickets', driverToken, { category: 'app_issue', subject: 'App crashes', message: 'At checkout.' }).then((response) => {
            expect(response.status).to.equal(201);
            ticketRows('id = ?', [response.body.data.id]).then((rows) => expect(rows[0]).to.include({ requester_id: driver.id, requester_type: 'driver' }));
        });
    });

    it('refuses a missing token, bad fields and a trip that is not the caller\'s own, and saves nothing', () => {
        ticketRows('subject like ?', ['Invalid %']).then((before) => {
            mobile('POST', '/api/v1/support-tickets', null, { category: 'fare', subject: 'Invalid a', message: 'x' }).its('status').should('eq', 401);
            mobile('POST', '/api/v1/support-tickets', 'junk', { category: 'fare', subject: 'Invalid b', message: 'x' }).its('status').should('eq', 401);
            mobile('POST', '/api/v1/support-tickets', riderToken, {}).then((r) => { expect(r.status).to.equal(422); expect(r.body.errors).to.have.keys('category', 'subject', 'message'); });
            mobile('POST', '/api/v1/support-tickets', riderToken, { category: 'refund_me', subject: 'Invalid c', message: 'x' }).then((r) => { expect(r.status).to.equal(422); expect(r.body.errors).to.have.property('category'); });
            mobile('POST', '/api/v1/support-tickets', riderToken, { category: 'fare', subject: 'ab', message: 'x' }).its('body.errors').should('have.property', 'subject');
            mobile('POST', '/api/v1/support-tickets', riderToken, { category: 'fare', subject: 'a'.repeat(161), message: 'x' }).its('body.errors').should('have.property', 'subject');
            mobile('POST', '/api/v1/support-tickets', riderToken, { category: 'fare', subject: 'Invalid d', message: 'a'.repeat(5001) }).its('body.errors').should('have.property', 'message');
            cy.make('support_ticket', { requester_id: otherRider.id, subject: 'Their ticket', with_trip: true }).then((theirs) => {
                mobile('POST', '/api/v1/support-tickets', riderToken, { category: 'fare', subject: 'Invalid e', message: 'x', trip_id: theirs.trip_id }).then((r) => { expect(r.status).to.equal(422); expect(r.body.errors).to.have.property('trip_id'); });
            });
            mobile('POST', '/api/v1/support-tickets', riderToken, { category: 'fare', subject: 'Invalid f', message: 'x', trip_id: 99999999 }).its('status').should('eq', 422);
            ticketRows('subject like ?', ['Invalid %']).should('deep.equal', before);
        });
    });

    it('links a ticket to the caller\'s own trip', () => {
        cy.make('support_ticket', { requester_id: rider.id, subject: 'Trip holder', with_trip: true }).then((holder) => {
            mobile('POST', '/api/v1/support-tickets', riderToken, { category: 'driver_behavior', subject: 'Rude driver', message: 'He shouted.', trip_id: holder.trip_id }).then((response) => {
                expect(response.status).to.equal(201);
                expect(response.body.data.trip_id).to.equal(holder.trip_id);
            });
        });
    });

    it('answers a retried request with the same ticket, and the same key for something else is 409', () => {
        const key = `cypress-t5-key-${Date.now()}`;
        mobile('POST', '/api/v1/support-tickets', riderToken, { category: 'other', subject: 'Retry me', message: 'Once.' }, key).then((first) => {
            expect(first.status).to.equal(201);
            mobile('POST', '/api/v1/support-tickets', riderToken, { category: 'other', subject: 'Retry me', message: 'Once.' }, key).then((again) => {
                expect(again.status).to.equal(200);
                expect(again.body.data.id).to.equal(first.body.data.id);
            });
            mobile('POST', '/api/v1/support-tickets', riderToken, { category: 'other', subject: 'Something else', message: 'Once.' }, key).its('status').should('eq', 409);
            ticketRows('subject = ?', ['Retry me']).should('have.length', 1);
            ticketRows('subject = ?', ['Something else']).should('have.length', 0);
        });
    });

    // Criterion: safety tickets are urgent and email every active admin with support.manage.
    it('creates a safety ticket urgent and mails the support admins (not finance or dispatch), after saving it', () => {
        mobile('POST', '/api/v1/support-tickets', riderToken, { category: 'safety', subject: 'Driver was speeding', message: 'Very fast on Mashtots.' }).then((response) => {
            expect(response.status).to.equal(201);
            expect(response.body.data.priority).to.equal('urgent');
            const code = response.body.data.code as string;
            cy.make<{ to: string; subject: string }[]>('logged_mail', { subject_contains: `Safety ticket ${code}` }).then((mails) => {
                const recipients = mails.map((mail) => mail.to).join(' ');
                [SUPPORT, COLLEAGUE, BOSS].forEach((email) => expect(recipients, email).to.contain(email));
                [FINANCE, DISPATCHER].forEach((email) => expect(recipients, email).not.to.contain(email));
                expect(mails.every((mail) => mail.subject.includes(`needs attention`))).to.equal(true);
            });
        });
        // A fare ticket sends nothing.
        mobile('POST', '/api/v1/support-tickets', riderToken, { category: 'fare', subject: 'Quiet fare', message: 'Hm.' }).then((response) => {
            cy.make<unknown[]>('logged_mail', { subject_contains: `Safety ticket ${response.body.data.code}` }).should('have.length', 0);
        });
    });

    // Criterion: GET /api/v1/support-tickets, and internal notes are never returned by the mobile endpoints.
    it('lists only the caller\'s own tickets of this app, newest first, and refuses someone else\'s ticket', () => {
        cy.make('support_ticket', { requester_id: otherRider.id, subject: 'Olga private' }).then((theirs) => {
            mobile('GET', '/api/v1/support-tickets', riderToken).then((response) => {
                expect(response.status).to.equal(200);
                expect(response.body.meta).to.include({ current_page: 1, per_page: 20 });
                const subjects = response.body.data.map((t: { subject: string }) => t.subject);
                expect(subjects).to.include('Charged twice');
                expect(subjects).not.to.include('Olga private');
                expect(subjects).not.to.include('App crashes');
                expect(response.body.data[0]).not.to.have.property('messages');
                const times = response.body.data.map((t: { last_message_at: string }) => Date.parse(t.last_message_at));
                expect(times).to.deep.equal([...times].sort((a, b) => b - a));
            });
            mobile('GET', `/api/v1/support-tickets/${theirs.id}`, riderToken).its('status').should('eq', 404);
            mobile('POST', `/api/v1/support-tickets/${theirs.id}/messages`, riderToken, { body: 'hi' }).its('status').should('eq', 404);
            // The same person's rider ticket is not visible from the driver app.
            ticketRows('subject = ?', ['Charged twice']).then((rows) => mobile('GET', `/api/v1/support-tickets/${rows[0].id}`, driverToken).its('status').should('eq', 404));
            mobile('GET', '/api/v1/support-tickets', null).its('status').should('eq', 401);
        });
    });

    it('shows the conversation to the requester without internal notes or staff names, and takes their messages', () => {
        cy.make('support_ticket', { requester_id: rider.id, subject: 'Conversation', message: 'It broke.' }).then((ticket) => {
            cy.loginAs(SUPPORT);
            cy.adminRequest('POST', `/admin/support-tickets/${ticket.id}/messages`, { body: 'We are checking.' }).its('status').should('be.oneOf', [200, 302, 303]);
            cy.adminRequest('POST', `/admin/support-tickets/${ticket.id}/messages`, { body: 'INTERNAL: driver has a history.', internal: true }).its('status').should('be.oneOf', [200, 302, 303]);

            mobile('GET', `/api/v1/support-tickets/${ticket.id}`, riderToken).then((response) => {
                expect(response.body.data.messages.map((m: { from: string; body: string }) => [m.from, m.body])).to.deep.equal([['you', 'It broke.'], ['support', 'We are checking.']]);
                expect(response.body.data.status).to.equal('waiting_on_customer');
                const raw = JSON.stringify(response.body);
                expect(raw).not.to.contain('INTERNAL');
                expect(raw).not.to.contain('Sam Support');
            });
            mobile('POST', `/api/v1/support-tickets/${ticket.id}/messages`, riderToken, { body: 'Thanks, here is more.' }).then((response) => {
                expect(response.status).to.equal(201);
                expect(response.body.data).to.include({ from: 'you', body: 'Thanks, here is more.', ticket_status: 'in_progress' });
            });
            mobile('POST', `/api/v1/support-tickets/${ticket.id}/messages`, riderToken, { body: '' }).its('status').should('eq', 422);
            const key = `cypress-t5-msg-${Date.now()}`;
            mobile('POST', `/api/v1/support-tickets/${ticket.id}/messages`, riderToken, { body: 'Only once.' }, key).its('status').should('eq', 201);
            mobile('POST', `/api/v1/support-tickets/${ticket.id}/messages`, riderToken, { body: 'Only once.' }, key).its('status').should('eq', 200);
            messages(ticket.id).then((rows) => expect(rows.filter((m) => m.body === 'Only once.')).to.have.length(1));
        });
    });

    it('refuses a closed ticket with 409 and limits the endpoints per person with Retry-After', () => {
        cy.make('support_ticket', { requester_id: rider.id, subject: 'Closed one', status: 'closed' }).then((ticket) => {
            mobile('POST', `/api/v1/support-tickets/${ticket.id}/messages`, riderToken, { body: 'Too late.' }).its('status').should('eq', 409);
            messages(ticket.id).then((rows) => expect(rows.map((m) => m.body)).not.to.include('Too late.'));
        });
        // The refused message above was the first of the 20 requests a minute.
        for (let i = 0; i < 19; i++) {
            mobile('GET', '/api/v1/support-tickets', riderToken).its('status').should('eq', 200);
        }
        mobile('GET', '/api/v1/support-tickets', riderToken).then((response) => {
            expect(response.status).to.equal(429);
            expect(Number(response.headers['retry-after'])).to.be.within(1, 60);
        });
        // Someone else's budget is their own.
        mobile('GET', '/api/v1/support-tickets', otherToken).its('status').should('eq', 200);
    });

    // ---- admin page ---------------------------------------------------------------------------------------------------

    // Criterion: GET /admin/support-tickets with filters status, category, priority, assignee.
    describe('the queue', () => {
        let urgent: { id: number; code: string };
        let mine: { id: number; code: string };
        let resolved: { id: number; code: string };

        before(() => {
            cy.make('support_ticket', { requester_id: rider.id, subject: 'Queue urgent', category: 'safety', priority: 'urgent', status: 'open' }).then((t) => (urgent = t));
            cy.make('support_ticket', { requester_id: otherRider.id, subject: 'Queue mine', category: 'lost_item', priority: 'high', status: 'in_progress', assignee_email: SUPPORT }).then((t) => (mine = t));
            cy.make('support_ticket', { requester_id: rider.id, subject: 'Queue done', category: 'fare', priority: 'low', status: 'resolved' }).then((t) => (resolved = t));
        });

        it('filters by status, category, priority and assignee, searches, and keeps the filters in the URL after a reload', () => {
            cy.loginAs(SUPPORT);
            cy.visit('/admin/support-tickets?lang=en');
            queueItem('Queue urgent').should('be.visible');
            queueItem('Queue mine').should('be.visible');

            cy.findByRole('combobox', { name: /status/i }).select('Resolved');
            cy.location('search').should('contain', 'filter%5Bstatus%5D=resolved');
            queueItem('Queue done').should('be.visible');
            queueItem('Queue urgent').should('not.exist');
            cy.findByRole('combobox', { name: /status/i }).select('All statuses');

            cy.findByRole('combobox', { name: /categor/i }).select('Lost item');
            queueItem('Queue mine').should('be.visible');
            queueItem('Queue urgent').should('not.exist');
            cy.findByRole('combobox', { name: /categor/i }).select('All categories');

            cy.findByRole('combobox', { name: /priorit/i }).select('Urgent');
            queueItem('Queue urgent').should('be.visible');
            queueItem('Queue mine').should('not.exist');
            cy.findByRole('combobox', { name: /priorit/i }).select('All priorities');

            cy.findByRole('combobox', { name: /assignee/i }).select('Assigned to me');
            queueItem('Queue mine').should('be.visible');
            queueItem('Queue urgent').should('not.exist');
            cy.findByRole('combobox', { name: /assignee/i }).select('Unassigned');
            queueItem('Queue urgent').should('be.visible');
            queueItem('Queue mine').should('not.exist');

            cy.reload();
            cy.findByRole('combobox', { name: /assignee/i }).should('have.value', 'unassigned');
            queueItem('Queue urgent').should('be.visible');
            cy.findByRole('combobox', { name: /assignee/i }).select('All assignees');

            cy.findByRole('searchbox').type(mine.code);
            queueItem('Queue mine').should('be.visible');
            queueItem('Queue urgent').should('not.exist');
        });

        it('rejects a malformed filter in the URL instead of failing', () => {
            cy.loginAs(SUPPORT);
            ['filter[status]=nope', 'filter[assignee]=abc', 'filter[bogus]=1'].forEach((query) => {
                cy.request({ url: `/admin/support-tickets?${query}`, failOnStatusCode: false }).its('status').should('eq', 400);
            });
        });

        // Criterion: GET /admin/support-tickets/{id}.
        it('opens a ticket from the list and by its own URL (the link in the alert), and 404s an unknown one', () => {
            cy.loginAs(SUPPORT);
            cy.visit('/admin/support-tickets?lang=en');
            queueItem('Queue mine').click();
            cy.location('search').should('contain', `ticket=${mine.id}`);
            cy.findByRole('heading', { name: 'Queue mine' }).should('be.visible');

            cy.visit(`/admin/support-tickets/${urgent.id}?lang=en`);
            cy.findByRole('heading', { name: 'Queue urgent' }).should('be.visible');
            cy.contains(urgent.code).should('be.visible');
            cy.request({ url: '/admin/support-tickets/99999999', failOnStatusCode: false }).its('status').should('eq', 404);
        });

        it('shows the urgent count on the menu', () => {
            cy.loginAs(SUPPORT);
            cy.visit('/admin?lang=en');
            cy.findByRole('navigation', { name: 'Main' }).within(() => {
                cy.findByRole('link', { name: /Support Tickets/ }).invoke('text').should('match', /\d+$/);
            });
        });
    });

    describe('working a ticket', () => {
        // Criterion: POST /admin/support-tickets/{id}/messages (reply or internal note); internal notes are never returned by the mobile endpoints.
        it('sends a reply and keeps an internal note staff-only: saved, shown after reload, audited, never in the rider\'s view', () => {
            cy.make('support_ticket', { requester_id: rider.id, subject: 'Reply flow', message: 'Where is my receipt?' }).then((ticket) => {
                cy.loginAs(SUPPORT);
                cy.intercept('POST', `/admin/support-tickets/${ticket.id}/messages`).as('message');
                openTicket(ticket.id);
                cy.contains('Where is my receipt?').should('be.visible');

                cy.findByRole('button', { name: 'Send' }).should('be.disabled');
                cy.findByLabelText('Reply').type('Sent to your email.');
                cy.findByRole('button', { name: 'Send' }).click();
                cy.wait('@message').its('response.statusCode').should('be.oneOf', [302, 303]);
                cy.contains('Reply sent.').should('be.visible');
                cy.findByLabelText('Reply').should('have.value', '');

                cy.findByRole('button', { name: 'Internal note' }).click();
                cy.findByLabelText('Internal note').type('Customer is a regular.');
                cy.findByRole('button', { name: 'Send' }).click();
                cy.wait('@message');
                cy.contains('Internal note added.').should('be.visible');

                cy.reload();
                cy.contains('Sent to your email.').should('be.visible');
                cy.contains('Customer is a regular.').should('be.visible');
                cy.contains('Internal note · Only visible to staff').should('be.visible');

                ticketRows('id = ?', [ticket.id]).then((rows) => expect(rows[0]).to.include({ status: 'waiting_on_customer', assigned_to: support.id }));
                messages(ticket.id).then((rows) => expect(rows.map((m) => [m.body, m.is_internal_note])).to.deep.equal([['Where is my receipt?', false], ['Sent to your email.', false], ['Customer is a regular.', true]]));
                audit('support_ticket.replied', ticket.id).then((rows) => expect(rows).to.have.length(1).and.to.have.nested.property('0.causer_id', support.id));
                audit('support_ticket.note_added', ticket.id).should('have.length', 1);
                mobile('GET', `/api/v1/support-tickets/${ticket.id}`, riderToken).then((response) => {
                    expect(response.body.data.messages.map((m: { body: string }) => m.body)).to.deep.equal(['Where is my receipt?', 'Sent to your email.']);
                    expect(JSON.stringify(response.body)).not.to.contain('regular');
                });
            });
        });

        it('sends a double-clicked reply once', () => {
            cy.make('support_ticket', { requester_id: rider.id, subject: 'Double reply' }).then((ticket) => {
                cy.loginAs(SUPPORT);
                cy.intercept('POST', `/admin/support-tickets/${ticket.id}/messages`).as('message');
                openTicket(ticket.id);
                cy.findByLabelText('Reply').type('Just once please.');
                cy.findByRole('button', { name: 'Send' }).dblclick();
                cy.contains('Reply sent.').should('be.visible');
                cy.get('@message.all').should('have.length', 1);
                messages(ticket.id).then((rows) => expect(rows.filter((m) => m.body === 'Just once please.')).to.have.length(1));
            });
        });

        // Criterion: POST /admin/support-tickets/{id}/assign.
        it('assigns a ticket to a colleague and takes the assignee off again, with an audit entry', () => {
            cy.make('support_ticket', { requester_id: rider.id, subject: 'Assign me' }).then((ticket) => {
                cy.loginAs(SUPPORT);
                openTicket(ticket.id);
                cy.findByRole('button', { name: 'Assign' }).click();
                cy.findByRole('dialog', { name: 'Assign ticket' }).within(() => {
                    cy.findByLabelText('Assignee').find('option').then(($options) => {
                        expect([...$options].map((o) => o.textContent)).to.deep.equal(['Unassigned', 'Bo Boss', 'Casey Colleague', 'Sam Support (me)']);
                    });
                    cy.findByLabelText('Assignee').select('Casey Colleague');
                    cy.findByRole('button', { name: 'Assign' }).click();
                });
                cy.contains('Ticket assigned.').should('be.visible');
                cy.reload();
                cy.contains('Casey Colleague').should('be.visible');
                ticketRows('id = ?', [ticket.id]).then((rows) => {
                    expect(rows[0].status).to.equal('in_progress');
                    cy.sql('select id from users where email = ?', [COLLEAGUE]).then((u) => expect(rows[0].assigned_to).to.equal(u[0].id));
                });
                audit('support_ticket.assigned', ticket.id).then((rows) => expect(rows).to.have.length(1).and.to.have.nested.property('0.causer_id', support.id));

                cy.findByRole('button', { name: 'Assign' }).click();
                cy.findByRole('dialog', { name: 'Assign ticket' }).within(() => {
                    cy.findByLabelText('Assignee').select('Unassigned');
                    cy.findByRole('button', { name: 'Assign' }).click();
                });
                cy.contains('Ticket assigned.').should('be.visible');
                ticketRows('id = ?', [ticket.id]).its('0.assigned_to').should('eq', null);
            });
        });

        it('refuses to assign to someone who does not handle support', () => {
            cy.make('support_ticket', { requester_id: rider.id, subject: 'Wrong assignee' }).then((ticket) => {
                cy.sql('select id from users where email = ?', [FINANCE]).then((finance) => {
                    cy.loginAs(SUPPORT);
                    adminJson('POST', `/admin/support-tickets/${ticket.id}/assign`, { assignee_id: finance[0].id }).its('status').should('eq', 409);
                    adminJson('POST', `/admin/support-tickets/${ticket.id}/assign`, {}).its('status').should('eq', 422);
                    ticketRows('id = ?', [ticket.id]).its('0.assigned_to').should('eq', null);
                });
            });
        });

        // Criterion: POST /admin/support-tickets/{id}/resolve (resolution required); /reopen.
        it('resolves only with a resolution, once for a double-click, then reopens', () => {
            cy.make('support_ticket', { requester_id: rider.id, subject: 'Resolve flow' }).then((ticket) => {
                cy.loginAs(SUPPORT);
                cy.intercept('POST', `/admin/support-tickets/${ticket.id}/resolve`).as('resolve');
                openTicket(ticket.id);
                cy.findByRole('button', { name: 'Resolve' }).click();
                cy.findByRole('dialog', { name: 'Resolve ticket' }).within(() => {
                    cy.findByRole('button', { name: 'Resolve' }).should('be.disabled');
                    cy.findByLabelText(/Resolution/).type('   ');
                    cy.findByRole('button', { name: 'Resolve' }).should('be.disabled');
                    cy.findByLabelText(/Resolution/).clear().type('Refunded the difference.');
                    cy.findByRole('button', { name: 'Resolve' }).dblclick();
                });
                cy.contains('Ticket resolved.').should('be.visible');
                cy.get('@resolve.all').should('have.length', 1);

                cy.reload();
                cy.contains('Refunded the difference.').should('be.visible');
                cy.findByLabelText('Reply').should('not.exist');
                cy.findByRole('button', { name: 'Resolve' }).should('not.exist');
                ticketRows('id = ?', [ticket.id]).then((rows) => expect(rows[0]).to.include({ status: 'resolved', resolution: 'Refunded the difference.', resolved: true }));
                audit('support_ticket.resolved', ticket.id).then((rows) => {
                    expect(rows).to.have.length(1);
                    expect(rows[0]).to.include({ causer_id: support.id, reason: 'Refunded the difference.' });
                });
                mobile('GET', `/api/v1/support-tickets/${ticket.id}`, riderToken).then((response) => expect(response.body.data).to.include({ status: 'resolved', resolution: 'Refunded the difference.' }));

                cy.findByRole('button', { name: 'Reopen' }).click();
                cy.contains('Ticket reopened.').should('be.visible');
                cy.findByLabelText('Reply').should('be.visible');
                ticketRows('id = ?', [ticket.id]).then((rows) => expect(rows[0]).to.include({ status: 'open', resolution: null, resolved: false }));
                audit('support_ticket.reopened', ticket.id).should('have.length', 1);
            });
        });

        it('shows the 409 message when the ticket was resolved meanwhile, and the server refuses an empty resolution', () => {
            cy.make('support_ticket', { requester_id: rider.id, subject: 'Stale page' }).then((ticket) => {
                cy.loginAs(SUPPORT);
                openTicket(ticket.id);
                adminJson('POST', `/admin/support-tickets/${ticket.id}/resolve`, { resolution: '' }).its('status').should('eq', 422);
                cy.adminRequest('POST', `/admin/support-tickets/${ticket.id}/resolve`, { resolution: 'Done elsewhere.' }).its('status').should('be.oneOf', [200, 302, 303]);

                cy.findByRole('button', { name: 'Resolve' }).click();
                cy.findByRole('dialog', { name: 'Resolve ticket' }).within(() => {
                    cy.findByLabelText(/Resolution/).type('Second resolution.');
                    cy.findByRole('button', { name: 'Resolve' }).click();
                });
                cy.contains('This ticket is already resolved.').should('be.visible');
                ticketRows('id = ?', [ticket.id]).its('0.resolution').should('eq', 'Done elsewhere.');
                audit('support_ticket.resolved', ticket.id).should('have.length', 1);
            });
        });

        // Criterion: from a ticket an admin can jump to the trip and issue a refund or adjust the fare.
        it('links the trip, and offers refund and fare adjustment only to roles with those permissions (disabled until built)', () => {
            cy.make('support_ticket', { requester_id: rider.id, subject: 'Trip linked', category: 'fare', with_trip: true }).then((ticket) => {
                cy.loginAs(SUPPORT);
                openTicket(ticket.id);
                cy.findByText(ticket.trip_code).should('be.visible');
                cy.findByRole('link', { name: 'Open trip' }).should('have.attr', 'href', `/admin/trips/${ticket.trip_id}`);
                cy.findByRole('button', { name: 'Issue refund' }).should('not.exist');
                cy.findByRole('button', { name: 'Adjust fare' }).should('not.exist');

                cy.loginAs(BOSS);
                openTicket(ticket.id);
                cy.findByRole('button', { name: 'Issue refund' }).should('be.disabled');
                cy.findByRole('button', { name: 'Adjust fare' }).should('be.disabled');
                cy.findByRole('link', { name: 'Open trip' }).should('be.visible');
            });
        });
    });

    // ---- permissions --------------------------------------------------------------------------------------------------

    it('hides Support Tickets from roles without support.manage and answers 403 on every URL; a guest is sent to sign in', () => {
        cy.make('support_ticket', { requester_id: rider.id, subject: 'Permission probe' }).then((ticket) => {
            [FINANCE, DISPATCHER].forEach((email) => {
                cy.loginAs(email);
                cy.visit('/admin?lang=en');
                cy.findByRole('navigation', { name: 'Main' }).within(() => cy.findByRole('link', { name: /Support Tickets/ }).should('not.exist'));
                cy.request({ url: '/admin/support-tickets', failOnStatusCode: false }).its('status').should('eq', 403);
                cy.request({ url: `/admin/support-tickets/${ticket.id}`, failOnStatusCode: false }).its('status').should('eq', 403);
                ['assign', 'messages', 'resolve', 'reopen'].forEach((action) => {
                    cy.adminRequest('POST', `/admin/support-tickets/${ticket.id}/${action}`, { assignee_id: null, body: 'x', resolution: 'x' }).its('status').should('eq', 403);
                });
                cy.clearAllCookies();
                Cypress.session.clearAllSavedSessions();
            });
            ticketRows('id = ?', [ticket.id]).then((rows) => expect(rows[0]).to.include({ status: 'open', resolution: null }));
            messages(ticket.id).should('have.length', 1);

            cy.clearAllCookies();
            cy.request({ url: '/admin/support-tickets', followRedirect: false }).then((response) => {
                expect(response.status).to.be.oneOf([302, 303]);
                expect(String(response.headers.location)).to.match(/\/login$/);
            });
        });
    });
});

export {};
