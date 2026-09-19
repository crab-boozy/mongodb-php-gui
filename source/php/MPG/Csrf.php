<?php

namespace MPG;

/**
 * CSRF protection: one token per session, sent either as the
 * X-CSRF-Token header (AJAX) or the csrf_token form field (forms).
 */
class Csrf {

    /**
     * Creates the session token if missing. Call after session_start().
     */
    public static function init() : void {

        if ( empty($_SESSION['mpg']['csrf_token']) ) {
            $_SESSION['mpg']['csrf_token'] = bin2hex(random_bytes(32));
        }

    }

    /**
     * Current session token (for views: meta tag / hidden fields).
     */
    public static function token() : string {

        return $_SESSION['mpg']['csrf_token'] ?? '';

    }

    /**
     * Explicit precedence:
     * - both header and field present and different -> rejected;
     * - header present -> the header is checked;
     * - field present  -> the field is checked;
     * - neither present -> rejected.
     *
     * @throws CsrfException
     */
    public static function verify() : void {

        $headerToken = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        $fieldToken = (string) (is_array($_POST['csrf_token'] ?? null) ? '' : ($_POST['csrf_token'] ?? ''));
        $sessionToken = $_SESSION['mpg']['csrf_token'] ?? '';

        $tokenValid = static function(string $token) use ($sessionToken) : bool {
            return $token !== ''
                && $sessionToken !== ''
                && hash_equals($sessionToken, $token);
        };

        if ( $headerToken !== '' && $fieldToken !== '' && $headerToken !== $fieldToken ) {
            throw new CsrfException('CSRF tokens mismatch.');
        }

        if ( $headerToken !== '' ) {
            if ( !$tokenValid($headerToken) ) {
                throw new CsrfException('CSRF validation failed.');
            }
            return;
        }

        if ( $fieldToken !== '' ) {
            if ( !$tokenValid($fieldToken) ) {
                throw new CsrfException('CSRF validation failed.');
            }
            return;
        }

        throw new CsrfException('CSRF token is missing.');

    }

}
