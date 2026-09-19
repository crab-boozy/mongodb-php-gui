<?php

/**
 * E2E regression test: the full app (nginx + PHP-FPM + real MongoDB) driven
 * over plain HTTP, exactly like a browser would.
 *
 * Covers what the in-process suite cannot: the successful-login lifecycle
 * (session rotation over real HTTP), JSON request bodies (php://input),
 * multipart uploads (is_uploaded_file) and the nginx path.
 *
 * Run (CI): the workflow starts the app container and a mongo:7 service,
 * then runs this script with --network host.
 *
 * Run (local):
 *   docker run -d --name mpg-mongo -p 27017:27017 mongo:7
 *   # start the app (e.g. compose, port 8080)
 *   docker run --rm --network host --entrypoint php \
 *     -e MPG_TEST_MONGO_URI=mongodb://127.0.0.1:27017 \
 *     -e MPG_E2E_BASE_URL=http://127.0.0.1:8080 \
 *     -v "$PWD/tests":/app/tests mongodb-php-gui:latest /app/tests/e2e_test.php
 *
 * Without MPG_TEST_MONGO_URI the script prints SKIP and exits 0, so it is
 * safe to run anywhere.
 */

$mongoUri = getenv('MPG_TEST_MONGO_URI');
$baseUrl = rtrim((string) (getenv('MPG_E2E_BASE_URL') ?: 'http://127.0.0.1'), '/');

require __DIR__ . '/junit_report.php';

if ( $mongoUri === false || $mongoUri === '' ) {
    echo "  SKIP  E2E live MongoDB test (MPG_TEST_MONGO_URI not set)\n";
    mpg_junit_skip('E2E suite skipped (MPG_TEST_MONGO_URI not set)');
    mpg_junit_write('e2e');
    exit(0);
}

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

/**
 * Minimal HTTP client on top of stream contexts (no curl dependency).
 *
 * @return array{0:int, 1:string, 2:string, 3:array<string,string>}
 *               [status, location, body, set-cookies]
 */
function httpRequest(string $method, string $url, array $headers = [], ?string $body = null, array $cookies = []) : array {

    $headerLines = [];

    if ( $cookies !== [] ) {
        $cookieParts = [];
        foreach ( $cookies as $name => $value ) {
            $cookieParts[] = $name . '=' . $value;
        }
        $headerLines[] = 'Cookie: ' . implode('; ', $cookieParts);
    }

    foreach ( $headers as $name => $value ) {
        $headerLines[] = $name . ': ' . $value;
    }

    $context = stream_context_create(['http' => [
        'method'        => $method,
        'header'        => implode("\r\n", $headerLines),
        'content'       => $body,
        'ignore_errors' => true,
        'follow_location' => 0,
        'max_redirects'   => 0,
        'timeout'       => 30,
    ]]);

    $responseBody = @file_get_contents($url, false, $context);
    $status = 0;
    $location = '';
    $setCookies = [];

    foreach ( $http_response_header as $line ) {
        if ( preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m) ) {
            $status = (int) $m[1];
        } elseif ( stripos($line, 'Location:') === 0 ) {
            $location = trim(substr($line, 9));
        } elseif ( stripos($line, 'Set-Cookie:') === 0 ) {
            $pair = trim(substr($line, 11));
            $nameValue = explode(';', $pair, 2)[0];
            $parts = explode('=', $nameValue, 2);
            if ( count($parts) === 2 ) {
                $setCookies[trim($parts[0])] = trim($parts[1]);
            }
        }
    }

    return [$status, $location, (string) $responseBody, $setCookies];

}

function metaToken(string $html) : string {

    if ( preg_match('/<meta name="mpg-csrf-token" content="([0-9a-f]+)"/', $html, $m) ) {
        return $m[1];
    }

    return '';

}

function jsonPost(string $baseUrl, string $route, array $payload, array $cookies, string $csrfToken) : array {

    return httpRequest(
        'POST',
        $baseUrl . $route,
        ['Content-Type' => 'application/json', 'X-CSRF-Token' => $csrfToken],
        json_encode($payload),
        $cookies
    );

}

function multipartPost(string $baseUrl, string $route, array $fields, string $filename, string $fileContent, array $cookies) : array {

    $boundary = '----mpge2e' . bin2hex(random_bytes(16));
    $body = '';

    foreach ( $fields as $name => $value ) {
        $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"$name\"\r\n\r\n$value\r\n";
    }

    $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"import\"; filename=\"$filename\""
        . "\r\nContent-Type: application/json\r\n\r\n$fileContent\r\n--$boundary--\r\n";

    return httpRequest(
        'POST',
        $baseUrl . $route,
        ['Content-Type' => "multipart/form-data; boundary=$boundary"],
        $body,
        $cookies
    );

}

