<?php

namespace MPG;

/**
 * Central application configuration: ENV parsing, limits, MongoURI allowlist.
 *
 * Numeric ENV contract: invalid (non-integer) -> default + warning;
 * value < min -> min + warning; value > max -> max + warning.
 */
class AppConfig {

    private static ?array $config = null;

    private function __construct() {
    }

    private static function config() : array {

        if ( self::$config === null ) {

            $maxDocuments = self::envInt('MPG_MAX_DOCUMENTS', 1000000, 1, null);

            $defaultDocuments = self::envInt('MPG_DEFAULT_DOCUMENTS', 100, 1, null);
            if ( $defaultDocuments > $maxDocuments ) {
                error_log('MPG config | MPG_DEFAULT_DOCUMENTS is above MPG_MAX_DOCUMENTS; clamping to ' . $maxDocuments . '.');
                $defaultDocuments = $maxDocuments;
            }

            self::$config = [
                'allowedHosts'           => self::normalizeList(self::envList('MPG_ALLOWED_MONGODB_HOSTS')),
                'allowedDomains'         => self::normalizeList(self::envList('MPG_ALLOWED_MONGODB_DOMAINS')),
                'defaultDocuments'       => $defaultDocuments,
                'maxDocuments'           => $maxDocuments,
                'queryMaxTimeMs'         => self::envInt('MPG_QUERY_MAX_TIME_MS', 60000, 100, 600000),
                'serverSelectionTimeout' => self::envInt('MPG_SERVER_SELECTION_TIMEOUT_MS', 5000, 100, 60000),
                'connectTimeout'         => self::envInt('MPG_CONNECT_TIMEOUT_MS', 5000, 100, 60000),
                'socketTimeout'          => self::envInt('MPG_SOCKET_TIMEOUT_MS', 10000, 100, 60000),
                'maxImportSize'          => self::envInt('MPG_MAX_IMPORT_SIZE', 10485760, 1, null),
                'maxImportDocuments'     => self::envInt('MPG_MAX_IMPORT_DOCUMENTS', 10000, 1, null),
                'cookieSecure'           => self::envBool('MPG_COOKIE_SECURE', false),
                'debug'                  => self::envBool('MPG_DEBUG', false),
            ];

        }

        return self::$config;

    }

    public static function allowedHosts() : array {
        return self::config()['allowedHosts'];
    }

    public static function allowedDomains() : array {
        return self::config()['allowedDomains'];
    }

    public static function defaultDocuments() : int {
        return self::config()['defaultDocuments'];
    }

    public static function maxDocuments() : int {
        return self::config()['maxDocuments'];
    }

    public static function queryMaxTimeMs() : int {
        return self::config()['queryMaxTimeMs'];
    }

    public static function serverSelectionTimeoutMs() : int {
        return self::config()['serverSelectionTimeout'];
    }

    public static function connectTimeoutMs() : int {
        return self::config()['connectTimeout'];
    }

    public static function socketTimeoutMs() : int {
        return self::config()['socketTimeout'];
    }

    public static function maxImportSize() : int {
        return self::config()['maxImportSize'];
    }

    public static function maxImportDocuments() : int {
        return self::config()['maxImportDocuments'];
    }

    public static function cookieSecure() : bool {
        return self::config()['cookieSecure'];
    }

    public static function debug() : bool {
        return self::config()['debug'];
    }

    /**
     * Checks a hostname against the allowlist: exact host or boundary-aware
     * domain suffix (example.com allows example.com and sub.example.com,
     * but not evil-example.com nor example.com.evil.com).
     *
     * Both lists empty: all hosts are allowed (warning is logged on every check).
     */
    public static function isHostAllowed(string $host) : bool {

        $host = self::normalizeHost($host);

        // ASCII hostnames only (IDN/punycode are not supported).
        if ( $host === '' || preg_match('/[^\x20-\x7E]/', $host) ) {
            return false;
        }

        $hosts = self::allowedHosts();
        $domains = self::allowedDomains();

        if ( $hosts === [] && $domains === [] ) {
            error_log('MPG config | MongoURI allowlist is empty - host "' . $host . '" allowed. Set MPG_ALLOWED_MONGODB_HOSTS / MPG_ALLOWED_MONGODB_DOMAINS to restrict.');
            return true;
        }

        foreach ( $hosts as $allowedHost ) {
            if ( $host === $allowedHost ) {
                return true;
            }
        }

        foreach ( $domains as $domain ) {
            if ( $host === $domain || str_ends_with($host, '.' . $domain) ) {
                return true;
            }
        }

        return false;

    }

