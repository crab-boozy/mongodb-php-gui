
/**
 * Field names of current collection.
 * 
 * @type {Array}
 */
MPG.collectionFields = [];

/**
 * Indexes of current collection.
 * 
 * @type {Array}
 */
MPG.collectionIndexes = [];

/**
 * Reloads fields of current collection.
 * 
 * @returns {void}
 */
MPG.reloadCollectionFields = function() {

    var requestBody = {
        'databaseName': MPG.databaseName,
        'collectionName': MPG.collectionName
    };

    MPG.helpers.doAjaxRequest(
        'POST',
        './enumCollectionFields',
        function(response) {

            JSON.parse(response).forEach(function(collectionField) {
                if ( typeof collectionField === 'string' ) {
                    MPG.collectionFields.push(collectionField);
                }
            });

            var indexableFieldsList = document.querySelector('#mpg-indexable-fields-list');
            
            if ( MPG.collectionFields.length === 0 ) {
                indexableFieldsList.innerHTML = '<li><i>Collection is empty.</i></li>';
                return;
            }

            indexableFieldsList.innerHTML = '';
        
            MPG.collectionFields.forEach(function(collectionField) {
        
                var listItem = document.createElement('li');

                var checkbox = document.createElement('input');
                checkbox.type = 'checkbox';
                checkbox.className = 'mpg-collection-field-checkbox';
                checkbox.value = collectionField;
                if ( collectionField === '_id' ) {
                    checkbox.disabled = true;
                }

                listItem.appendChild(checkbox);
                listItem.appendChild(document.createTextNode(' ' + collectionField + ' '));

                indexableFieldsList.appendChild(listItem);
        
            });

        },
        JSON.stringify(requestBody)
    );

};

/**
 * Reloads indexes of current collection.
 * 
 * @returns {void}
 */
MPG.reloadCollectionIndexes = function() {

    var requestBody = {
        'databaseName': MPG.databaseName,
        'collectionName': MPG.collectionName
    };

    MPG.helpers.doAjaxRequest(
        'POST',
        './listIndexes',
        function(response) {

            MPG.collectionIndexes = JSON.parse(response);
            
            var indexesTableBody = document.querySelector('#mpg-indexes-table tbody');
            indexesTableBody.innerHTML = '';
            
            MPG.collectionIndexes.forEach(function(collectionIndex) {

                var tableRow = document.createElement('tr');

                var nameCell = document.createElement('td');
                nameCell.textContent = collectionIndex.name;
                tableRow.appendChild(nameCell);

                var keysCell = document.createElement('td');

                for (var collectionIndexKey in collectionIndex.keys) {

                    if ( !collectionIndex.keys.hasOwnProperty(collectionIndexKey) ) {
                        continue;
                    }

                    var collectionIndexOrder = ' (ASC) ';

                    if ( collectionIndex.keys[collectionIndexKey] === -1 ) {
                        collectionIndexOrder = ' (DESC) ';
                    }

                    keysCell.appendChild(document.createTextNode(collectionIndexKey + collectionIndexOrder));

                }

                tableRow.appendChild(keysCell);

                var isUniqueCell = document.createElement('td');
                isUniqueCell.textContent = ( collectionIndex.isUnique ? 'Yes' : 'No' );
                tableRow.appendChild(isUniqueCell);

                var actionCell = document.createElement('td');

                var collectionIndexDropButton = document.createElement('button');
                collectionIndexDropButton.setAttribute('data-index-name', collectionIndex.name);
                collectionIndexDropButton.className = 'mpg-index-drop-button btn btn-danger';
                collectionIndexDropButton.textContent = 'Drop index';
                actionCell.appendChild(collectionIndexDropButton);

                tableRow.appendChild(actionCell);

                indexesTableBody.appendChild(tableRow);

            });

            MPG.eventListeners.addDropIndex();

            document.querySelector('#mpg-indexes-column').classList.remove('d-none');

        },
        JSON.stringify(requestBody)
    );

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

            document.querySelector('#mpg-indexable-fields-list').innerHTML =
                '<li><i>Please select a collection.</i></li>';

            document.querySelector('#mpg-indexes-column').classList.add('d-none');

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

            MPG.collectionFields = [];

            MPG.reloadCollectionFields();
            MPG.reloadCollectionIndexes();

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
 * Adds an event listener on "Create index" button.
 * 
 * @returns {void}
 */
MPG.eventListeners.addCreateIndex = function() {

    document.querySelector('#mpg-create-index-button')
        .addEventListener('click', function(_event) {

        if ( MPG.databaseName === '' || MPG.collectionName === '' ) {
            return window.alert('Please select a database and a collection.');
        }

        var indexKeys = document.querySelectorAll('.mpg-collection-field-checkbox:checked');

        if ( indexKeys.length === 0 ) {
            return window.alert('Please select one or many fields.');
        }

        // TODO: Manage index order by field.
        var indexOrder = parseInt(document.querySelector('#mpg-index-order-select').value);
        var uniqueIndex = document.querySelector('#mpg-unique-index-select').value;
        var indexIsUnique = ( uniqueIndex === 'true' ) ? true : false;
        
        var requestBody = {
            'databaseName': MPG.databaseName,
            'collectionName': MPG.collectionName
        };

        requestBody.key = {};
        requestBody.options = { "unique" : indexIsUnique };

        indexKeys.forEach(function(indexKey) {
            requestBody.key[indexKey.value] = indexOrder;
        });

        MPG.helpers.doAjaxRequest(
            'POST',
            './createIndex',
            function(response) {

                var indexCreated = document.querySelector('#mpg-index-created');
                var indexCreatedText = document.querySelector('#mpg-index-created .text');

                indexCreated.classList.remove('d-none');
                indexCreatedText.textContent
                    = 'Success: Index created with name ' + response + '.';

                MPG.reloadCollectionIndexes();

            },
            JSON.stringify(requestBody)
        );

    });

};

/**
 * Adds an event listener on "Drop index" buttons.
 * 
 * @returns {void}
 */
MPG.eventListeners.addDropIndex = function() {

    var indexDropButtons = document.querySelectorAll('.mpg-index-drop-button');

    indexDropButtons.forEach(function(indexDropButton) {

        indexDropButton.addEventListener('click', function(_event) {

            var requestBody = {
                'databaseName': MPG.databaseName,
                'collectionName': MPG.collectionName,
                'indexName': indexDropButton.dataset.indexName
            };

            MPG.helpers.doAjaxRequest(
                'POST',
                './dropIndex',
                function(response) {
    
                    if ( JSON.parse(response) === true ) {
                        MPG.reloadCollectionIndexes();
                    }
    
                },
                JSON.stringify(requestBody)
            );

        });

    });

};

// When document is ready:
window.addEventListener('DOMContentLoaded', function(_event) {

    MPG.eventListeners.addMenuToggle();
    MPG.eventListeners.addDatabases();
    MPG.eventListeners.addCreateIndex();
    MPG.eventListeners.addDismissibleAlerts();

    MPG.helpers.navigateOnSamePage();

});
