<?php

namespace MPG;

class HealthController extends Controller {

    /**
     * Liveness: the application process is alive and accepting requests.
     */
    public function health() : JsonResponse {

        return new JsonResponse(200, ['status' => 'ok']);

    }

    /**
     * Readiness: the application is ready to serve traffic.
     * NOTE: application readiness only, NOT MongoDB readiness
     * (there is no "own" MongoDB - URIs live in user sessions).
     */
    public function ready() : JsonResponse {

        return new JsonResponse(200, ['status' => 'ready']);

    }

}
