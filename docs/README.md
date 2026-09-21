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
| `MPG_COOKIE_SECURE`               | `0`        | Set `1` when TLS terminates at the ingress.                                                                         |
| `MPG_DEBUG`                       | `0`        | Set `1` to include error details in responses (development only).                                                   |

## Security

The application is hardened:

* **MongoDB URI allowlist** — `MPG_ALLOWED_MONGODB_HOSTS` / `MPG_ALLOWED_MONGODB_DOMAINS` restrict which servers can be connected to (exact hosts and boundary-aware domain suffixes, port validated to 1–65535, strict `mongodb+srv` handling, every host of a replica-set seed list is checked).
* **CSRF protection** on every mutating (POST) route; the token is per-session and rotated on login.
* **Per-session MongoDB clients** — one user cannot see another user's connection or credentials; the client is dropped on logout.
* **Query and import limits** — `MPG_MAX_DOCUMENTS` document cap, `maxTimeMS` on reads, fixed import limits: 50MB file and 100 000 documents (over-size uploads are rejected with HTTP 413).
* **Safe errors** — error responses never leak credentials or connection details (set `MPG_DEBUG=1` in development only).
* **Audit log** — every mutating operation (insert/update/delete/import, collection, index and user changes) is written to `stderr`.
* **Container hardening** — non-root (UID 808), works with a read-only root filesystem (Kubernetes), all logs go to stdout/stderr only.

### Production notes

* Set `MPG_ALLOWED_MONGODB_HOSTS` / `MPG_ALLOWED_MONGODB_DOMAINS` — without them any MongoDB host is allowed (a warning is logged).
* Lower `MPG_MAX_DOCUMENTS` to `500`–`1000` (the code default is 1 000 000).
* Set `MPG_COOKIE_SECURE=1` when TLS terminates at the ingress.
* Logging is fixed and minimal: no access log, nginx errors at `crit`, PHP errors/warnings only (no notices/deprecations). The application's own `MPG audit |` / `MPG error |` / `MPG config |` lines are always written to stderr.
* Import is capped at 50MB per file and 100 000 documents (fixed in the image). A 50MB import is decoded fully in memory, so plan for up to ~512MB of RAM per concurrent import (`pm.max_children = 5`).

## Docker image

The image is published to Docker Hub as `boozy1981/mongodb-php-gui`:

| Tag | Meaning |
| --- | --- |
| `boozy1981/mongodb-php-gui:<version>` | Versioned build, e.g. `1.0.0`. |
| `boozy1981/mongodb-php-gui:latest` | The most recently published build. |

Publishing is gated by the full test suite (in-process + E2E against a live MongoDB + audit checks — the publish job only runs when they all pass). It triggers on **release creation** (GitHub creates the tag automatically from the tag name you enter):

* GitHub UI: `Releases` → `Create a new release` → tag `v1.0.0` → publish.
* CLI: `gh release create v1.0.0`.

An ad-hoc build of the current master (no release): Actions → `Publish image` → Run workflow (optional tag input; empty = `dev-<short-sha>`). The workflow needs the repository secrets `DOCKERHUB_USERNAME` and `DOCKERHUB_TOKEN`.

## Deployment: OIDC (ADFS)

The application keeps its own login (MongoDB credentials). To add corporate SSO in front of it, put [oauth2-proxy](https://oauth2-proxy.github.io/oauth2-proxy/) between Traefik and the app:

```
browser → Traefik (TLS) → oauth2-proxy (ADFS login) → mongodb-php-gui (MongoDB login)
```

Manifests: `k8s/oidc/` (Secret + Deployment + Service for oauth2-proxy, IngressRoute for the host).

### Register the ADFS client

OIDC requires ADFS on Windows Server 2019+. On the ADFS server:

```powershell
New-AdfsOAuth2Client `
  -ClientName "mongodb-php-gui" `
  -ClientId "urn:adfs:sp:example.com:mongodb-php-gui" `
  -RedirectUri "https://gui.example.com/oauth2/callback"
```

ADFS does not issue a client secret for OIDC clients — oauth2-proxy works without one.

### Apply

```
kubectl -n <namespace> apply -f k8s/oidc/
```

Then replace the `TODO` placeholders in `k8s/oidc/oauth2-proxy.yaml` and `k8s/oidc/ingressroute.yaml`:

* `OIDC_ISSUER_URL` — `https://<adfs-host>/adfs/ls/` (**trailing slash mandatory**).
* `CLIENT_ID` — exactly the `-ClientId` from the registration above.
* `REDIRECT_URL` — `https://<host>/oauth2/callback`, where `<host>` is the IngressRoute host (an exact match is required).
* IngressRoute `Host(...)` and the `tls` block — per your existing routes.
* Secret `COOKIE_SECRET` — 32 random bytes (`head -c 32 /dev/urandom | base64`).

### Notes

* A redirect loop usually means `REDIRECT_URL` does not match the public URL (host or TLS).
* ADFS answers `server_error` for an issuer URL without the trailing slash.
* The ADFS token lifetime is 750 s by default; raise it in ADFS if logins feel too short.
* To restrict who may log in, set `EMAIL_DOMAIN` (comma-separated domains) in the oauth2-proxy Deployment.
* The app itself is not modified: no OIDC cookies are stored, and the per-user MongoDB login stays the data layer.

## Deployment: scaling and sessions

Sessions are stored in pod-local files, so `k8s/deployment.yaml` stays at `replicas: 1`. Two ways to scale above a single replica:

**Option A — sticky sessions (Traefik v3, no code change).** Make Traefik pin each browser to one app server with a cookie. Add the labels to the Service referenced by the IngressRoute route (for the app, the `mongodb-php-gui` Service):

```yaml
traefik.http.services.mongodb-php-gui.stickiness.cookie.name: mpg-affinity
traefik.http.services.mongodb-php-gui.stickiness.cookie.secure: "true"
traefik.http.services.mongodb-php-gui.stickiness.cookie.httpOnly: "true"
traefik.http.services.mongodb-php-gui.stickiness.cookie.sameSite: Lax
```

Caveats: if the pinned pod is replaced (rollout, node drain) the user logs in again; the affinity cookie is per IngressRoute route, not shared across hosts; clearing browser cookies forces a re-login.

**Option B — shared session backend (Redis, follow-up).** `session.save_handler=redis` with `session.save_path=redis://<redis>:6379` lets any replica serve any session. Not implemented in the current image (the static `config/php/mpg.ini` uses the file handler + `emptyDir`); planned as a follow-up.

Start with Option A (zero code change); move to Option B if the team or uptime requirements outgrow sticky sessions.

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
- [ ] **Upload limits** — a ~48 MiB JSON import succeeds; a ~53 MB file is rejected with an HTTP 413 JSON body (application guard, 50MB cap); a ~54.5 MB file is rejected with an HTTP 413 HTML body (nginx, 52M body cap).
- [ ] **Read-only root filesystem** — run with `--read-only` plus tmp volumes for `/var/lib/php`, `/var/lib/nginx` and `/tmp` (or the Kubernetes manifests with their emptyDirs); login and import must work.
- [ ] **`mongodb+srv`** — connect to a real `mongodb+srv://` cluster (SRV DNS + TLS); the host allowlist must accept or reject it as configured.

## Credits

Originally created by Samuel Tallet (https://github.com/SamuelTallet).  
This repo is a hardened fork of his MongoDB-PHP-GUI (https://github.com/SamuelTallet/MongoDB-PHP-GUI).
