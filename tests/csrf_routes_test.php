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

// ---------------------------------------------------------------------------
if ( $failures > 0 ) {
    fwrite(STDERR, "\n$failures check(s) FAILED.\n");
    exit(1);
}

echo "\nAll CSRF checks passed.\n";
