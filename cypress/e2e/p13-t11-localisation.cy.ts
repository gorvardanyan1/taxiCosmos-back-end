/**
 * P13-T11 · Admin UI localisation: English, Russian (ru) and Armenian (hy).
 * Sign-in language links, the language switch in My Account (saved, audited, applied after a reload),
 * every page of the route map in each language, server messages (toasts, validation, 404) in the
 * admin's language, plural forms and number/date formats, and the mobile API's Accept-Language.
 */
const CYRILLIC = /[А-Яа-яЁё]/;
const ARMENIAN = /[Ա-Ֆա-և]/;

const ROUTE_MAP = [
    '/admin', '/admin/live-map', '/admin/riders', '/admin/drivers', '/admin/trips', '/admin/support-tickets', '/admin/ratings',
    '/admin/transactions', '/admin/payouts', '/admin/chargebacks', '/admin/driver-balances', '/admin/reports', '/admin/zones',
    '/admin/surge', '/admin/commission-rules', '/admin/settings/users', '/admin/settings/gateways', '/admin/settings/currencies',
    '/admin/settings/platform', '/admin/settings/maps', '/admin/activity-logs', '/admin/account',
];

/** English words of the page chrome (menu, header, page title) that must never remain in another language. */
const ENGLISH_CHROME = /\b(Super Admin|Riders|Drivers|Trips|Dashboard|Settings|Reports|Zones|Payments|Payouts|Support|Finance|Dispatcher|Overview|Operations|Platform|Activity Log|Live Map|Admin Console|Surge|Ratings)\b/;

const savedLocale = (email: string) => cy.sql('select locale from users where email = ?', [email]).its('0.locale');

