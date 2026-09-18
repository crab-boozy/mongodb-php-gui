# Free MongoDB GUI powered by PHP

Visually administrate your MongoDB database. Create, read, update & delete your documents.<br>
Query your MongoDB database with a [relax JSON syntax](#relaxed-json) and regular expressions.<br>
Autocompletion is available for collection fields and MongoDB keywords via [key shortcuts](#key-shortcuts).<br>
Export documents to JSON. Import documents from JSON. Manage indexes. Manage users, etc.

## Screenshots

![MongoDB PHP GUI - Visualize Database](https://raw.githubusercontent.com/SamuelTallet/MongoDB-PHP-GUI/master/docs/screenshots/visualize-database.png)

![MongoDB PHP GUI - Query Documents](https://raw.githubusercontent.com/SamuelTallet/MongoDB-PHP-GUI/master/docs/screenshots/query-documents.png)

## Installation

### Docker (nginx + PHP-FPM, non-root, port 8080)
1. In a case of an upgrade, run `docker pull samueltallet/mongodb-php-gui`<br>
2. Always run `docker run --add-host localhost:172.17.0.1 --publish 8080:8080 --rm samueltallet/mongodb-php-gui`<br>
3. Open your browser at this address: http://127.0.0.1:8080/ to access GUI.<br>
4. Kubernetes/Compose manifests live in `k8s/` and `compose.yml`. Public probes: `/health` (liveness) and `/ready` (readiness).<br>
5. Configure the application with `MPG_*` environment variables (see the table below). In production set `MPG_ALLOWED_MONGODB_HOSTS`/`MPG_ALLOWED_MONGODB_DOMAINS` to restrict which MongoDB hosts can be connected to.

#### Environment variables

| Variable | Default | Description |
|----------|---------|-------------|
| `MPG_ALLOWED_MONGODB_HOSTS` | *(empty)* | Comma-separated exact hostnames allowed for connections. Empty = warn only. |
| `MPG_ALLOWED_MONGODB_DOMAINS` | *(empty)* | Comma-separated domain suffixes allowed (boundary-aware: `xexample.com` does not match `example.com`). |
| `MPG_DEFAULT_DOCUMENTS` | `100` | Default page size for queries. |
| `MPG_MAX_DOCUMENTS` | `1000000` | Hard cap for documents returned/affected by a single operation. **Override it (500-1000) in production manifests.** |
| `MPG_QUERY_MAX_TIME_MS` | `60000` | `maxTimeMS` applied to reads (ceiling: 600000). Writes are not bounded. |
| `MPG_SERVER_SELECTION_TIMEOUT_MS` | `5000` | Driver server selection timeout. |
| `MPG_CONNECT_TIMEOUT_MS` | `5000` | Driver connection timeout. |
| `MPG_SOCKET_TIMEOUT_MS` | `10000` | Driver socket timeout. |
| `MPG_MAX_IMPORT_SIZE` | `10485760` | Maximum import file size in bytes (drives `upload_max_filesize` and nginx `client_max_body_size`). |
| `MPG_MAX_IMPORT_DOCUMENTS` | `10000` | Maximum number of documents in an import file. |
| `MPG_COOKIE_SECURE` | `0` | Set `1` when TLS terminates at the ingress. |
| `MPG_DEBUG` | `0` | Set `1` to include error details in responses (development only). |

### Apache HTTP server
1. Clone current repository in a folder served by Apache.
2. Be sure to have PHP >= 8.4 with [MongoDB extension](https://www.php.net/manual/en/mongodb.installation.php) enabled.
3. Check that `rewrite_module` module is enabled in your Apache configuration.
4. Be sure to have `AllowOverride All` in your Apache (virtual host) configuration.
5. Run `composer install` at project's root directory to install all PHP dependencies.
6. Open your browser at Apache server URL to access GUI.

## Usage

### Query Syntax

#### Relaxed JSON

MongoDB PHP GUI supports a relaxed JSON syntax. In practice, this query:

```js
city: New York
```

Will produce same result that:

```js
{ "city": "New York" }
```

#### Regular Expressions

Imagine you want to find all the US cities starting with "San An". This query:

```js
city: /^San An/
```

Will output:
- San Antonio (FL)
- San Angelo (TX)
- ...

### Key Shortcuts

<kbd>Ctrl + Space</kbd> Autocomplete the query<br>
<kbd>Ctrl + *</kbd> Count doc(s) matching the query<br>
<kbd>Ctrl + Enter</kbd> Find doc(s) matching the query

## Credits

This GUI uses [Limber](https://github.com/nimbly/Limber), [Capsule](https://github.com/nimbly/Capsule), [Font Awesome](https://fontawesome.com/), [Bootstrap](https://getbootstrap.com/), [CodeMirror](https://github.com/codemirror/codemirror), [jsonic](https://github.com/jsonicjs/jsonic), [JsonView](https://github.com/pgrabovets/json-view), [MongoDB PHP library](https://github.com/mongodb/mongo-php-library) and [vis.js](https://github.com/visjs). Leaf icon was made by [Freepik](https://www.freepik.com) from [Flaticon](https://www.flaticon.com).

## Funding

If you find this GUI useful, [donate](https://www.paypal.me/SamuelTallet) at least one dollar to support its development. Thank you to all! ❤️

## Copyright

© 2025 Samuel Tallet
