
/**
 * MongoDB PHP GUI namespace.
 * 
 * @type {object}
 */
var MPG = {};

/**
 * Name of current database.
 * 
 * @type {string}
 */
MPG.databaseName = '';

/**
 * Name of current collection.
 * 
 * @type {string}
 */
MPG.collectionName = '';

/**
 * To do list sub-namespace.
 * 
 * @type {object}
 */
MPG.toDoList = {};

/**
 * Name of collection to reselect.
 * XXX Used for navigation.
 * 
 * @type {string}
 */
MPG.toDoList.reselectCollection = '';

/**
 * Helpers sub-namespace.
 * 
 * @type {object}
 */
MPG.helpers = {};

/**
 * Does an ajax request.
 * 
 * @param {string} method 
 * @param {string} url 
 * @param {function} successCallback 
 * @param {?string} body
 * 
 * @returns {void}
 */
MPG.helpers.doAjaxRequest = function(method, url, successCallback, body) {

    var xhr = new XMLHttpRequest();

    xhr.addEventListener('readystatechange', function() {

        if ( this.readyState === 4 ) {
            if ( this.status === 200 ) {
                successCallback(this.responseText);
            } else {
                window.alert('Error: ' + JSON.parse(this.responseText).error.message);
            }
        }

    });

    xhr.open(method, url);

    if ( method.toUpperCase() === 'POST' ) {

        var csrfMeta = document.querySelector('meta[name="mpg-csrf-token"]');

        if ( csrfMeta && csrfMeta.content !== '' ) {
            xhr.setRequestHeader('X-CSRF-Token', csrfMeta.content);
        }

    }

    xhr.send(body);

};

/**
 * Navigates on same page. Example: reselects database and collection after a refresh.
 * 
 * @returns {void}
 */
MPG.helpers.navigateOnSamePage = function() {

    var fragmentUrl = window.location.hash.split('#');
    var databaseAndCollectionName;
    var databaseSelector;
    var database;

    if ( fragmentUrl.length === 2 && fragmentUrl[1] !== '' ) {

        databaseAndCollectionName = fragmentUrl[1].split('/');

        if ( databaseAndCollectionName.length === 1 ) {

            databaseSelector = '.mpg-database-link' + '[data-database-name="'
                + databaseAndCollectionName[0] + '"]';

            database = document.querySelector(databaseSelector);

            if ( database ) {
                database.click();
            } else {
                window.alert('Error: Database not found. Select another one.');
                window.location.hash = '';
            }

        } else if ( databaseAndCollectionName.length === 2 ) {

            MPG.toDoList.reselectCollection = databaseAndCollectionName[1];

            databaseSelector = '.mpg-database-link' + '[data-database-name="'
                + databaseAndCollectionName[0] + '"]';

            database = document.querySelector(databaseSelector);

            if ( database ) {
                database.click();
            } else {
                window.alert('Error: Database not found. Select another one.');
                window.location.hash = '';
            }

        }

    }

};

/**
 * Completes navigation links with an URL fragment.
 * 
 * @param {string} urlFragment
 * 
 * @returns {void}
 */
MPG.helpers.completeNavLinks = function(urlFragment) {

    document.querySelectorAll('.nav-link[data-canonical-url]').forEach(function(navLink) {
        navLink.href = navLink.dataset.canonicalUrl + urlFragment;
    });

};

/**
 * Escapes HTML tags and entities.
 * This prevents HTML stored in MongoDB documents to be interpreted by browser.
 * 
 * @param {string} html
 * 
 * @returns {string}
 */
MPG.helpers.escapeHTML = function(html) {

    return html
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');

};

/**
 * Unescapes HTML tags and entities.
 *
 * @param {string} html
 *
 * @returns {string}
 */
