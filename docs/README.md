# Free web GUI for MongoDB

Visually administrate your MongoDB database. Create, read, update & delete your documents.<br>
Query your MongoDB database with a [relax JSON syntax](#relaxed-json) and regular expressions.<br>
Autocompletion is available for collection fields and MongoDB keywords via [key shortcuts](#key-shortcuts).<br>
Export documents to JSON. Import documents from JSON. Manage indexes. Manage users, etc.  
Runs as **nginx + PHP-FPM** (non-root) in Docker or Kubernetes.

## Screenshots

![MongoDB PHP GUI - Visualize Database](screenshots/visualize-database.png)

![MongoDB PHP GUI - Query Database](screenshots/query-database.png)

![MongoDB PHP GUI - Query Documents](screenshots/query-documents.png)

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

## Building from source

```
git clone <this repository>
cd mongodb-php-gui
docker build -t mongodb-php-gui:latest .
```

The multi-stage build compiles the `mongodb` PHP extension (version pinned in the `Dockerfile`) and installs all PHP dependencies from `composer.lock` — no manual steps are needed.

## Environment variables

| Variable                          | Default    | Description                                                                                                         |
| --------------------------------- | ---------- | ------------------------------------------------------------------------------------------------------------------- |
| `MPG_ALLOWED_MONGODB_HOSTS`       | *(empty)*  | Comma-separated exact hostnames allowed for connections. Empty = warn only.                                         |
| `MPG_ALLOWED_MONGODB_DOMAINS`     | *(empty)*  | Comma-separated domain suffixes allowed (boundary-aware: `xexample.com` does not match `example.com`).              |
| `MPG_DEFAULT_DOCUMENTS`           | `100`      | Default page size for queries.                                                                                      |
| `MPG_MAX_DOCUMENTS`               | `1000000`  | Hard cap for documents returned/affected by a single operation. **Override it (500-1000) in production manifests.** |
| `MPG_QUERY_MAX_TIME_MS`           | `60000`    | `maxTimeMS` applied to reads (ceiling: 600000). Writes are not bounded.                                             |
| `MPG_SERVER_SELECTION_TIMEOUT_MS` | `5000`     | Driver server selection timeout.                                                                                    |
| `MPG_CONNECT_TIMEOUT_MS`          | `5000`     | Driver connection timeout.                                                                                          |
| `MPG_SOCKET_TIMEOUT_MS`           | `10000`    | Driver socket timeout.                                                                                              |
| `MPG_MAX_IMPORT_SIZE`             | `10485760` | Maximum import file size in bytes (drives `upload_max_filesize` and nginx `client_max_body_size`).                  |
| `MPG_MAX_IMPORT_DOCUMENTS`        | `10000`    | Maximum number of documents in an import file.                                                                      |
| `MPG_COOKIE_SECURE`               | `0`        | Set `1` when TLS terminates at the ingress.                                                                         |
| `MPG_DEBUG`                       | `0`        | Set `1` to include error details in responses (development only).                                                   |

## Security

The application is hardened:

* **MongoDB URI allowlist** — `MPG_ALLOWED_MONGODB_HOSTS` / `MPG_ALLOWED_MONGODB_DOMAINS` restrict which servers can be connected to (exact hosts and boundary-aware domain suffixes, port validated to 1–65535, strict `mongodb+srv` handling, every host of a replica-set seed list is checked).
* **CSRF protection** on every mutating (POST) route; the token is per-session and rotated on login.
* **Per-session MongoDB clients** — one user cannot see another user's connection or credentials; the client is dropped on logout.
* **Query and import limits** — `MPG_MAX_DOCUMENTS` document cap, `maxTimeMS` on reads, bounded import size and document count (over-size uploads are rejected with HTTP 413).
* **Safe errors** — error responses never leak credentials or connection details (set `MPG_DEBUG=1` in development only).
* **Audit log** — every mutating operation (insert/update/delete/import, collection, index and user changes) is written to `stderr`.
* **Container hardening** — non-root (UID 808), works with a read-only root filesystem (Kubernetes), all logs go to stdout/stderr only.

### Production notes

* Set `MPG_ALLOWED_MONGODB_HOSTS` / `MPG_ALLOWED_MONGODB_DOMAINS` — without them any MongoDB host is allowed (a warning is logged).
* Lower `MPG_MAX_DOCUMENTS` to `500`–`1000` (the code default is 1 000 000).
* Set `MPG_COOKIE_SECURE=1` when TLS terminates at the ingress.

## Tests

Security regression suite (CSRF chokepoint on all POST routes, failed-login form, MongoDB URI/allowlist validation):

```
docker run --rm --entrypoint php -v "$PWD/tests":/app/tests mongodb-php-gui:latest /app/tests/csrf_routes_test.php
```

## Credits

Originally created by Samuel Tallet (https://github.com/SamuelTallet).  
This repo is a hardened fork of his MongoDB-PHP-GUI (https://github.com/SamuelTallet/MongoDB-PHP-GUI).
