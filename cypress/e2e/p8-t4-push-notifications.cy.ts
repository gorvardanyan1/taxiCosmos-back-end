/**
 * P8-T4 · Push notifications: POST /api/v1/devices and DELETE /api/v1/devices/{id}, and the pushes the system sends.
 * Real server, real OTP sign-ins, the real database. The e2e environment runs the log driver (PUSH_DRIVER=log), so a push is
 * written to the e2e log instead of being sent to Expo: that log is how a spec sees what "would have been sent". Expo itself,
 * invalid-token revocation and the queue retries are covered by PHPUnit with the HTTP client faked.
 */
const CODE = '123456';
const signIn = (phone: string, app: 'driver' | 'rider' = 'driver') =>
    cy.api('POST', '/api/v1/auth/otp/request', { phone, app }).its('status').should('eq', 202).then(() =>
        cy.api('POST', '/api/v1/auth/otp/verify', { phone, code: CODE, app, device_id: `cypress-${app}-device` }).then((response) => {
            expect(response.status, `sign-in of ${phone}`).to.equal(200);
            return response.body.access_token as string;
        }));

interface Push { token: string; title: string; body: string; data: Record<string, unknown>; priority: string; channel: string; time_sensitive: boolean }
const expoToken = (label: string) => `ExponentPushToken[cy-${label}-0123456789]`;
const register = (accessToken: string, pushToken: string, extra: Record<string, unknown> = {}) => cy.api('POST', '/api/v1/devices', { push_token: pushToken, platform: 'android', ...extra }, accessToken);
const pushesTo = (pushToken: string) => cy.make<Push[]>('sent_pushes', { push_token: pushToken });
const deviceRows = (userId: number) => cy.sql('select id, user_id, app, platform, device_id, locale, app_version, revoked_at is not null as revoked from devices where user_id = ? order by id', [userId]);
const offer = (driverUserId: number, offerId = 1, tripId = 10) => cy.make('dispatch_trip_event', { kind: 'offer', driver_user_id: driverUserId, offer_id: offerId, trip_id: tripId });

interface Person { user_id: number; driver_id: number; phone: string; name: string }

