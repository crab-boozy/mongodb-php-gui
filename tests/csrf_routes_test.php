<?php

/**
 * Dev-only regression test: EVERY registered POST route must be guarded
 * by the CSRF chokepoint. Not shipped in the Docker image (see .dockerignore).
 *
 * Run: php tests/csrf_routes_test.php
 *
 * For each POST route:
 *   1. route is actually registered (Router::getSupportedMethods);
 *   2. POST without any token        -> 403;
 *   3. POST with valid header token  -> pass CSRF (200 on /login);
 *   4. POST with valid form field    -> pass CSRF (200 on /login);
 *   5. header + field with different values -> 403.
 *
 * Additionally:
 *   6. failed login re-renders the form with a non-empty token;
 *   7. MongoURI/host parser: seed lists, allowlist boundaries, port range,
 *      SRV strictness.
 *
 * The successful-login lifecycle (token rotation -> next request renders a
 * fresh token -> POST with it passes CSRF) exits the PHP process via
 * header()+exit in Routes::redirectTo, so it cannot be asserted in-process;
 * it is verified by the live HTTP regression (login -> GET -> POST /countDocuments).
 */

namespace MPG;

use Nimbly\Capsule\Factory\ServerRequestFactory;
use Nimbly\Limber\Application;

$autoloadFile = __DIR__ . '/../vendor/autoload.php';

if ( !file_exists($autoloadFile) ) {
    fwrite(STDERR, "Run `composer install` first.\n");
    exit(1);
}

$loader = require_once $autoloadFile;
$loader->add('MPG', __DIR__ . '/../source/php');

// CLI: emulate a web request context for Routes::setPrefix().
$_SERVER['REQUEST_URI'] = '/';

// index.php defines these constants; replicate for CLI.
define('VERSION', 'test');
if ( !defined('ABS_PATH') ) {
    define('ABS_PATH', __DIR__ . '/..');
}

// Allowlist fixtures used by the parser checks below (AppConfig caches its
// config on first access, so the env must be set before any dispatch).
putenv('MPG_ALLOWED_MONGODB_HOSTS=127.0.0.1');
putenv('MPG_ALLOWED_MONGODB_DOMAINS=example.com');

// A real (file-based) session so session_regenerate_id() works in CLI.
session_start();

$routesFile = __DIR__ . '/../routes.php';
$router = require $routesFile;

$postRoutes = [
    '/login',
    '/listCollections',
    '/createCollection',
    '/renameCollection',
    '/dropCollection',
    '/importDocuments',
    '/insertOneDocument',
    '/countDocuments',
    '/deleteOneDocument',
    '/findDocuments',
    '/updateOneDocument',
    '/enumCollectionFields',
    '/createIndex',
    '/listIndexes',
    '/dropIndex',
    '/createUser',
    '/listUsers',
    '/dropUser',
];

$failures = 0;

$check = function(string $label, bool $ok) use (&$failures) : void {
    if ( $ok ) {
        echo "  PASS  $label\n";
    } else {
        echo "  FAIL  $label\n";
        $failures++;
    }
};

$factory = new ServerRequestFactory();
$application = new Application($router);

// --- No token: every POST route must answer 403. -------------------------
echo "== POST without CSRF token (expect 403) ==\n";

$_SESSION = [];
unset($_SERVER['HTTP_X_CSRF_TOKEN']);
$_POST = [];

foreach ( $postRoutes as $route ) {

    $request = $factory->createServerRequest('POST', 'http://localhost' . $route);
    $methods = $router->getSupportedMethods($request);

    $registered = in_array('POST', $methods, true);
    $check($route . ' registered as POST', $registered);

    if ( !$registered ) {
        continue;
    }

    $response = $application->dispatch($request);
    $check($route . ' -> ' . $response->getStatusCode() . ' (403 expected)', $response->getStatusCode() === 403);

}

// --- Valid header token on /login: CSRF passes. ---------------------------
echo "== POST with valid X-CSRF-Token (expect CSRF pass) ==\n";

$_SESSION['mpg']['csrf_token'] = bin2hex(random_bytes(32));
$token = $_SESSION['mpg']['csrf_token'];

$_SERVER['HTTP_X_CSRF_TOKEN'] = $token;
$request = $factory->createServerRequest('POST', 'http://localhost/login');

$response = $application->dispatch($request);
unset($_SERVER['HTTP_X_CSRF_TOKEN']);
$check('/login with header token -> ' . $response->getStatusCode() . ' (200 expected)', $response->getStatusCode() === 200);

// --- Valid form field token on /login: CSRF passes. -----------------------
echo "== POST with valid csrf_token field (expect CSRF pass) ==\n";

$_POST = ['csrf_token' => $token];

$request = $factory->createServerRequest('POST', 'http://localhost/login');
$response = $application->dispatch($request);
$check('/login with field token -> ' . $response->getStatusCode() . ' (200 expected)', $response->getStatusCode() === 200);

// --- Mismatch: header and field differ -> 403. ----------------------------
echo "== POST with conflicting header and field (expect 403) ==\n";

$_SERVER['HTTP_X_CSRF_TOKEN'] = $token;
$_POST = ['csrf_token' => str_repeat('0', strlen($token))];

$request = $factory->createServerRequest('POST', 'http://localhost/login');
$response = $application->dispatch($request);
$check('/login with mismatched tokens -> ' . $response->getStatusCode() . ' (403 expected)', $response->getStatusCode() === 403);

