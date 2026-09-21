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
 *      SRV strictness;
 *   8. ErrorNormalizer: credential masking, generic client message,
 *      sanitized server log, debug-on detail via subprocess;
 *   9. Audit: line format, allowlist, CR/LF log-injection guard;
 *   10. Routes::setPrefix open-redirect guard;
 *   11. normalizeFindOptions/normalizeLimit: the 400-path validation
 *       (php://input is empty in CLI and CSRF blocks the body first, so the
 *       private validator is exercised via reflection);
 *   12. AuthController::resetSessionState regression: the P0 fix that keeps
 *       the CSRF token alive while wiping auth state;
 *   13. MongoDBHelper::clearClient: per-session client cache eviction;
 *   14. /findDocuments with a valid CSRF token and an empty body -> 400
 *       (php://input is empty in CLI, so this hits the exact decode catch
 *       the production 400 path uses).
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

// index.php defines ABS_PATH; replicate for CLI.
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

require __DIR__ . '/junit_report.php';

$failures = 0;

$check = function(string $label, bool $ok) use (&$failures) : void {
    if ( $ok ) {
        echo "  PASS  $label\n";
    } else {
        echo "  FAIL  $label\n";
        $failures++;
    }

    mpg_junit_record($label, $ok);
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

// --- ErrorNormalizer: masking, generic client message, sanitized log. -------
echo "== ErrorNormalizer checks ==\n";

$check('sanitize masks mongodb credentials', ErrorNormalizer::sanitize('connect mongodb://secuser:secret@mongo1.example.com/db') === 'connect mongodb://***@mongo1.example.com/db');
$check('sanitize masks mongodb+srv credentials', ErrorNormalizer::sanitize('mongodb+srv://user:p%40ss@cluster0.example.com/?tls=true') === 'mongodb+srv://***@cluster0.example.com/?tls=true');
$check('sanitize masks every URI in a message', ErrorNormalizer::sanitize('mongodb://a:b@h1:27017 then mongodb://c:d@h2:27017') === 'mongodb://***@h1:27017 then mongodb://***@h2:27017');
$check('sanitize leaves a URI without credentials alone', ErrorNormalizer::sanitize('connected to mongodb://mongo1:27017') === 'connected to mongodb://mongo1:27017');
$check('sanitize is case-insensitive', ErrorNormalizer::sanitize('MONGODB://u:p@h') === 'MONGODB://***@h');

$errLogFile = tempnam(sys_get_temp_dir(), 'mpg-test-errlog-');
$originalErrLog = ini_get('error_log');
ini_set('error_log', $errLogFile);

$normalized = ErrorNormalizer::normalize(
    new \Exception('connection failed: mongodb://secuser:secret@mongo1.example.com/db', 42),
    'MPG\DocumentsController::find'
);
ErrorNormalizer::normalize(new \Exception('plain message'), null);

$check('debug off: client gets a generic message', $normalized['error']['message'] === 'An internal error has occurred.');
$check('debug off: exception code preserved', $normalized['error']['code'] === 42);
$check('debug off: function preserved', $normalized['error']['function'] === 'MPG\DocumentsController::find');
$check('no function passed: key omitted', !isset(ErrorNormalizer::normalize(new \Exception('x'), null)['error']['function']));

$logContent = (string) @file_get_contents($errLogFile);
$check('server log has the MPG error line', strpos($logContent, 'MPG error | connection failed: ') !== false);
$check('server log URI is sanitized', strpos($logContent, 'mongodb://***@mongo1.example.com/db') !== false);
$check('server log keeps the function', strpos($logContent, ' in MPG\DocumentsController::find') !== false);
$check('server log has no raw credentials', strpos($logContent, 'secuser:secret@') === false);

// Debug mode is cached in AppConfig on first access, so the debug-on path is
// asserted in a subprocess where MPG_DEBUG=1 is the only difference.
$debugOnSnippet = '$l = require "/app/vendor/autoload.php";'
    . ' $l->add("MPG", "/app/source/php");'
    . ' try { throw new Exception("conn mongodb://secuser:secret@mongo1.example.com"); }'
    . ' catch (Throwable $e) { echo MPG\ErrorNormalizer::normalize($e, "fnX")["error"]["message"]; }';
$debugOnOut = [];
exec('MPG_DEBUG=1 php -r ' . escapeshellarg($debugOnSnippet), $debugOnOut, $debugOnRc);
$debugOnMsg = implode("\n", $debugOnOut);
$check('debug on: sanitized detail reaches the client', $debugOnMsg === 'conn mongodb://***@mongo1.example.com');
$check('debug on: raw credentials never reach the client', $debugOnRc === 0 && strpos($debugOnMsg, 'secuser:secret@') === false);

ini_set('error_log', $originalErrLog);
@unlink($errLogFile);

// --- Audit: line format, allowlist, log-injection guard. ---------------------
echo "== Audit checks ==\n";

$auditFile = tempnam(sys_get_temp_dir(), 'mpg-test-audit-');
ini_set('error_log', $auditFile);

$truncate = static function() use ($auditFile) : void {
    file_put_contents($auditFile, '');
};

$auditLines = static function() use ($auditFile) : array {
    $content = (string) file_get_contents($auditFile);
    $content = rtrim($content, "\n");
    return $content === '' ? [] : explode("\n", $content);
};

$expectedSession = substr(hash('sha256', session_id()), 0, 8);

// error_log() prepends a [timestamp] prefix; strip it before asserting.
$stripTimestamp = static function(string $line) : string {
    return (string) preg_replace('/^\[[^\]]*\] /', '', $line);
};

$truncate();
Audit::success('document.insert_one', 'testdb', 'coll1');
$lines = $auditLines();
$check('audit success line format', count($lines) === 1
    && $stripTimestamp($lines[0]) === 'MPG audit | op=document.insert_one db=testdb coll=coll1 result=success session=' . $expectedSession);

$truncate();
Audit::error('document.import', 'testdb', null);
$lines = $auditLines();
$check('audit error with null collection -> coll=-', count($lines) === 1
    && strpos($lines[0], 'op=document.import db=testdb coll=- result=error session=' . $expectedSession) !== false);

$truncate();
Audit::success('document.evil', 'testdb', 'x');
$check('unknown operation is not audited', $auditLines() === []);

$truncate();
Audit::success('document.import', "te\nst", "c\r1");
$lines = $auditLines();
$check('CR/LF in names stay on one line', count($lines) === 1
    && strpos($lines[0], 'db=te' . '\\' . 'nst coll=c' . '\\' . 'r1') !== false);

$truncate();
Audit::success('document.import', '  spaced  ', 'c');
$lines = $auditLines();
$check('audit values are trimmed', count($lines) === 1 && strpos($lines[0], 'db=spaced coll=c') !== false);

ini_set('error_log', $originalErrLog);
@unlink($auditFile);

// --- Routes::setPrefix: open-redirect guard. ---------------------------------
echo "== Routes::setPrefix guard checks ==\n";

$prefixCheck = static function(string $uri, string $expected) use ($check) : void {
    $_SERVER['REQUEST_URI'] = $uri;
    Routes::setPrefix();
    $shown = str_replace(["\x00", "\n", "\r"], ['\\0', '\\n', '\\r'], $uri);
    $check("setPrefix('" . $shown . "') -> '" . Routes::getPrefix() . "'", Routes::getPrefix() === $expected);
};

$prefixCheck('/mongo/', '/mongo');
$prefixCheck('/mongo/login', '/mongo');
$prefixCheck('/', '');
$prefixCheck('//evil.com/', '');
$prefixCheck('/a//b/', '');
$prefixCheck("/a\x00b/", '');

$_SERVER['REQUEST_URI'] = '/';
Routes::setPrefix();
$check('prefix restored to empty after tests', Routes::getPrefix() === '');

// --- normalizeFindOptions/normalizeLimit: 400-path validation. ---------------
echo "== find options validation checks ==\n";

$normalizeOptions = static function($userOptions) {
    $method = new \ReflectionMethod(DocumentsController::class, 'normalizeFindOptions');
    return $method->invoke(null, $userOptions);
};

$optionsRejected = static function($userOptions) use ($normalizeOptions) : bool {
    try {
        $normalizeOptions($userOptions);
        return false;
    } catch (\InvalidArgumentException $e) {
        return true;
    }
};

$check('no options -> default page size', $normalizeOptions([]) === ['limit' => AppConfig::defaultDocuments()]);
$check('int limit passes through', $normalizeOptions(['limit' => 500]) === ['limit' => 500]);
$check('numeric string limit accepted', $normalizeOptions(['limit' => '500']) === ['limit' => 500]);
$check('limit 0 -> default page size', $normalizeOptions(['limit' => 0]) === ['limit' => AppConfig::defaultDocuments()]);
$check('explicit null limit -> default page size', $normalizeOptions(['limit' => null]) === ['limit' => AppConfig::defaultDocuments()]);
$check('limit above max is clamped', $normalizeOptions(['limit' => 5000000]) === ['limit' => AppConfig::maxDocuments()]);
$check('non-numeric string limit rejected', $optionsRejected(['limit' => 'abc']));
$check('negative limit rejected', $optionsRejected(['limit' => -5]));
$check('float limit rejected', $optionsRejected(['limit' => 1.5]));
$check('non-array options rejected', $optionsRejected('nope'));
$check('valid skip passes through', $normalizeOptions(['skip' => 5]) === ['limit' => AppConfig::defaultDocuments(), 'skip' => 5]);
$check('string skip rejected', $optionsRejected(['skip' => '5']));
$check('negative skip rejected', $optionsRejected(['skip' => -1]));
$check('non-array sort rejected', $optionsRejected(['sort' => 'name']));
$check('non-array projection rejected', $optionsRejected(['projection' => 'name']));

// --- AuthController::resetSessionState: P0 regression. ----------------------
// The wipe must drop auth state but keep the CSRF token; without it a failed
// login after a success rendered a dead form (empty token).
echo "== resetSessionState regression checks ==\n";

$authController = ( new \ReflectionClass(AuthController::class) )->newInstanceWithoutConstructor();
$resetSessionState = ( new \ReflectionClass(AuthController::class) )->getMethod('resetSessionState');
$resetSessionState->setAccessible(true);

$csrfToken = bin2hex(random_bytes(32));
$_SESSION['mpg'] = [
    'csrf_token'     => $csrfToken,
    'user_is_logged' => true,
    'mongodb_uri'    => 'mongodb://u:p@mongo1.example.com/test',
    'mongodb_user'   => 'u',
    'mongodb_password' => 'p',
];

$resetSessionState->invoke($authController);

$check('resetSessionState keeps the CSRF token', ($_SESSION['mpg']['csrf_token'] ?? null) === $csrfToken);
$check('resetSessionState wipes user_is_logged', !isset($_SESSION['mpg']['user_is_logged']));
$check('resetSessionState wipes mongodb credentials', !isset($_SESSION['mpg']['mongodb_uri'])
    && !isset($_SESSION['mpg']['mongodb_user']) && !isset($_SESSION['mpg']['mongodb_password']));

$_SESSION['mpg'] = ['user_is_logged' => true];
$resetSessionState->invoke($authController);
$check('resetSessionState without a token empties mpg state', $_SESSION['mpg'] === []);

// --- MongoDBHelper::clearClient: session client cache eviction. -------------
echo "== clearClient checks ==\n";

$clientsProp = ( new \ReflectionClass(MongoDBHelper::class) )->getProperty('clients');
$clientsProp->setAccessible(true);
$clientsProp->setValue(null, [session_id() => 'sentinel-client']);

MongoDBHelper::clearClient();
$check('clearClient drops the session client', !isset($clientsProp->getValue(null)[session_id()]));

$clientsProp->setValue(null, []);
MongoDBHelper::clearClient();
$check('clearClient with no client is a no-op', $clientsProp->getValue(null) === []);

// --- /findDocuments: valid CSRF token + empty body -> 400, not 500. ---------
echo "== findDocuments empty body checks ==\n";

$_SESSION['mpg']['csrf_token'] = bin2hex(random_bytes(32));
unset($_SERVER['HTTP_X_CSRF_TOKEN']);
$_POST = [];

$_SERVER['HTTP_X_CSRF_TOKEN'] = $_SESSION['mpg']['csrf_token'];
$request = $factory->createServerRequest('POST', 'http://localhost/findDocuments');
$response = $application->dispatch($request);
unset($_SERVER['HTTP_X_CSRF_TOKEN']);
$emptyBodyResponse = $bodyOf($response);
$check('/findDocuments empty body -> ' . $response->getStatusCode() . ' (400 expected)', $response->getStatusCode() === 400);
$check('/findDocuments empty body -> generic error message', strpos($emptyBodyResponse, 'An internal error has occurred.') !== false);

echo "== import limit constant checks ==\n";

$check('import size limit fixed at 50MB', AppConfig::maxImportSize() === 52428800);
$check('import document limit fixed at 100000', AppConfig::maxImportDocuments() === 100000);

// ---------------------------------------------------------------------------
mpg_junit_write('csrf_routes');

if ( $failures > 0 ) {
    fwrite(STDERR, "\n$failures check(s) FAILED.\n");
    exit(1);
}

echo "\nAll checks passed.\n";