echo "== E2E: app + live MongoDB ==\n";

// --- App is up. ---------------------------------------------------------------
list($status, , $healthBody) = httpRequest('GET', $baseUrl . '/health');
$check('/health -> ' . $status . ' (200 expected)', $status === 200);

// --- Login lifecycle: form -> 302 -> rotated session -> fresh token. ----------
list($status, , $loginPage, $setCookies) = httpRequest('GET', $baseUrl . '/login');
$cookies = $setCookies;

$tokenBefore = metaToken($loginPage);
$check('login page -> ' . $status . ' with non-empty CSRF token', $status === 200 && $tokenBefore !== '');

list($status, $location, , $setCookies) = httpRequest(
    'POST',
    $baseUrl . '/login',
    ['Content-Type' => 'application/x-www-form-urlencoded'],
    http_build_query(['csrf_token' => $tokenBefore, 'uri' => $mongoUri]),
    $cookies
);
$check('login POST -> ' . $status . ' (302 expected)', $status === 302);

// session_regenerate_id(true) re-issues the session cookie in the 302.
$cookies = array_merge($cookies, $setCookies);

// session_regenerate_id(true) re-issues the cookie: keep the new one.
list($status, $location, , $setCookies) = httpRequest('GET', $baseUrl . '/', [], null, $cookies);
$cookies = array_merge($cookies, $setCookies);
$check('GET / after login -> 302 to /queryDocuments', $status === 302 && preg_match('#^/queryDocuments#', $location) === 1);

list($status, , $appPage, $setCookies) = httpRequest('GET', $baseUrl . '/queryDocuments', [], null, $cookies);
$cookies = array_merge($cookies, $setCookies);
$tokenAfter = metaToken($appPage);
$check('queryDocuments -> ' . $status . ' (200 expected, logged in)', $status === 200 && $tokenAfter !== '');
$check('CSRF token rotated on login', $tokenAfter !== '' && $tokenAfter !== $tokenBefore);

// --- Fixtures: create collection, insert, count, find, update, delete. -------
$db = 'mpgtest';
$coll = 't8';

// Idempotent re-runs: drop leftovers from a previous run.
jsonPost($baseUrl, '/dropCollection', ['databaseName' => $db, 'collectionName' => $coll], $cookies, $tokenAfter);

list($status, , $body) = jsonPost($baseUrl, '/createCollection', ['databaseName' => $db, 'collectionName' => $coll], $cookies, $tokenAfter);
$check('/createCollection -> ' . $status . ' (200 expected)', $status === 200 && trim($body) === 'true');

foreach ( [[1, 'alpha'], [2, 'beta'], [3, 'gamma']] as [$n, $city] ) {
    list($status) = jsonPost(
        $baseUrl,
        '/insertOneDocument',
        ['databaseName' => $db, 'collectionName' => $coll, 'document' => ['n' => $n, 'city' => $city]],
        $cookies,
        $tokenAfter
    );
    $check("/insertOneDocument n=$n -> $status (200 expected)", $status === 200);
}

list($status, , $body) = jsonPost($baseUrl, '/countDocuments', ['databaseName' => $db, 'collectionName' => $coll, 'filter' => []], $cookies, $tokenAfter);
$check('/countDocuments all -> ' . trim($body) . ' (3 expected)', $status === 200 && trim($body) === '3');

list($status, , $body) = jsonPost($baseUrl, '/countDocuments', ['databaseName' => $db, 'collectionName' => $coll, 'filter' => ['n' => ['$gte' => 2]]], $cookies, $tokenAfter);
$check('/countDocuments n>=2 -> ' . trim($body) . ' (2 expected)', $status === 200 && trim($body) === '2');

list($status, , $body) = jsonPost(
    $baseUrl,
    '/findDocuments',
    ['databaseName' => $db, 'collectionName' => $coll, 'filter' => [], 'options' => ['limit' => 2, 'sort' => ['n' => -1]]],
    $cookies,
    $tokenAfter
);
$documents = json_decode($body, true);
$check('/findDocuments limit=2 sort desc -> ' . $status . ' (200 expected)', $status === 200);
$check('find returned 2 documents, newest first', is_array($documents)
    && count($documents) === 2
    && ($documents[0]['n'] ?? null) === 3
    && ($documents[1]['n'] ?? null) === 2);