// --- Bogus token -> 403. ---------------------------------------------------
echo "== POST with forged token (expect 403) ==\n";

unset($_SERVER['HTTP_X_CSRF_TOKEN']);
$_POST = ['csrf_token' => str_repeat('0', 64)];

$request = $factory->createServerRequest('POST', 'http://localhost/findDocuments');
$response = $application->dispatch($request);
$check('/findDocuments with forged token -> ' . $response->getStatusCode() . ' (403 expected)', $response->getStatusCode() === 403);

// Capsule BufferStream is not seekable - cast once, never rewind.
$bodyOf = static function($response) : string {
    return (string) $response->getBody();
};

$metaTokenOf = static function(string $html) : string {
    if ( preg_match('/<meta name="mpg-csrf-token" content="([0-9a-f]+)"/', $html, $m) ) {
        return $m[1];
    }
    return '';
};

// --- Failed login must re-render a live form (non-empty token). ------------
// (The successful-login lifecycle - token rotation, fresh token on the next
// request, POST with it passing CSRF - exits the PHP process inside
// Routes::redirectTo and is verified by the live HTTP regression instead.)
echo "== Failed login keeps the form alive ==\n";

$_POST = [];
$request = $factory->createServerRequest('GET', 'http://localhost/login');
$tokenBefore = $metaTokenOf($bodyOf($application->dispatch($request)));

$_POST = [
    'csrf_token' => $tokenBefore,
    'uri' => 'not a uri',
];

$request = $factory->createServerRequest('POST', 'http://localhost/login');
$response = $application->dispatch($request);
$failedLoginHtml = $bodyOf($response);
$tokenAfterFail = $metaTokenOf($failedLoginHtml);
$check('failed login -> ' . $response->getStatusCode() . ' (200 expected)', $response->getStatusCode() === 200);
$check('failed login re-renders a non-empty CSRF token', $tokenAfterFail !== '');

// --- MongoURI/host parser: seeds, boundaries, ports, SRV. ------------------
echo "== MongoURI / host parser checks ==\n";

$hosts = AppConfig::extractMongoHosts(
    'mongodb://mongo1.example.com:27017,mongo2.example.com:27017,mongo3.example.com:27017/?replicaSet=rs0'
);
$check('rs seed list -> 3 hosts', $hosts === ['mongo1.example.com', 'mongo2.example.com', 'mongo3.example.com']);

try {
    AppConfig::assertMongoUriAllowed(
        'mongodb://mongo1.example.com:27017,mongo2.example.com:27017,mongo3.example.com:27017/?replicaSet=rs0'
    );
    $check('rs seed list passes allowlist', true);
} catch (\InvalidArgumentException $e) {
    $check('rs seed list passes allowlist', false);
}

// notallowed.org is outside the example.com allowlist; one bad seed must
// reject the whole seed list. (evil.example.com would be allowed - it is a
// real subdomain of example.com.)
try {
    AppConfig::assertMongoUriAllowed('mongodb://mongo1.example.com:27017,notallowed.org:27017');
    $check('rs with disallowed seed rejected', false);
} catch (\InvalidArgumentException $e) {
    $check('rs with disallowed seed rejected', true);
}

$check('srv parses single authority', AppConfig::extractMongoHosts('mongodb+srv://cluster.example.com/?replicaSet=rs0') === ['cluster.example.com']);

$check('exact host allowed', AppConfig::isHostAllowed('127.0.0.1'));
$check('subdomain allowed', AppConfig::isHostAllowed('mongo.example.com'));
$check('case-insensitive host allowed', AppConfig::isHostAllowed('FOO.EXAMPLE.COM'));
$check('trailing dot normalized', AppConfig::isHostAllowed('foo.example.com.'));
$check('evil-example.com rejected', !AppConfig::isHostAllowed('evil-example.com'));
$check('example.com.evil.com rejected', !AppConfig::isHostAllowed('example.com.evil.com'));

$check('port 1 accepted', AppConfig::extractHost('mongo.example.com:1') === 'mongo.example.com');
$check('port 65535 accepted', AppConfig::extractHost('mongo.example.com:65535') === 'mongo.example.com');

$portRejected = static function(string $seed) : bool {
    try {
        AppConfig::extractHost($seed);
        return false;
    } catch (\InvalidArgumentException $e) {
        return true;
    }
};

$check('port 0 rejected', $portRejected('mongo.example.com:0'));
$check('port 65536 rejected', $portRejected('mongo.example.com:65536'));
$check('port 999999 rejected', $portRejected('mongo.example.com:999999'));
$check('ipv6 port accepted', AppConfig::extractHost('[::1]:27017') === '::1');
$check('ipv6 port out of range rejected', $portRejected('[::1]:65536'));

$srvRejected = static function(string $seed) : bool {
    try {
        AppConfig::extractHost($seed, true);
        return false;
    } catch (\InvalidArgumentException $e) {
        return true;
    }
};

$check('srv with port rejected', $srvRejected('foo.example.com:27017'));
$check('srv with comma rejected', $srvRejected('foo.example.com,evil.com'));
$check('plain srv accepted', AppConfig::extractHost('foo.example.com', true) === 'foo.example.com');

// ---------------------------------------------------------------------------
if ( $failures > 0 ) {
    fwrite(STDERR, "\n$failures check(s) FAILED.\n");
    exit(1);
}

echo "\nAll CSRF checks passed.\n";