describe('P8-T4 · Push notifications', () => {
    let admin: { id: number };

    before(() => {
        cy.resetDb();
        cy.make('admin', { email: 'cy-push-admin@taxikosmos.test', role: 'support', name: 'Paula Push' }).then((a) => (admin = a));
    });

    beforeEach(() => cy.make('clear_rate_limits'));

    // Criterion: devices table + registration (a token registered by another user moves to the new user).
    it('registers a device after sign-in, updates it when repeated, and refuses bad tokens, platforms and languages', () => {
        cy.make<Person>('driver', { name: 'Reg Rita', phone: '+37491520021', locale: 'ru' }).then((person) => {
            signIn(person.phone).then((access) => {
                register(access, expoToken('reg'), { platform: 'ios', locale: 'ru', app_version: '1.4.2' }).then((r) => {
                    expect(r.status).to.equal(201);
                    expect(r.body.device).to.include({ app: 'driver', platform: 'ios', locale: 'ru', app_version: '1.4.2' });
                    expect(JSON.stringify(r.body), 'the token is not echoed back').not.to.contain('ExponentPushToken');
                    const id = r.body.device.id;
                    deviceRows(person.user_id).then((rows) => expect(rows).to.have.length(1).and.to.have.nested.property('0.device_id', 'cypress-driver-device'));
                    register(access, expoToken('reg'), { platform: 'ios', locale: 'hy', app_version: '1.5.0' }).then((again) => {
                        expect(again.status).to.equal(200);
                        expect(again.body.device.id).to.equal(id);
                    });
                    deviceRows(person.user_id).then((rows) => { expect(rows).to.have.length(1); expect(rows[0]).to.include({ locale: 'hy', app_version: '1.5.0', revoked: false }); });
                });

                [
                    [{ push_token: 'abc' }, 'push_token'], [{ push_token: 'ExponentPushToken[]' }, 'push_token'], [{ push_token: null }, 'push_token'],
                    [{ platform: 'windows' }, 'platform'], [{ locale: 'fr' }, 'locale'], [{ app_version: '1'.repeat(33) }, 'app_version'],
                ].forEach(([override, field]) => cy.api('POST', '/api/v1/devices', { push_token: expoToken('bad'), platform: 'ios', ...(override as object) }, access).then((r) => {
                    expect(r.status, JSON.stringify(override)).to.equal(422);
                    expect(r.body.errors).to.have.property(field as string);
                }));
                deviceRows(person.user_id).then((rows) => expect(rows, 'nothing was added').to.have.length(1));
            });
        });
        cy.api('POST', '/api/v1/devices', { push_token: expoToken('guest'), platform: 'ios' }).its('status').should('eq', 401);
        cy.api('POST', '/api/v1/devices', { push_token: expoToken('guest'), platform: 'ios' }, 'junk').its('status').should('eq', 401);
    });

    // Criterion: logging out deregisters the token; a new user on the same phone never receives the previous user's pushes.
    it('ends the registration at logout, and the next person on the same phone never receives the previous person\'s pushes', () => {
        cy.make<Person>('driver', { name: 'Shared Alice', phone: '+37491520022', locale: 'en' }).then((alice) => {
            cy.make<Person>('driver', { name: 'Shared Bob', phone: '+37491520023', locale: 'en' }).then((bob) => {
                const phone = expoToken('shared-phone');
                signIn(alice.phone).then((aliceAccess) => {
                    register(aliceAccess, phone).its('status').should('eq', 201);
                    offer(alice.user_id, 1);
                    pushesTo(phone).should('have.length', 1);

                    cy.api('POST', '/api/v1/auth/logout', undefined, aliceAccess).its('status').should('eq', 204);
                    deviceRows(alice.user_id).then((rows) => expect(rows[0].revoked, 'logout deregisters the device').to.equal(true));
                    offer(alice.user_id, 2);
                    pushesTo(phone).should('have.length', 1); // nothing new for Alice
                });

                // Bob signs in on the same phone: Alice's pushes still do not arrive, Bob's do.
                signIn(bob.phone).then((bobAccess) => {
                    register(bobAccess, phone).its('status').should('eq', 200); // the same token: the row moves to Bob
                    deviceRows(bob.user_id).then((rows) => { expect(rows).to.have.length(1); expect(rows[0].revoked).to.equal(false); });
                    offer(alice.user_id, 3);
                    pushesTo(phone).should('have.length', 1);
                    offer(bob.user_id, 4);
                    pushesTo(phone).should('have.length', 2);
                    pushesTo(phone).then((pushes) => expect(pushes[1].data).to.deep.equal({ type: 'trip_offer', offer_id: 4, trip_id: 10 }));
                });
            });
        });
    });

    it('ends the previous person\'s registration when someone else signs in on that phone, even before the new person registers', () => {
        cy.make<Person>('driver', { name: 'Lapsed Lia', phone: '+37491520024' }).then((lia) => {
            cy.make<Person>('driver', { name: 'Fresh Finn', phone: '+37491520025' }).then((finn) => {
                signIn(lia.phone).then((liaAccess) => register(liaAccess, expoToken('lapsed')).its('status').should('eq', 201));
                deviceRows(lia.user_id).then((rows) => expect(rows[0].revoked).to.equal(false));

                signIn(finn.phone); // Lia never logged out; Finn has not registered a token yet

                deviceRows(lia.user_id).then((rows) => expect(rows[0].revoked, 'Lia is no longer registered on this phone').to.equal(true));
                offer(lia.user_id, 5);
                pushesTo(expoToken('lapsed')).should('deep.equal', []);
            });
        });
    });

    it('deregisters a device with DELETE, refuses to touch someone else\'s, and logs everything out with logout-all', () => {
        cy.make<Person>('driver', { name: 'Del Dana', phone: '+37491520026' }).then((dana) => {
            cy.make<Person>('driver', { name: 'Del Dan', phone: '+37491520027' }).then((dan) => {
                signIn(dana.phone).then((danaAccess) => signIn(dan.phone).then((danAccess) => {
                    register(danaAccess, expoToken('dana')).then((r) => {
                        const id = r.body.device.id;
                        cy.api('DELETE', `/api/v1/devices/${id}`, undefined, danAccess).its('status').should('eq', 404); // someone else's
                        deviceRows(dana.user_id).then((rows) => expect(rows[0].revoked).to.equal(false));
                        cy.api('DELETE', `/api/v1/devices/${id}`, undefined, danaAccess).its('status').should('eq', 204);
                        cy.api('DELETE', `/api/v1/devices/${id}`, undefined, danaAccess).its('status').should('eq', 204); // repeating changes nothing
                        deviceRows(dana.user_id).then((rows) => { expect(rows).to.have.length(1); expect(rows[0].revoked).to.equal(true); });
                    });
                    cy.api('DELETE', '/api/v1/devices/999999', undefined, danaAccess).its('status').should('eq', 404);
                    cy.api('DELETE', '/api/v1/devices/abc', undefined, danaAccess).its('status').should('eq', 404);

                    register(danAccess, expoToken('dan-1')).its('status').should('eq', 201);
                    cy.api('POST', '/api/v1/auth/logout-all', undefined, danAccess).its('status').should('eq', 204);
                    deviceRows(dan.user_id).then((rows) => expect(rows.every((row) => row.revoked)).to.equal(true));
                    offer(dan.user_id, 6);
                    pushesTo(expoToken('dan-1')).should('deep.equal', []);
                }));
            });
        });
    });

    // Criterion: notification texts use the user's locale; each push carries a type and ids; offers are urgent.
    it('pushes the right text in the person\'s language with a type and ids only, and urgent delivery for a trip offer', () => {
        cy.make<Person>('driver', { name: 'Lang Leo', phone: '+37491520031', locale: 'ru' }).then((leo) => {
            cy.make<{ id: number; phone: string }>('rider', { name: 'Lang Rosa', phone: '+37491520032', locale: 'hy' }).then((rosa) => {
                signIn(leo.phone).then((access) => register(access, expoToken('leo'), { locale: 'en' }).its('status').should('eq', 201));
                signIn(rosa.phone, 'rider').then((access) => register(access, expoToken('rosa'), { platform: 'ios' }).its('status').should('eq', 201));

                offer(leo.user_id, 7, 70);
                pushesTo(expoToken('leo')).then((pushes) => {
                    expect(pushes).to.have.length(1);
                    expect(pushes[0].title, 'the account language (ru), not the phone\'s (en)').to.equal('Новый заказ');
                    expect(pushes[0].data).to.deep.equal({ type: 'trip_offer', offer_id: 7, trip_id: 70 });
                    expect(pushes[0]).to.include({ priority: 'high', channel: 'trip_offers', time_sensitive: true });
                    expect(JSON.stringify(pushes[0]), 'no addresses or fare in a push').not.to.match(/AMD|2500/);
                });

                ['matched', 'arrived', 'completed', 'no_driver_found'].forEach((status, i) => cy.make('dispatch_trip_event', { kind: 'status', status, trip_id: 80 + i, rider_user_id: rosa.id, driver_user_id: leo.user_id }));
                cy.make('dispatch_trip_event', { kind: 'status', status: 'in_progress', trip_id: 90, rider_user_id: rosa.id });
                cy.make('dispatch_trip_event', { kind: 'reply', ticket_id: 4, requester_user_id: rosa.id });
                pushesTo(expoToken('rosa')).then((pushes) => {
                    expect(pushes.map((p) => p.data.type), 'in progress has no push of its own').to.deep.equal(['trip_matched', 'trip_arrived', 'trip_completed', 'no_driver_found', 'support_reply']);
                    expect(pushes[1].title).to.equal('Ձեր վարորդը ժամանել է');
                    expect(pushes[1].data).to.deep.equal({ type: 'trip_arrived', trip_id: 81 });
                    expect(pushes.every((p) => p.priority === 'default' && p.channel === 'default' && !p.time_sensitive)).to.equal(true);
                });
                pushesTo(expoToken('leo')).should('have.length', 1); // the rider's events never reach the driver app
            });
        });
    });

    // Criterion: document approved or rejected (driver). The real admin action.
    it('tells the driver when an admin approves or rejects a document, without the reason in the push', () => {
        cy.make<Person & { documents: { id: number; type: string }[] }>('driver_with_documents', { name: 'Doc Dora', phone: '+37491520033' }).then((dora) => {
            signIn(dora.phone).then((access) => register(access, expoToken('dora')).its('status').should('eq', 201));
            cy.loginAs('cy-push-admin@taxikosmos.test');
            const [first, second] = dora.documents;

            cy.adminRequest('POST', `/admin/drivers/${dora.driver_id}/documents/${first.id}/approve`).its('status').should('eq', 302);
            cy.adminRequest('POST', `/admin/drivers/${dora.driver_id}/documents/${second.id}/reject`, { reason: 'The photo is blurry' }).its('status').should('eq', 302);

            pushesTo(expoToken('dora')).then((pushes) => {
                expect(pushes.map((p) => p.data)).to.deep.equal([{ type: 'document_approved', document_id: first.id }, { type: 'document_rejected', document_id: second.id }]);
                expect(pushes.map((p) => p.title)).to.deep.equal(['Document approved', 'Document rejected']);
                expect(JSON.stringify(pushes[1])).not.to.contain('blurry');
            });
            // Approving the same document again changes nothing, so it sends nothing more.
            cy.adminRequest('POST', `/admin/drivers/${dora.driver_id}/documents/${first.id}/approve`).its('status').should('eq', 302);
            pushesTo(expoToken('dora')).should('have.length', 2);
        });
    });

    // Criterion: account suspended (both).
    it('announces a suspension once, ends the sessions, and sends nothing else to the suspended account', () => {
        cy.make<Person>('driver', { name: 'Susp Sam', phone: '+37491520034', locale: 'en' }).then((sam) => {
            signIn(sam.phone).then((access) => {
                register(access, expoToken('sam')).its('status').should('eq', 201);
                cy.loginAs('cy-push-admin@taxikosmos.test');

                cy.adminRequest('POST', `/admin/drivers/${sam.driver_id}/suspend`, { reason: 'Safety complaint' }).its('status').should('eq', 302);

                pushesTo(expoToken('sam')).then((pushes) => {
                    expect(pushes).to.have.length(1);
                    expect(pushes[0].title).to.equal('Account suspended');
                    expect(pushes[0].data).to.deep.equal({ type: 'account_suspended' });
                });
                cy.api('POST', '/api/v1/devices', { push_token: expoToken('sam2'), platform: 'ios' }, access).its('status').should('eq', 401); // the session is gone
                offer(sam.user_id, 8);
                pushesTo(expoToken('sam')).should('have.length', 1); // no offer for a suspended driver
            });
        });
    });

    // Criterion (security checklist): rate limited.
    it('limits registrations to 30 a minute per user and leaves another user alone', () => {
        cy.make<Person>('driver', { name: 'Rate Ray', phone: '+37491520035' }).then((ray) => {
            cy.make<Person>('driver', { name: 'Rate Rob', phone: '+37491520036' }).then((rob) => {
                signIn(ray.phone).then((rayAccess) => signIn(rob.phone).then((robAccess) => {
                    cy.make('clear_rate_limits');
                    for (let i = 0; i < 30; i++) register(rayAccess, expoToken(`ray-${i}`)).its('status').should('be.oneOf', [200, 201]);
                    register(rayAccess, expoToken('ray-31')).then((r) => { expect(r.status).to.equal(429); expect(r.headers).to.have.property('retry-after'); });
                    register(robAccess, expoToken('rob')).its('status').should('eq', 201);
                }));
            });
        });
    });
});

export {};