list($status, , $body) = jsonPost(
    $baseUrl,
    '/findDocuments',
    ['databaseName' => $db, 'collectionName' => $coll, 'filter' => [], 'options' => ['limit' => 2, 'projection' => ['n' => 1]]],
    $cookies,
    $tokenAfter
);
$documents = json_decode($body, true);
$check('find projection hides other fields', is_array($documents)
    && isset($documents[0]['n'])
    && !isset($documents[0]['city']));

list($status, , $body) = jsonPost(
    $baseUrl,
    '/updateOneDocument',
    ['databaseName' => $db, 'collectionName' => $coll, 'filter' => ['n' => 1], 'update' => ['$set' => ['n' => 100]]],
    $cookies,
    $tokenAfter
);
$check('/updateOneDocument -> ' . $status . ', modified ' . trim($body) . ' (1 expected)', $status === 200 && trim($body) === '1');

list($status, , $body) = jsonPost($baseUrl, '/countDocuments', ['databaseName' => $db, 'collectionName' => $coll, 'filter' => ['n' => 100]], $cookies, $tokenAfter);
$check('update visible in count -> ' . trim($body) . ' (1 expected)', $status === 200 && trim($body) === '1');

list($status, , $body) = jsonPost($baseUrl, '/deleteOneDocument', ['databaseName' => $db, 'collectionName' => $coll, 'filter' => ['n' => 3]], $cookies, $tokenAfter);
$check('/deleteOneDocument -> ' . $status . ', deleted ' . trim($body) . ' (1 expected)', $status === 200 && trim($body) === '1');

list($status, , $body) = jsonPost($baseUrl, '/countDocuments', ['databaseName' => $db, 'collectionName' => $coll, 'filter' => []], $cookies, $tokenAfter);
$check('count after delete -> ' . trim($body) . ' (2 expected)', $status === 200 && trim($body) === '2');

// --- Import: real multipart upload through nginx. ------------------------------
list($status, , $body) = multipartPost(
    $baseUrl,
    '/importDocuments',
    ['csrf_token' => $tokenAfter, 'database_name' => $db, 'collection_name' => $coll],
    'docs.json',
    json_encode([['n' => 40, 'city' => 'delta'], ['n' => 41, 'city' => 'epsilon']]),
    $cookies
);
$check('/importDocuments -> ' . $status . ' with success message', $status === 200
    && strpos($body, '2 document(s) imported') !== false);

list($status, , $body) = jsonPost($baseUrl, '/countDocuments', ['databaseName' => $db, 'collectionName' => $coll, 'filter' => []], $cookies, $tokenAfter);
$check('count after import -> ' . trim($body) . ' (4 expected)', $status === 200 && trim($body) === '4');

// --- Field enum + index lifecycle. ---------------------------------------------
list($status, , $body) = jsonPost($baseUrl, '/enumCollectionFields', ['databaseName' => $db, 'collectionName' => $coll], $cookies, $tokenAfter);
$check('/enumCollectionFields contains the fixture fields', $status === 200
    && strpos($body, '"n"') !== false
    && strpos($body, '"city"') !== false);

list($status, , $body) = jsonPost(
    $baseUrl,
    '/createIndex',
    ['databaseName' => $db, 'collectionName' => $coll, 'key' => ['n' => 1], 'options' => []],
    $cookies,
    $tokenAfter
);
$check('/createIndex -> ' . $status . ' (200 expected)', $status === 200);

list($status, , $body) = jsonPost($baseUrl, '/listIndexes', ['databaseName' => $db, 'collectionName' => $coll], $cookies, $tokenAfter);
$check('/listIndexes shows the created index', $status === 200 && strpos($body, 'n_1') !== false);

list($status, , $body) = jsonPost($baseUrl, '/dropIndex', ['databaseName' => $db, 'collectionName' => $coll, 'indexName' => 'n_1'], $cookies, $tokenAfter);
$check('/dropIndex -> ' . $status . ' (200 expected)', $status === 200);

// --- Teardown. -----------------------------------------------------------------
list($status, , $body) = jsonPost($baseUrl, '/dropCollection', ['databaseName' => $db, 'collectionName' => $coll], $cookies, $tokenAfter);
$check('/dropCollection -> ' . $status . ' (200 expected)', $status === 200 && trim($body) === 'true');

list($status, , $body) = jsonPost($baseUrl, '/countDocuments', ['databaseName' => $db, 'collectionName' => $coll, 'filter' => []], $cookies, $tokenAfter);
$check('count after drop -> ' . trim($body) . ' (0 expected)', $status === 200 && trim($body) === '0');

// ---------------------------------------------------------------------------
mpg_junit_write('e2e');

if ( $failures > 0 ) {
    fwrite(STDERR, "\n$failures E2E check(s) FAILED.\n");
    exit(1);
}

echo "\nAll E2E checks passed.\n";
