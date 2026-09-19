<?php

namespace MPG;

class ErrorNormalizer {

    /**
     * Normalizes an error.
     * 
     * @param \Throwable $error
     * @param ?string $function
     * 
     * @return array
     */
    public static function normalize(\Throwable $error, ?string $function = null) : array {

        $normalizedError = ['error' => null];

        $normalizedError['error']['code'] = $error->getCode();

        // Details reach the client only in debug mode.
        $normalizedError['error']['message'] = AppConfig::debug()
            ? self::sanitize($error->getMessage())
            : 'An internal error has occurred.';

        if ( !is_null($function) ) {
            $normalizedError['error']['function'] = $function;
        }

        // Raw messages are never logged: driver exceptions carry topology
        // and connection details. The server log gets the sanitized form.
        error_log('MPG error | ' . self::sanitize($error->getMessage())
            . ( is_null($function) ? '' : ' in ' . $function )
            . ' | ' . $error->getFile() . ':' . $error->getLine()
            . ' | ' . $error->getTraceAsString());

        return $normalizedError;

    }

    /**
     * Masks credentials in MongoDB URIs:
     * mongodb://user:pass@host -> mongodb://***@host
     */
    public static function sanitize(string $message) : string {

        return preg_replace('#(mongodb(?:\+srv)?://)[^@/\s]+@#i', '${1}***@', $message);

    }

    /**
     * Normalizes then prints an error prettily then terminates script.
     * 
     * @param \Throwable $error
     */
    public static function prettyPrintAndDie(\Throwable $error) {

        http_response_code(500);
        header('Content-Type: application/json');

        echo json_encode(self::normalize($error), JSON_PRETTY_PRINT);

        die;

    }

}
