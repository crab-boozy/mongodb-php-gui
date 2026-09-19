<?php

namespace MPG;

class Controller {

    /**
     * Maps a caught exception to an HTTP status: expired sessions
     * surface as 401, everything else stays a server error.
     */
    public static function errorStatus(\Throwable $th) : int {

        return str_starts_with($th->getMessage(), 'Session expired') ? 401 : 500;

    }

    /**
     * If it exists: returns request body.
     * 
     * @return string|null
     */
    private function getRequestBody() : ?string {

        $requestBody = file_get_contents('php://input');

        return is_string($requestBody) ? $requestBody : null;
        
    }

    /**
     * Returns request body, decoded.
     * @deprecated
     * 
     * @throws \Exception
     * 
     * @return array
     */
    public function getDecodedRequestBody() : array {

        $requestBody = $this->getRequestBody();

        if ( is_null($requestBody) ) {
            throw new \Exception('Request body is missing.');
        }

        $decodedRequestBody = json_decode($requestBody, JSON_OBJECT_AS_ARRAY);

        if ( is_null($decodedRequestBody) ) {
            throw new \Exception('Request body is invalid.');
        }

        return $decodedRequestBody;

    }

}
