<?php

namespace MPG;

class Routes {

    private static $prefix;

    public static function setPrefix() {

        $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');

        // If request matches a folder. For example: /mongo/
        if ( preg_match('#/$#', $requestUri) ) {

            $prefix = $requestUri;

        } else {

            $prefix = dirname($requestUri);

            // Normalize directory separator in request path.
            if ( DIRECTORY_SEPARATOR !== '/' ) {
                $prefix = str_replace(DIRECTORY_SEPARATOR, '/', $prefix);
            }

        }

        $prefix = rtrim($prefix, '/');

        // Open-redirect guard: the prefix must stay a plain single-slash
        // path - no empty segments (//evil.com), no control characters.
        if ( $prefix !== ''
            && !preg_match('#^/(?:[^/\x00-\x1F]+/)*[^/\x00-\x1F]+$#', $prefix) ) {
            $prefix = '';
        }

        self::$prefix = $prefix;

    }

    /**
     * Returns routes prefix, without trailing slash.
     * Example: /mongo
     */
    public static function getPrefix() : string {
        return self::$prefix;
    }

    /**
     * Redirects to a route.
     * 
     * @param string $route Route with leading slash.
     * Example: /queryDocuments
     */
    public static function redirectTo(string $route) {

        header('Location: ' . self::$prefix . $route);
        exit;

    }

}
