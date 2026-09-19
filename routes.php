<?php

namespace MPG;

use Nimbly\Limber\Router\Router;

Routes::setPrefix();

$router = new Router();

/**
 * POST routes are only registered through this wrapper: every POST is
 * CSRF-checked before the controller is ever reached.
 */
$post = static function(string $route, string $controllerMethod) use ($router) : void {

    $router->post($route, static function() use ($controllerMethod) {

        try {
            Csrf::verify();
            [$controller, $method] = explode('@', $controllerMethod, 2);
            return (new $controller())->{$method}();
        } catch (CsrfException) {
            return new JsonResponse(403, [
                'error' => [
                    'code' => 403,
                    'message' => 'CSRF validation failed. Reload the page and retry.'
                ]
            ]);
        }

    });

};

// Public probes (no session, no CSRF, no MongoDB):
$router->get(
    Routes::getPrefix() . '/health',
    HealthController::class . '@health'
);

$router->get(
    Routes::getPrefix() . '/ready',
    HealthController::class . '@ready'
);

$router->get(Routes::getPrefix() . '/', function() {

    AuthController::ensureUserIsLogged();
    Routes::redirectTo('/queryDocuments');

});

$router->get(
    Routes::getPrefix() . '/login',
    AuthController::class . '@login'
);

$post(
    Routes::getPrefix() . '/login',
    AuthController::class . '@login'
);

$router->get(
    Routes::getPrefix() . '/manageCollections',
    CollectionsController::class . '@manage'
);

$post(
    Routes::getPrefix() . '/listCollections',
    CollectionsController::class . '@list'
);

$post(
    Routes::getPrefix() . '/createCollection',
    CollectionsController::class . '@create'
);

$post(
    Routes::getPrefix() . '/renameCollection',
    CollectionsController::class . '@rename'
);

$post(
    Routes::getPrefix() . '/dropCollection',
    CollectionsController::class . '@drop'
);

$router->get(
    Routes::getPrefix() . '/importDocuments',
    DocumentsController::class . '@import'
);

$post(
    Routes::getPrefix() . '/importDocuments',
    DocumentsController::class . '@import'
);

$router->get(
    Routes::getPrefix() . '/visualizeDatabase',
    DatabasesController::class . '@visualize'
);

$router->get(
    Routes::getPrefix() . '/getDatabaseGraph',
    DatabasesController::class . '@getGraph'
);

$router->get(
    Routes::getPrefix() . '/queryDocuments',
    DocumentsController::class . '@query'
);

$post(
    Routes::getPrefix() . '/insertOneDocument',
    DocumentsController::class . '@insertOne'
);

$post(
    Routes::getPrefix() . '/countDocuments',
    DocumentsController::class . '@count'
);

$post(
    Routes::getPrefix() . '/deleteOneDocument',
    DocumentsController::class . '@deleteOne'
);

$post(
    Routes::getPrefix() . '/findDocuments',
    DocumentsController::class . '@find'
);

$post(
    Routes::getPrefix() . '/updateOneDocument',
    DocumentsController::class . '@updateOne'
);

$post(
    Routes::getPrefix() . '/enumCollectionFields',
    CollectionsController::class . '@enumFields'
);

$router->get(
    Routes::getPrefix() . '/manageIndexes',
    IndexesController::class . '@manage'
);

$post(
    Routes::getPrefix() . '/createIndex',
    IndexesController::class . '@create'
);

$post(
    Routes::getPrefix() . '/listIndexes',
    IndexesController::class . '@list'
);

$post(
    Routes::getPrefix() . '/dropIndex',
    IndexesController::class . '@drop'
);

$router->get(
    Routes::getPrefix() . '/manageUsers',
    UsersController::class . '@manage'
);

$post(
    Routes::getPrefix() . '/createUser',
    UsersController::class . '@create'
);

$post(
    Routes::getPrefix() . '/listUsers',
    UsersController::class . '@list'
);

$post(
    Routes::getPrefix() . '/dropUser',
    UsersController::class . '@drop'
);

$router->get(
    Routes::getPrefix() . '/logout',
    AuthController::class . '@logout'
);

return $router;
