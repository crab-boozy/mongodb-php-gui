<?php

namespace MPG;

/**
 * Minimal audit trail for mutating operations.
 * One line per operation to stderr (Docker/K8s log streams; no log files).
 * Document contents and exception text never reach the log.
 */
class Audit {

    /**
     * Allowlist of operations that can be audited (no free-form op strings).
     */
    private const OPERATIONS = [
        'document.delete_one' => true,
        'document.insert_one' => true,
        'document.update_one' => true,
        'document.import'     => true,
        'collection.create'   => true,
        'collection.rename'   => true,
        'collection.drop'     => true,
        'index.create'        => true,
        'index.drop'          => true,
        'user.create'         => true,
        'user.drop'           => true,
    ];

    public static function success(string $action, string $database, ?string $collection = null) : void {

        self::log($action, $database, $collection, 'success');

    }

    public static function error(string $action, string $database, ?string $collection = null) : void {

        self::log($action, $database, $collection, 'error');

    }

    private static function log(string $action, string $database, ?string $collection, string $result) : void {

        if ( !isset(self::OPERATIONS[$action]) ) {
            return;
        }

        $sessionId = function_exists('session_id') ? session_id() : '';

        error_log('MPG audit | op=' . $action
            . ' db=' . self::sanitize($database)
            . ' coll=' . ($collection === null ? '-' : self::sanitize($collection))
            . ' result=' . $result
            . ' session=' . ($sessionId === '' ? '-' : substr(hash('sha256', $sessionId), 0, 8))
        );

    }

    /**
     * Log-injection guard: CR/LF are escaped so a name like "foo\nFAKE"
     * stays a single log line.
     */
    private static function sanitize(string $value) : string {

        return str_replace(["\r", "\n"], ['\\r', '\\n'], trim($value));

    }

}
