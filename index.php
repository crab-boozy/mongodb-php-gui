<?php

namespace MPG; // MongoDB PHP GUI

use Nimbly\Limber\Application;
use Nimbly\Capsule\Factory\ServerRequestFactory;
use Nimbly\Limber\Exceptions\NotFoundHttpException;

/**
 * Absolute path, without trailing slash.
 * Example: /app
 */
const ABS_PATH = __DIR__;

if ( !file_exists($autoload_file = ABS_PATH . '/vendor/autoload.php') ) {
    die('Run `composer install` to complete MongoDB PHP GUI installation.');
}

$loader = require_once $autoload_file;
$loader->add('MPG', ABS_PATH . '/source/php');

session_set_cookie_params([
    'path' => '/',
    'secure' => AppConfig::cookieSecure(),
    'httponly' => true,
    'samesite' => 'Lax'
]);

session_start();

Csrf::init();

// PHP rejects uploads above upload_max_filesize before handler code runs
// (tmp_name becomes empty), which makes the PSR-7 request factory fatal
// with "Path must not be empty". Answer upload errors ourselves.
if ( ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && is_array($_FILES) && $_FILES !== [] ) {

    $uploadErrors = static function(array $files) : array {

        $errors = [];

        foreach ( $files as $file ) {
            if ( isset($file['error']) && is_array($file['error']) ) {
                $errors = array_merge($errors, $file['error']);
            } elseif ( isset($file['error']) ) {
                $errors[] = (int) $file['error'];
            }
        }

        return $errors;

    };

    if ( count(array_diff($uploadErrors($_FILES), [UPLOAD_ERR_OK])) > 0 ) {

        http_response_code(413);
        header('Content-Type: application/json');

        echo json_encode([
            'error' => [
                'code' => 413,
                'message' => 'The uploaded file exceeds the server limit.'
            ]
        ]);

        exit;

    }

}

$router = require ABS_PATH . '/routes.php';

$application = new Application($router);
$serverRequest = ServerRequestFactory::createFromGlobals();

try {

    $response = $application->dispatch($serverRequest);
    $application->send($response);
    
} catch (NotFoundHttpException $_error) {
    http_response_code(404);
    die('Route not found. Try to append a slash to URL.');
}
