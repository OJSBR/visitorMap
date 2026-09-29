/**
 * @file cypress/tests/functional/VisitorMap.cy.js
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Functional tests: the manager enables the block, puts it in the sidebar and
 * sets it up; the settings are read back from the server; the reader sees the
 * map on the journal's home page, or nothing when there is no data.
 *
 * Parameters (--env): contextPath, adminUser, adminPassword (captcha on login
 * must be off for the run). The defaults match the data set of PKP's continuous
 * integration, which has no geographic statistics: there the reader must see no
 * block. Where the journal has them, expectedTotal (the accesses of the last 60
 * days, as the page prints them), expectedCountries and expectedTop (the name of
 * the first country) turn on the checks of the map itself.
 *
 * The settings page is loaded once for the whole run (a second load leaves the
 * web server of PKP's CI waiting until the page load times out), so test
 * isolation is off and the tests share it. Settings and sidebar are put back.
 */

describe('Visitor Map block', {testIsolation: false}, function() {
	const contextPath = Cypress.env('contextPath') || 'publicknowledge';
	const adminUser = Cypress.env('adminUser') || 'admin';
	const adminPassword = Cypress.env('adminPassword') || 'admin';
	const expectedTotal = Cypress.env('expectedTotal') ? String(Cypress.env('expectedTotal')) : null;
	const expectedCountries = Cypress.env('expectedCountries') ? String(Cypress.env('expectedCountries')) : null;
	const expectedTop = Cypress.env('expectedTop') || null;

	const rowName = 'visitormapplugin';
	const formSelector = 'form[id="visitorMapSettingsForm"]';
	const title = 'Mapa de teste ' + Date.now();
	let contextId = null;
	// What the journal had and what the settings form held, captured once and
	// put back in after().
	let original = null;
	let originalForm = null;

	// ---- OJSBR spec helpers (padrão v2) ----

	const pageUrl = (path) => '/index.php/' + contextPath + (path ? '/' + path : '');

	const waitJQuery = () => cy.window({timeout: 60000}).should((win) => expect(win.jQuery && win.jQuery.active).to.eq(0));

	const request = (options) => cy.window({log: false}).then((win) => cy.request(Object.assign(
		typeof options === 'string' ? {url: options} : options,
		{headers: Object.assign({'User-Agent': win.navigator.userAgent}, (typeof options === 'string' ? {} : options.headers) || {})}
	)));

	const login = (username, password) => {
		cy.clearCookies();
		request(pageUrl('login')).then((response) => {
			const token = /name="csrfToken" value="([^"]+)"/.exec(response.body)[1];
			const action = /<form[^>]*id="login"[^>]*action="([^"]+)"/.exec(response.body)[1];
			request({method: 'POST', url: action, form: true, body: {csrfToken: token, username: username, password: password}, log: false});
		});
		cy.visit(pageUrl('submissions') + '?reload=' + Date.now());
		cy.get('body').then(($body) => {
			if ($body.find('form#login').length) {
				cy.get('form#login input[name="username"]').type(username, {delay: 0});
				cy.get('form#login input[name="password"]').type(password, {delay: 0, log: false});
				cy.get('form#login').submit();
				cy.get('form#login', {timeout: 30000}).should('not.exist');
			}
		});
	};

	const api = (path) => cy.window({log: false}).then((win) => cy.wrap(
		win.fetch(path, {credentials: 'same-origin'}).then((response) => response.json()),
		{log: false, timeout: 30000}
	));

	const apiWrite = (method, path, body) => cy.window({log: false}).then((win) => cy.wrap(
		win.fetch(path, {
			method: method,
			credentials: 'same-origin',
			headers: {'Content-Type': 'application/json', 'X-Csrf-Token': win.pkp.currentUser.csrfToken},
			body: JSON.stringify(body),
		}).then((response) => response.status),
		{log: false, timeout: 30000}
	));

	// The URL behind the checkbox of the plugins grid, with the token of the page.
	const enablePlugin = () => cy.window({log: false}).then((win) => cy.wrap(
		win.fetch(pageUrl('$$$call$$$/grid/settings/plugins/settings-plugin-grid/enable') + '?plugin=' + rowName + '&category=blocks', {
			method: 'POST',
			credentials: 'same-origin',
			headers: {'Content-Type': 'application/x-www-form-urlencoded'},
			body: 'csrfToken=' + encodeURIComponent(win.pkp.currentUser.csrfToken) + '&plugin=' + rowName + '&category=blocks&disableNotification=1',
		}).then((response) => response.text()),
		{log: false, timeout: 30000}
	));

	const waitFormHandler = () => cy.window({timeout: 30000}).should((win) => {
		expect(win.jQuery(formSelector).data('pkp.handler'), 'form handler').to.exist;
	});

	// Opens the settings modal from the grid, on the page already open.
	const openSettings = () => {
		cy.get('a[id*="-row-' + rowName + '-settings-button-"]', {timeout: 30000}).then(($link) => {
			if (!$link.is(':visible')) {
				cy.get('tr[id$="-row-' + rowName + '"] a.show_extras').first().click();
			}
		});
		cy.get('a[id*="-row-' + rowName + '-settings-button-"]').first().click({force: true});
		waitJQuery();
		waitFormHandler();
	};

	// ---- end of helpers ----

	before(function() {
		login(adminUser, adminPassword);

		enablePlugin().then((answer) => {
			expect(answer, 'the plugin was switched on').to.contain('"status":true');
		});

		cy.window({log: false}).then((win) => cy.wrap(
			win.fetch(pageUrl('api/v1/contexts?isEnabled=true&count=100'), {credentials: 'same-origin'}).then((response) => response.json())
		)).then((contexts) => {
			const context = (contexts.items || []).find((item) => item.urlPath === contextPath);
			expect(context, 'the journal of this path').to.exist;
			contextId = context.id;
		});
		cy.then(() => api(pageUrl('api/v1/contexts/' + contextId))).then((context) => {
			if (original === null) {
				original = {sidebar: context.sidebar || []};
			}
		});

		// The one load of the settings page.
		cy.visit(pageUrl('management/settings/website') + '?reload=' + Date.now());
	});

	after(function() {
		cy.window({log: false}).then((win) => {
			if (!win.pkp || !win.pkp.currentUser) {
				login(adminUser, adminPassword);
			}
		});
		cy.then(() => original && apiWrite('PUT', pageUrl('api/v1/contexts/' + contextId), {sidebar: original.sidebar}));
		// The settings go back through the plugin's own form, as the manager would save them.
		cy.window({log: false}).then((win) => {
			if (!originalForm) {
				return;
			}
			const body = new URLSearchParams();
			originalForm.data.forEach((field) => body.append(field.name, field.value));
			body.append('csrfToken', win.pkp.currentUser.csrfToken);
			return cy.wrap(win.fetch(originalForm.action, {method: 'POST', credentials: 'same-origin', body: body}).then((response) => response.text()))
				.then((answer) => expect(answer, 'the settings were put back').to.contain('"status":true'));
		});
		cy.then(() => api(pageUrl('api/v1/contexts/' + contextId))).then((context) => {
			expect(context.sidebar || [], 'the sidebar was put back').to.deep.eq(original.sidebar);
		});
	});

	it('is put in the sidebar through the appearance form', function() {
		cy.get('button[id="appearance-button"]', {timeout: 60000}).click();
		cy.get('button[id="appearance-setup-button"]').click();
		cy.intercept({method: 'POST', url: '**/api/v1/contexts/*'}).as('savedAppearance');
		cy.get('input[type="checkbox"][value="' + rowName + '"]', {timeout: 30000}).then(($box) => {
			if (!$box.is(':checked')) {
				cy.wrap($box).check({force: true});
			}
		});
		cy.get('input[type="checkbox"][value="' + rowName + '"]').parents('form').first()
			.find('.pkpFormPage__footer button').last().click();
		cy.wait('@savedAppearance', {timeout: 30000}).its('response.statusCode').should('eq', 200);

		cy.then(() => api(pageUrl('api/v1/contexts/' + contextId))).then((context) => {
			expect(context.sidebar, 'the journal keeps the block in its sidebar').to.include(rowName);
		});
	});

	it('keeps the settings the manager saves', function() {
		cy.get('button[id="plugins-button"]').click();
		waitJQuery();
		openSettings();
		cy.get(formSelector).then(($form) => {
			if (originalForm === null) {
				originalForm = {action: $form.attr('action'), data: $form.serializeArray().filter((field) => field.name !== 'csrfToken')};
			}
		});

		cy.get(formSelector + ' input[name="days"]').clear().type('60', {delay: 0});
		cy.get(formSelector + ' input[name="topCount"]').clear().type('5', {delay: 0});
		cy.get(formSelector + ' select[name="metric"]').select('unique');
		cy.get(formSelector + ' input[name="excludedCountries"]').clear();
		cy.get(formSelector + ' input[name="startDate"]').invoke('val', '').trigger('input', {force: true});
		cy.get(formSelector + ' input[name="colorHighlight"]').invoke('val', '#aa3300').trigger('input', {force: true});
		cy.get(formSelector + ' input[name="showSummary"]').check({force: true});
		// The same title in every language of the journal, whichever the reader uses.
		cy.get(formSelector + ' input[name^="blockTitle["]').each(($input) => cy.wrap($input).invoke('val', title).trigger('input', {force: true}));
		waitFormHandler();
		cy.get(formSelector + ' button[type="submit"], ' + formSelector + ' button.submitFormButton').first().click();
		waitJQuery();
		cy.get(formSelector).should('not.exist');

		// What the server kept, in a form it sends again.
		openSettings();
		cy.get(formSelector + ' input[name="days"]').should('have.value', '60');
		cy.get(formSelector + ' input[name="topCount"]').should('have.value', '5');
		cy.get(formSelector + ' input[name="colorHighlight"]').should('have.value', '#aa3300');
		cy.get(formSelector + ' input[name^="blockTitle["]').first().should('have.value', title);

		// A value out of range is refused with the plugin's message.
		cy.get(formSelector + ' input[name="days"]').clear().type('9999', {delay: 0});
		waitFormHandler();
		cy.get(formSelector + ' button[type="submit"], ' + formSelector + ' button.submitFormButton').first().click();
		waitJQuery();
		cy.get(formSelector).should('exist');
		cy.get(formSelector + ' .pkp_form_error, ' + formSelector + ' label.error').should('exist');
	});

	(expectedTotal ? it : it.skip)('shows the map to the reader', function() {
		cy.clearCookies();
		// A new query string gets past the web server's cache of anonymous pages.
		cy.visit(pageUrl('') + '?cb=' + Date.now());
		cy.get('.block_visitor_map', {timeout: 30000}).should('exist');
		cy.get('.block_visitor_map .title').should('contain', title);
		cy.get('.block_visitor_map img.visitor_map__image')
			.should('have.attr', 'src').and('match', /\/visitorMap-[0-9a-f]{16}\.svg$/);
		// The map loads lazily: the browser fetches it when the reader gets near it.
		cy.get('.block_visitor_map img.visitor_map__image').should('have.attr', 'loading', 'lazy').scrollIntoView();
		cy.get('.block_visitor_map img.visitor_map__image', {timeout: 30000}).should(($img) => {
			expect($img[0].complete, 'the map finished loading').to.be.true;
			expect($img[0].naturalWidth, 'the map is an image the browser could draw').to.be.greaterThan(0);
		});
		cy.get('.block_visitor_map .visitor_map__summary dd').first().should('have.text', expectedTotal);
		if (expectedCountries) {
			cy.get('.block_visitor_map .visitor_map__summary dd').eq(1).should('have.text', expectedCountries);
		}
		cy.get('.block_visitor_map .visitor_map__top li').should('have.length', 5);
		if (expectedTop) {
			cy.get('.block_visitor_map .visitor_map__country').first().should('have.text', expectedTop);
		}
		cy.get('.block_visitor_map .visitor_map__period').should('contain', '60');
		// The map is the colour the manager chose.
		cy.get('.block_visitor_map img.visitor_map__image').invoke('attr', 'src').then((src) => {
			cy.request(src).then((response) => {
				expect(response.headers['content-type'], 'served as an SVG image').to.contain('image/svg+xml');
				expect(response.body).to.contain('.c5{fill:#aa3300}');
			});
		});
	});

	(expectedTotal ? it.skip : it)('shows nothing to the reader when there is no data', function() {
		cy.clearCookies();
		cy.visit(pageUrl('') + '?cb=' + Date.now());
		cy.get('.pkp_structure_sidebar, body', {timeout: 30000}).should('exist');
		cy.get('.block_visitor_map').should('not.exist');
	});
});
