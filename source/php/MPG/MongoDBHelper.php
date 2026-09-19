<?php

namespace MPG;

use MongoDB\Client;
use \MongoDB\BSON\Regex;

/**
 * @deprecated
 * TODO: Split this helper into many helpers.
 */
class MongoDBHelper {

    /**
     * Regular expression for a MongoDB URI.
     * 
     * @var string
     */
    public const URI_REGEX = '/^mongodb(\+srv)?:\/\/.+$/';

    /**
     * Regular expression for a MongoDB ObjectID.
     * 
     * @var string
     * @see https://stackoverflow.com/questions/20988446/regex-for-mongodb-objectid
     */
    public const OBJECT_ID_REGEX = '/^[a-f\d]{24}$/i';

    /**
     * Regular expression for an unsigned integer.
     * 
     * @var string
     */
    public const UINT_REGEX = '/^(0|[1-9][0-9]*)$/';

    /**
     * Regular expression for an ISO date-time.
     * 
     * @var string
     * @see https://stackoverflow.com/questions/3143070/javascript-regex-iso-datetime
     */
    public const ISO_DATE_TIME_REGEX = '/\d{4}-[01]\d-[0-3]\dT[0-2]\d:[0-5]\d:[0-5]\d\.\d+([+-][0-2]\d:[0-5]\d|Z)/';

    /**
     * Regular expression for a regular expression.
     * 
     * @var string
     */
    public const REGEX = '#^/(.+)/([igmsuy]*)$#';

    /**
     * MongoDB clients, keyed by session id.
     *
     * A FPM worker serves many sessions over its lifetime; a single
     * process-wide client would leak user A's connection (database,
     * topology, credentials) into user B's requests on the same worker.
     *
     * @var array<string, MongoDB\Client>
     */
    private static $clients = [];

    /**
     * Creates a MongoDB client.
     * 
     * @throws \Exception
     * @return MongoDB\Client
     */
    private static function createClient() : Client {

        if ( !isset($_SESSION['mpg']['user_is_logged']) ) {
            throw new \Exception('Session expired. Refresh browser.');
        }

        if ( isset($_SESSION['mpg']['mongodb_uri']) ) {

            $clientUri = $_SESSION['mpg']['mongodb_uri'];

        } else {
            
            $clientUri = 'mongodb://';

            if ( isset($_SESSION['mpg']['mongodb_user'])
                && isset($_SESSION['mpg']['mongodb_password'])
            ) {
                $clientUri .= rawurlencode($_SESSION['mpg']['mongodb_user']) . ':';
                $clientUri .= rawurlencode($_SESSION['mpg']['mongodb_password']) . '@';
            }
    
            $clientUri .= $_SESSION['mpg']['mongodb_host'];
    
            if ( isset($_SESSION['mpg']['mongodb_port']) ) {
                $clientUri .= ':' . $_SESSION['mpg']['mongodb_port'];
            }
            // When it's not defined: port defaults to 27017.
    
            if ( isset($_SESSION['mpg']['mongodb_database']) ) {
                $clientUri .= '/' . $_SESSION['mpg']['mongodb_database'];
            }

        }

        // Defense in depth: the URI lives in the session, so the allowlist
        // is re-checked before every client creation.
        if ( isset($_SESSION['mpg']['mongodb_uri']) ) {

            try {
                AppConfig::assertMongoUriAllowed($_SESSION['mpg']['mongodb_uri']);
            } catch (\InvalidArgumentException $exception) {
                throw new \Exception($exception->getMessage());
            }

        } else {

            try {
                $host = AppConfig::extractHost($_SESSION['mpg']['mongodb_host']);
                if ( !AppConfig::isHostAllowed($host) ) {
                    throw new \InvalidArgumentException('Host not allowed: ' . $host);
                }
            } catch (\InvalidArgumentException $exception) {
                $message = str_starts_with($exception->getMessage(), 'Host not allowed')
                    ? $exception->getMessage()
                    : 'Invalid host.';
                throw new \Exception($message);
            }

        }

        return new Client($clientUri, [
            'serverSelectionTimeoutMS' => AppConfig::serverSelectionTimeoutMs(),
            'connectTimeoutMS' => AppConfig::connectTimeoutMs(),
            'socketTimeoutMS' => AppConfig::socketTimeoutMs(),
        ]);

    }

    /**
     * Gets the MongoDB client for the current session.
     *
     * Note: clients are kept per worker process; bounded recycling of
     * workers (pm.max_requests) keeps their count in check.
     *
     * @return MongoDB\Client
     */
    public static function getClient() : Client {

        $sessionId = session_id();

        if ( !isset(self::$clients[$sessionId]) ) {
            self::$clients[$sessionId] = self::createClient();
        }

        return self::$clients[$sessionId];

    }

    /**
     * Drops the cached client of the current session (logout).
     *
     * Every successful login rotates the session id, so a re-login always
     * gets a fresh client via the new cache key; without this cleanup the
     * previous connection (its topology and credentials) would keep living
     * in the long-lived FPM worker memory until pm.max_requests recycling.
     */
    public static function clearClient() : void {

        $sessionId = session_id();

        if ( isset(self::$clients[$sessionId]) ) {
            unset(self::$clients[$sessionId]);
        }

    }

    /**
     * Creates a MongoDB Regex from a string.
     * 
     * @throws \Exception
     * @return \MongoDB\BSON\Regex
     */
    public static function createRegexFromString(string $regexAsString) : Regex {

        $regexParts = [];

        if ( !preg_match(self::REGEX, $regexAsString, $regexParts) ) {
            throw new \Exception($regexAsString . ' is not a regular expression.');
        }

        $regexPattern = $regexParts[1];
        $regexFlags = $regexParts[2];

        return new Regex($regexPattern, $regexFlags);

    }

}