describe('P13-T11 · Localisation (en / ru / hy)', () => {
    before(() => {
        cy.resetDb();
        cy.make('admin', { email: 'cy-i18n-en@taxikosmos.test', role: 'admin', name: 'Eve English', locale: 'en' });
        cy.make('admin', { email: 'cy-i18n-ru@taxikosmos.test', role: 'super_admin', name: 'Rita Russian', locale: 'ru' });
        cy.make('admin', { email: 'cy-i18n-hy@taxikosmos.test', role: 'super_admin', name: 'Hasmik Armenian', locale: 'hy' });
        cy.make('admin', { email: 'cy-i18n-switch@taxikosmos.test', role: 'admin', name: 'Sam Switcher', locale: 'en' });
        cy.make('riders', { count: 21, prefix: 'I18n Rider' });
    });

    beforeEach(() => {
        Cypress.session.clearAllSavedSessions();
        cy.clearAllCookies();
    });

    // Criterion: an admin can switch language in My Account.
    it('switches the language in My Account: saved, audited, and the whole panel changes after the reload', () => {
        cy.loginAs('cy-i18n-switch@taxikosmos.test');
        cy.intercept('PATCH', '/admin/account/locale').as('save');
        cy.visit('/admin/account');

        cy.findByRole('heading', { name: 'My Account' }).should('be.visible');
        cy.findByLabelText('Admin panel language').as('language').should('have.value', 'en');
        cy.findByRole('button', { name: 'Save language' }).should('be.disabled');
        cy.get('@language').find('option').then(($options) => expect([...$options].map((o) => o.textContent)).to.deep.equal(['English', 'Հայերեն', 'Русский']));

        cy.get('@language').select('Русский');
        cy.findByRole('button', { name: 'Save language' }).should('be.enabled').dblclick();
        cy.wait('@save').its('request.body').should('deep.equal', { locale: 'ru' });

        cy.findByRole('heading', { name: 'Мой аккаунт' }).should('be.visible');
        cy.findByRole('status').should('contain', 'Язык обновлён.');
        cy.get('html').should('have.attr', 'lang', 'ru');
        cy.title().should('eq', 'Мой аккаунт · TaxiKosmos Admin');
        cy.get('nav[aria-label="Главное"]').within(() => {
            cy.contains('a', 'Пассажиры').should('be.visible');
            cy.contains('a', 'Водители').should('be.visible');
            cy.contains('Обзор').should('be.visible');
        });
        cy.findByLabelText('Язык панели администратора').should('have.value', 'ru');

        cy.reload();
        cy.findByRole('heading', { name: 'Мой аккаунт' }).should('be.visible');
        savedLocale('cy-i18n-switch@taxikosmos.test').should('eq', 'ru');
        cy.sql("select causer_id, attribute_changes from activity_log where description = 'account.locale_changed' order by id").then((rows) => {
            expect(rows, 'one change, one entry').to.have.length(1);
            expect(JSON.parse(rows[0].attribute_changes)).to.deep.equal({ old: { locale: 'en' }, attributes: { locale: 'ru' } });
        });

        // And back to English, and to Armenian: each is a full reload in that language.
        cy.findByLabelText('Язык панели администратора').select('Հայերեն');
        cy.findByRole('button', { name: 'Сохранить язык' }).click();
        cy.findByRole('heading', { name: 'Իմ հաշիվը' }).should('be.visible');
        cy.get('html').should('have.attr', 'lang', 'hy');
        cy.findByLabelText('Ադմինիստրատորի վահանակի լեզուն').select('English');
        cy.findByRole('button', { name: 'Պահպանել լեզուն' }).click();
        cy.findByRole('heading', { name: 'My Account' }).should('be.visible');
        savedLocale('cy-i18n-switch@taxikosmos.test').should('eq', 'en');
    });

    it('refuses a language the panel does not offer and changes only the signed-in admin', () => {
        cy.make('admin', { email: 'cy-i18n-own@taxikosmos.test', role: 'support', locale: 'en' }).then((own) => {
            cy.loginAs('cy-i18n-own@taxikosmos.test');
            cy.visit('/admin/account');

            cy.adminRequest('PATCH', '/admin/account/locale', { locale: 'de' }).its('status').should('be.oneOf', [302, 303]);
            cy.pageProps('/admin/account').its('errors.locale').should('exist');
            savedLocale('cy-i18n-own@taxikosmos.test').should('eq', 'en');

            // Another admin's id in the request changes nothing about them.
            cy.sql("select id from users where email = 'cy-i18n-hy@taxikosmos.test'").its('0.id').then((otherId) => {
                cy.adminRequest('PATCH', '/admin/account/locale', { locale: 'ru', id: otherId, user_id: otherId }).its('status').should('be.oneOf', [302, 303, 409]);
            });
            savedLocale('cy-i18n-own@taxikosmos.test').should('eq', 'ru');
            savedLocale('cy-i18n-hy@taxikosmos.test').should('eq', 'hy');
            expect(own.id).to.be.a('number');
        });
    });

    // Criterion: sign-in screens are available in every language.
    it('lets a guest pick the language on the sign-in screens, and remembers it', () => {
        cy.visit('/login');
        cy.findByRole('button', { name: 'Sign in' }).should('be.visible');
        cy.get('nav[aria-label=Language] a').should('have.length', 3).then(($links) => expect([...$links].map((a) => a.textContent)).to.deep.equal(['English', 'Հայերեն', 'Русский']));

        cy.contains('nav a', 'Русский').click();
        cy.findByRole('button', { name: 'Войти' }).should('be.visible');
        cy.findByLabelText('Пароль').should('be.visible');
        cy.contains('a', 'Забыли пароль?').should('be.visible');
        cy.get('html').should('have.attr', 'lang', 'ru');
        cy.contains('nav a[aria-current=true]', 'Русский').should('exist');

        cy.contains('a', 'Забыли пароль?').click();
        cy.location('pathname').should('eq', '/forgot-password');
        cy.findByRole('button', { name: 'Отправить ссылку для сброса' }).should('be.visible');
        cy.findByLabelText('Адрес электронной почты').type('nobody@taxikosmos.test');
        cy.findByRole('button', { name: 'Отправить ссылку для сброса' }).click();
        cy.contains('Ссылка на сброс пароля была отправлена.').should('be.visible');

        cy.contains('nav a', 'Հայերեն').click();
        cy.findByRole('button', { name: 'Ուղարկել վերականգնման հղումը' }).should('be.visible');
        cy.get('html').should('have.attr', 'lang', 'hy');
        cy.contains('a', 'Վերադառնալ մուտքին').click();
        cy.findByRole('button', { name: 'Մուտք գործել' }).should('be.visible');
    });

    it('shows sign-in errors in the chosen language', () => {
        cy.visit('/login?lang=ru');
        cy.findByLabelText('Эл. почта').type('nobody@taxikosmos.test');
        cy.findByLabelText('Пароль').type('Not-A-Password-1!');
        cy.findByRole('button', { name: 'Войти' }).click();
        cy.findByRole('alert').should('have.text', 'Неверное имя пользователя или пароль.');

        cy.visit('/login?lang=hy');
        cy.findByLabelText('Էլ. փոստ').type('nobody@taxikosmos.test');
        cy.findByLabelText('Գաղտնաբառ').type('Not-A-Password-1!');
        cy.findByRole('button', { name: 'Մուտք գործել' }).click();
        cy.findByRole('alert').should('have.text', 'Մուտքագրված տվյալները սխալ են։');
    });

    it('starts in the browser\'s language for a guest, and ignores languages it does not offer', () => {
        cy.visit('/login', { headers: { 'Accept-Language': 'ru-RU,ru;q=0.9,en;q=0.5' } });
        cy.findByRole('button', { name: 'Войти' }).should('be.visible');

        cy.clearAllCookies();
        cy.visit('/login', { headers: { 'Accept-Language': 'de-DE,de;q=0.9' } });
        cy.findByRole('button', { name: 'Sign in' }).should('be.visible');

        cy.clearAllCookies();
        cy.visit('/login?lang=de', { headers: { 'Accept-Language': 'hy' } });
        cy.findByRole('button', { name: 'Մուտք գործել' }).should('be.visible');
    });

    // Criterion: no hardcoded visible strings: every page of the route map is translated, in each language.
    [
        { email: 'cy-i18n-ru@taxikosmos.test', lang: 'ru', script: CYRILLIC, home: 'Панель управления', role: 'Суперадминистратор' },
        { email: 'cy-i18n-hy@taxikosmos.test', lang: 'hy', script: ARMENIAN, home: 'Վահանակ', role: 'Գլխավոր ադմինիստրատոր' },
    ].forEach(({ email, lang, script, home, role }) => {
        it(`renders every admin page in ${lang} (heading, menu and no console errors)`, () => {
            cy.loginAs(email);

            ROUTE_MAP.forEach((path) => {
                cy.visit(path);
                cy.get('html').should('have.attr', 'lang', lang);
                cy.get('main h1').first().invoke('text').should('match', script);
                // The signed-in user's role (sent by the server) is translated, and nothing of the page chrome stays English.
                cy.get('aside').should('contain', role);
                cy.get('header').should('contain', role);
                cy.get('aside, header, main h1').each(($el) => expect($el.text(), `English left in ${path}`).not.to.match(ENGLISH_CHROME));
            });

            cy.visit('/admin');
            cy.findByRole('heading', { name: home }).should('be.visible');
            cy.get('aside').invoke('text').should('match', script);
            cy.get('aside').invoke('text').should('not.match', /Riders|Drivers|Operations|Activity Log/);
        });
    });

    it('keeps English admins in English: no Russian or Armenian leaks into the page', () => {
        cy.loginAs('cy-i18n-en@taxikosmos.test');

        ['/admin/riders', '/admin/zones', '/admin/activity-logs', '/admin/trips'].forEach((path) => {
            cy.visit(path);
            cy.get('html').should('have.attr', 'lang', 'en');
            cy.get('main h1').first().invoke('text').should('match', /^[A-Za-z &]+$/);
            cy.get('aside').invoke('text').should('not.match', CYRILLIC).and('not.match', ARMENIAN);
        });

        cy.visit('/admin/riders');
        cy.contains('21 registered accounts').should('be.visible');
        cy.get('tbody tr').first().contains(/^.*[A-Z][a-z]{2} \d{1,2}, 20\d\d.*$/);
    });

    // Criterion: server messages follow the admin's language.
    it('shows toasts, validation errors and conflicts from the server in Russian', () => {
        cy.make('rider', { name: 'Rus Rider' }).then((rider) => {
            cy.loginAs('cy-i18n-ru@taxikosmos.test');
            cy.visit(`/admin/riders/${rider.id}`);

            cy.findByRole('button', { name: 'Заблокировать' }).click();
            cy.findByRole('dialog').within(() => {
                cy.findByLabelText(/Причина/).type('Повторные неявки');
                cy.findByRole('button', { name: 'Подтвердить блокировку' }).click();
            });
            cy.findByRole('status').should('contain', 'Пассажир заблокирован.');
            cy.contains('Заблокирован').should('be.visible');

            // Validation message next to the field, with the translated field name.
            cy.findByRole('button', { name: 'Редактировать' }).click();
            cy.findByRole('dialog').within(() => {
                cy.findByLabelText('Телефон').clear().type('abc');
                cy.findByLabelText(/Причина изменения/).type('Проверка');
                cy.findByRole('button', { name: 'Сохранить изменения' }).click();
                cy.findByLabelText('Телефон').parent().find('[role=alert]').should('have.text', 'Телефон должен быть действительным номером.');
            });
        });
    });

    it('shows validation errors and conflicts from the server in Armenian', () => {
        cy.make('rider', { name: 'Arm Rider' }).then((rider) => {
            cy.loginAs('cy-i18n-hy@taxikosmos.test');
            cy.visit(`/admin/riders/${rider.id}`);

            cy.adminRequest('POST', `/admin/riders/${rider.id}/reactivate`, { reason: 'x' }).its('status').should('eq', 302);
            cy.pageProps(`/admin/riders/${rider.id}`).its('flash.error').should('eq', 'Վերաակտիվացնել կարելի է միայն արգելափակված ուղևորներին։');

            cy.adminRequest('PATCH', `/admin/riders/${rider.id}`, { name: 'No reason' }).its('status').should('eq', 303);
            cy.pageProps(`/admin/riders/${rider.id}`).its('errors.reason').should('eq', 'Պատճառ դաշտը պարտադիր է։');
        });
    });

    // Text the server itself sends (placeholders, roles, who-did-what) is in the admin's language.
    it('shows the server\'s own display text in the admin\'s language: role, placeholders, activity and system actor', () => {
        cy.make('rider', { name: 'Anon', unnamed: true }).then((rider) => {
            cy.loginAs('cy-i18n-ru@taxikosmos.test');

            cy.visit(`/admin/riders?filter[search]=${encodeURIComponent(rider.code)}`);
            cy.contains('tbody tr', 'Пассажир без имени').should('contain', rider.code);

            cy.visit(`/admin/riders/${rider.id}`);
            cy.findByRole('button', { name: 'Заблокировать' }).click();
            cy.findByRole('dialog').within(() => {
                cy.findByLabelText(/Причина/).type('Повторные неявки');
                cy.findByRole('button', { name: 'Подтвердить блокировку' }).click();
            });
            cy.findByRole('status').should('contain', 'Пассажир заблокирован.');

            cy.visit(`/admin/riders/${rider.id}?tab=activity`);
            cy.contains('rider.suspended, выполнил(а): Rita Russian. Причина: Повторные неявки').should('be.visible');

            cy.make('system_audit_entry', { action: 'rider.updated', rider_id: rider.id });
            cy.visit(`/admin/riders/${rider.id}?tab=activity`);
            cy.contains('rider.updated, выполнил(а): Система').should('be.visible');

            cy.visit('/admin/activity-logs?filter[action]=rider.updated');
            cy.contains('tbody tr', 'rider.updated').should('contain', 'Система');

            // The same data for an Armenian admin.
            cy.loginAs('cy-i18n-hy@taxikosmos.test');
            cy.visit(`/admin/riders?filter[search]=${encodeURIComponent(rider.code)}`);
            cy.contains('tbody tr', 'Անանուն ուղևոր').should('exist');
            cy.visit(`/admin/riders/${rider.id}?tab=activity`);
            cy.contains('rider.suspended՝ կատարող՝ Rita Russian։ Պատճառ՝ Повторные неявки').should('be.visible');
            cy.visit('/admin/activity-logs?filter[action]=rider.updated');
            cy.contains('tbody tr', 'rider.updated').should('contain', 'Համակարգ');
        });

        // The stored audit text is not translated: an English admin reads the same entry as written.
        cy.loginAs('cy-i18n-en@taxikosmos.test');
        cy.visit('/admin/activity-logs?filter[action]=rider.suspended');
        cy.contains('tbody tr', 'Повторные неявки').should('contain', 'Rita Russian');
        cy.sql("select properties->>'reason' as reason, properties->>'actor_role' as role from activity_log where description = 'rider.suspended' order by id desc limit 1").then(([row]) => {
            expect(row).to.deep.equal({ reason: 'Повторные неявки', role: 'super_admin' });
        });
    });

    it('shows the missing-page and forbidden pages in the admin\'s language', () => {
        cy.loginAs('cy-i18n-ru@taxikosmos.test');
        cy.visit('/admin/riders/999999', { failOnStatusCode: false });
        cy.findByRole('heading', { name: 'Страница не найдена' }).should('be.visible');
        cy.get('html').should('have.attr', 'lang', 'ru');

        cy.make('admin', { email: 'cy-i18n-dispatch@taxikosmos.test', role: 'dispatcher', locale: 'hy' });
        cy.loginAs('cy-i18n-dispatch@taxikosmos.test');
        cy.visit('/admin/zones', { failOnStatusCode: false });
        cy.findByRole('heading', { name: 'Դուք հասանելիություն չունեք' }).should('be.visible');
    });

    // Plurals and number/date formats follow the language.
    it('uses the right plural forms, number grouping and date formats', () => {
        // Russian plural rule, written out independently of the app: 1 / 21 → one, 2-4 → few, 0, 5-20, 25 → many.
        const accounts = (n: number) => {
            const last = n % 10;
            const tens = n % 100;
            const form = last === 1 && tens !== 11 ? 'зарегистрированный аккаунт' : last >= 2 && last <= 4 && (tens < 12 || tens > 14) ? 'зарегистрированных аккаунта' : 'зарегистрированных аккаунтов';
            return `${n} ${form}`;
        };

        cy.loginAs('cy-i18n-ru@taxikosmos.test');
        cy.sql('select count(*)::int as n from users where is_rider').its('0.n').then((total) => {
            cy.visit('/admin/riders');
            cy.contains(accounts(total)).should('be.visible');
            cy.findByLabelText('Все статусы').should('be.visible');

            // Three more riders move the count to another plural form.
            cy.make('riders', { count: 3, prefix: 'Extra Rider', phone_offset: 500 });
            cy.visit('/admin/riders');
            cy.contains(accounts(total + 3)).should('be.visible');

            cy.visit('/admin/riders?filter[search]=Extra');
            cy.contains('3 результата').should('be.visible');
            cy.contains('Показано').scrollIntoView().invoke('text').should('eq', 'Показано 1–3 из 3');
            cy.get('tbody tr').first().contains(/\d{1,2} (янв|февр|мар|апр|мая|июн|июл|авг|сент|окт|нояб|дек)\.? 20\d\d г\./);

            cy.visit('/admin/riders?filter[search]=Extra+Rider+01');
            cy.contains('1 результат').should('be.visible');

            cy.loginAs('cy-i18n-hy@taxikosmos.test');
            cy.visit('/admin/riders');
            cy.contains(`${total + 3} գրանցված հաշիվ`).should('be.visible');
            cy.contains(`Ցուցադրվում է 1–10՝ ${total + 3}-ից`).scrollIntoView().should('be.visible');
        });
    });

    // The mobile API speaks the app's language.
    it('answers the mobile API in the language of Accept-Language', () => {
        cy.make('clear_rate_limits');
        cy.request({ method: 'POST', url: '/api/v1/auth/otp/verify', failOnStatusCode: false, headers: { 'Accept-Language': 'ru', Accept: 'application/json' }, body: { phone: '+37491770001', code: '000000', app: 'rider', device_id: 'cypress-i18n-1' } }).then((response) => {
            expect(response.status).to.equal(422);
            expect(response.body.message).to.equal('Код неверен или срок его действия истёк.');
            expect(response.headers['content-language']).to.equal('ru');
        });
        cy.request({ method: 'POST', url: '/api/v1/auth/otp/verify', failOnStatusCode: false, headers: { 'Accept-Language': 'hy-AM', Accept: 'application/json' }, body: { phone: '+37491770001', code: '000000', app: 'rider', device_id: 'cypress-i18n-1' } }).then((response) => {
            expect(response.body.message).to.equal('Կոդը սխալ է կամ ժամկետը լրացել է։');
        });
        cy.make('clear_rate_limits');
        cy.request({ method: 'POST', url: '/api/v1/auth/otp/request', failOnStatusCode: false, headers: { 'Accept-Language': 'de', Accept: 'application/json' }, body: { app: 'rider' } }).then((response) => {
            expect(response.status).to.equal(422);
            expect(response.body.errors.phone[0]).to.equal('The phone field is required.');
        });
        cy.make('clear_rate_limits'); // past the one-minute resend window
        cy.api('POST', '/api/v1/auth/otp/request', { app: 'rider' }).its('body.errors.phone.0').should('eq', 'The phone field is required.');
    });

    it('sends the one-time code text message in the app\'s language', () => {
        cy.make('clear_rate_limits');
        cy.request({ method: 'POST', url: '/api/v1/auth/otp/request', headers: { 'Accept-Language': 'ru', Accept: 'application/json' }, body: { phone: '+37491770002', app: 'rider' } }).its('status').should('eq', 202);
        cy.make('clear_rate_limits');
        cy.request({ method: 'POST', url: '/api/v1/auth/otp/request', headers: { 'Accept-Language': 'hy', Accept: 'application/json' }, body: { phone: '+37491770003', app: 'rider' } }).its('status').should('eq', 202);

        cy.readFile('storage/logs/e2e.log').then((log: string) => {
            const messages = log.split('\n').filter((line) => line.includes('SMS (log driver, not sent)')).slice(-2);
            expect(messages[0]).to.match(/Ваш код TaxiKosmos: \d{6}\. Он действует 5 минут\./);
            expect(messages[1]).to.match(/Ձեր TaxiKosmos կոդը՝ \d{6}։ Այն գործում է 5 րոպե։/);
        });
    });
});
