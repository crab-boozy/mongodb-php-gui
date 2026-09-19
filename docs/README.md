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

Two suites, both executed on every push/PR by the `Tests` GitHub workflow:

**In-process regression suite** — runs without a live MongoDB (CSRF chokepoint on all POST routes, failed-login form, MongoDB URI/allowlist validation, credential masking in error output, audit log format, open-redirect prefix guard, find-options validation, session/client cleanup):

```
docker run --rm --entrypoint php -v "$PWD/tests":/app/tests mongodb-php-gui:latest /app/tests/csrf_routes_test.php
```

**E2E suite** (`tests/e2e_test.php`) — drives the full application (nginx + PHP-FPM + a live `mongo:7` provided by the workflow) over real HTTP: the successful-login lifecycle (session/CSRF rotation), insert/count/find/update/delete, multipart import, index lifecycle and the audit trail. Locally, with your own app instance (e.g. compose on port 8080) and a local MongoDB:

```
docker run -d --name mpg-mongo -p 27017:27017 mongo:7
docker run --rm --network host --entrypoint php \
  -e MPG_TEST_MONGO_URI=mongodb://127.0.0.1:27017 \
  -e MPG_E2E_BASE_URL=http://127.0.0.1:8080 \
  -v "$PWD/tests":/app/tests mongodb-php-gui:latest /app/tests/e2e_test.php
```

Without `MPG_TEST_MONGO_URI` the E2E suite prints `SKIP` and exits 0.

Both suites report in JUnit XML, so every run shows a **Test results** tab on the GitHub Actions page (pass/fail/skip per check).

### Manual checks

The login lifecycle is covered automatically by the E2E suite. Before a rollout, walk through these scenarios once against a real deployment:

- [ ] **Replica-set failover** — log in with a multi-seed URI (`mongodb://rs1:27017,rs2:27017,rs3:27017/?replicaSet=rs0`), stop the PRIMARY, and verify the next query still succeeds.
- [ ] **Upload limits** — a ~9 MiB JSON import succeeds; a ~10.5 MiB file is rejected with an HTTP 413 JSON body (application guard); a ~13 MiB file is rejected with an HTTP 413 HTML body (nginx).
- [ ] **Read-only root filesystem** — run with `--read-only` plus tmp volumes for `/var/lib/php`, `/var/lib/nginx` and `/tmp` (or the Kubernetes manifests with their emptyDirs); login and import must work.
- [ ] **`mongodb+srv`** — connect to a real `mongodb+srv://` cluster (SRV DNS + TLS); the host allowlist must accept or reject it as configured.

## Credits

Originally created by Samuel Tallet (https://github.com/SamuelTallet).  
This repo is a hardened fork of his MongoDB-PHP-GUI (https://github.com/SamuelTallet/MongoDB-PHP-GUI).