    /**
     * Extracts hostnames from a MongoDB URI.
     * mongodb:// - seed list, every host is returned;
     * mongodb+srv:// - exactly one SRV hostname (no comma list, no port).
     *
     * @throws \InvalidArgumentException
     *
     * @return string[]
     */
    public static function extractMongoHosts(string $uri) : array {

        $schemeParts = explode('://', $uri, 2);

        if ( count($schemeParts) !== 2 ) {
            throw new \InvalidArgumentException('Invalid MongoDB URI.');
        }

        $scheme = strtolower(trim($schemeParts[0]));

        if ( !in_array($scheme, ['mongodb', 'mongodb+srv'], true) ) {
            throw new \InvalidArgumentException('Invalid MongoDB URI scheme.');
        }

        $isSrv = ($scheme === 'mongodb+srv');

        // Authority: from scheme terminator to the first "/", "?" or "#".
        $authority = $schemeParts[1];
        foreach (['/', '?', '#'] as $terminator) {
            $position = strpos($authority, $terminator);
            if ( $position !== false ) {
                $authority = substr($authority, 0, $position);
            }
        }

        // Credentials are not part of hostnames.
        $atPosition = strrpos($authority, '@');
        if ( $atPosition !== false ) {
            $authority = substr($authority, $atPosition + 1);
        }

        $seeds = $isSrv ? [$authority] : explode(',', $authority);

        $hosts = [];
        foreach ( $seeds as $seed ) {
            $hosts[] = self::extractHost($seed, $isSrv);
        }

        return $hosts;

    }

    /**
     * Extracts a hostname from a single seed: host[:port] or [IPv6](:port).
     *
     * @throws \InvalidArgumentException
     */
    public static function extractHost(?string $seed, bool $isSrv = false) : string {

        $seed = trim((string) $seed);

        if ( $seed === '' ) {
            throw new \InvalidArgumentException('Invalid MongoDB host.');
        }

        if ( $isSrv ) {
            if ( strpos($seed, ':') !== false || strpos($seed, ',') !== false ) {
                throw new \InvalidArgumentException('Invalid SRV host.');
            }
            return $seed;
        }

        if ( $seed[0] === '[' ) {
            $closeBracket = strpos($seed, ']');
            if ( $closeBracket === false ) {
                throw new \InvalidArgumentException('Invalid MongoDB host.');
            }
            $host = substr($seed, 1, $closeBracket - 1);
            $portPart = substr($seed, $closeBracket + 1);
            if ( $portPart !== '' && !self::isValidPort(ltrim($portPart, ':')) ) {
                throw new \InvalidArgumentException('Invalid MongoDB host.');
            }
            return $host;
        }

        $colonPosition = strpos($seed, ':');
        if ( $colonPosition !== false ) {
            if ( !self::isValidPort(substr($seed, $colonPosition + 1)) ) {
                throw new \InvalidArgumentException('Invalid MongoDB host.');
            }
            return substr($seed, 0, $colonPosition);
        }

        return $seed;

    }

    /**
     * Asserts that every hostname of the URI is on the allowlist.
     * Rejection message contains hostnames only, never the full URI.
     *
     * @throws \InvalidArgumentException
     */
    public static function assertMongoUriAllowed(string $uri) : void {

        foreach ( self::extractMongoHosts($uri) as $host ) {
            if ( !self::isHostAllowed($host) ) {
                throw new \InvalidArgumentException('Host not allowed: ' . $host);
            }
        }

    }

    private static function normalizeHost(string $host) : string {

        $host = strtolower(trim($host));

        while ( str_ends_with($host, '.') ) {
            $host = substr($host, 0, -1);
        }

        return $host;

    }

    private static function isValidPort(string $port) : bool {

        if ( !preg_match('/^\d+$/', $port) ) {
            return false;
        }

        $value = (int) $port;

        return $value >= 1 && $value <= 65535;

    }

    private static function normalizeList(array $list) : array {

        $normalized = [];
        foreach ( $list as $item ) {
            $item = self::normalizeHost($item);
            if ( $item !== '' ) {
                $normalized[] = $item;
            }
        }

        return array_values(array_unique($normalized));

    }

    private static function envList(string $envName) : array {

        $raw = self::envRaw($envName);

        if ( $raw === null ) {
            return [];
        }

        $items = array_map('trim', explode(',', $raw));

        return array_values(array_filter($items, static function(string $item) : bool {
            return $item !== '';
        }));

    }

    private static function envInt(string $envName, int $default, int $min, ?int $max = null) : int {

        $raw = self::envRaw($envName);

        if ( $raw === null ) {
            return $default;
        }

        if ( !preg_match('/^-?\d+$/', $raw) ) {
            error_log("MPG config | $envName=$raw is not a valid integer; using default $default.");
            return $default;
        }

        $int = (int) $raw;

        if ( $int < $min ) {
            error_log("MPG config | $envName=$raw is below minimum $min; using $min.");
            return $min;
        }

        if ( $max !== null && $int > $max ) {
            error_log("MPG config | $envName=$raw is above maximum $max; using $max.");
            return $max;
        }

        return $int;

    }

    private static function envBool(string $envName, bool $default) : bool {

        $raw = self::envRaw($envName);

        if ( $raw === null ) {
            return $default;
        }

        return in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true);

    }

    private static function envRaw(string $envName) : ?string {

        $value = $_ENV[$envName] ?? getenv($envName);

        if ( $value === false || $value === null ) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;

    }

}