MPG.helpers.unescapeHTML = function(html) {

    return html
        .replace(/&lt;/g, '<')
        .replace(/&gt;/g, '>')
        .replace(/&quot;/g, '"')
        .replace(/&#39;/g, "'")
        .replace(/&amp;/g, '&');

};

/**
 * Reloads collections of a specific database.
 * 
 * @param {string} databaseName
 * 
 * @returns {void}
 */
MPG.reloadCollections = function(databaseName) {

    var requestBody = { 'databaseName': databaseName };

    MPG.helpers.doAjaxRequest(
        'POST', './listCollections', function(response) {

            var collectionsList = document.querySelector('#mpg-collections-list');

            collectionsList.textContent = '';
            MPG.collectionName = '';

            JSON.parse(response).forEach(function(collectionName) {

                var listItem = document.createElement('li');
                listItem.className = 'collection-name';

                var icon = document.createElement('i');
                icon.className = 'fa fa-file-text';
                icon.setAttribute('aria-hidden', 'true');

                var collectionLink = document.createElement('a');
                collectionLink.className = 'mpg-collection-link';
                collectionLink.setAttribute('data-collection-name', collectionName);
                collectionLink.setAttribute('href', '#' + MPG.databaseName + '/' + collectionName);
                collectionLink.textContent = collectionName;

                listItem.appendChild(icon);
                listItem.appendChild(document.createTextNode(' '));
                listItem.appendChild(collectionLink);

                collectionsList.appendChild(listItem);

            });

            MPG.eventListeners.addCollections();

        },
        JSON.stringify(requestBody)
    );

};

/**
 * Event listeners sub-namespace.
 * 
 * @type {object}
 */
MPG.eventListeners = {};

/**
 * Adds an event listener on "Menu toggle" button.
 * 
 * @returns {void}
 */
MPG.eventListeners.addMenuToggle = function() {

    document.querySelector('#menu-toggle-button').addEventListener('click', function(_event) {
        document.querySelector('.navbar').classList.toggle('menu-expanded');
    });

};

/**
 * Adds an event listener on each database.
 * 
 * @returns {void}
 */
MPG.eventListeners.addDatabases = function() {

    document.querySelectorAll('.mpg-database-link').forEach(function(databaseLink) {

        databaseLink.addEventListener('click', function(_event) {

            MPG.databaseName = databaseLink.dataset.databaseName;
            MPG.helpers.completeNavLinks('#' + MPG.databaseName);

            document.querySelectorAll('.mpg-database-link').forEach(function(databaseLink) {
                databaseLink.classList.remove('active');
            });

            databaseLink.classList.add('active');

            MPG.reloadCollections(databaseLink.dataset.databaseName);

        });

    });

};

/**
 * Adds an event listener on each collection.
 * 
 * @returns {void}
 */
MPG.eventListeners.addCollections = function() {

    document.querySelectorAll('.mpg-collection-link').forEach(function(collectionLink) {

        collectionLink.addEventListener('click', function(_event) {
            
            MPG.collectionName = collectionLink.dataset.collectionName;
            MPG.helpers.completeNavLinks('#' + MPG.databaseName + '/' + MPG.collectionName);

            document.querySelectorAll('.mpg-collection-link').forEach(function(collectionLink) {
                collectionLink.classList.remove('active');
            });

            collectionLink.classList.add('active');

        });

    });

    if ( MPG.toDoList.reselectCollection !== '' ) {

        var collectionSelector = '.mpg-collection-link' + '[data-collection-name="'
            + MPG.toDoList.reselectCollection + '"]';

        var collection = document.querySelector(collectionSelector);

        if ( collection ) {
            collection.click();
        } else {
            window.alert('Error: Collection not found. Select another one.');
            window.location.hash = '';
        }

        MPG.toDoList.reselectCollection = '';

    }

};

/**
 * Adds an event listener on dismissible alerts.
 * 
 * @returns {void}
 */
MPG.eventListeners.addDismissibleAlerts = function() {

    document.querySelectorAll('.alert [data-dismiss="alert"]')
        .forEach(function(alertCloseButton) {

        alertCloseButton.addEventListener('click', function(event) {

            var alert = document.getElementById(event.currentTarget.dataset.alertId);
            alert.classList.add('d-none');

        });
        
    });

};

/**
 * Adds an event listener on buttons that open modals.
 * 
 * @returns {void}
 */
MPG.eventListeners.addModalOpen = function() {

    document.querySelectorAll('.btn[data-open="modal"]')
        .forEach(function(modalOpenButton) {

        modalOpenButton.addEventListener('click', function(event) {

            var modal = document.getElementById(event.currentTarget.dataset.modalId);
            modal.classList.add('d-block');

        });
        
    });

};

/**
 * Adds an event listener on buttons that close modals.
 * 
 * @returns {void}
 */
MPG.eventListeners.addModalClose = function() {

    document.querySelectorAll('.btn[data-dismiss="modal"]')
        .forEach(function(modalCloseButton) {

        modalCloseButton.addEventListener('click', function(event) {

            var modal = document.getElementById(event.currentTarget.dataset.modalId);
            modal.classList.remove('d-block');

        });
        
    });

};
