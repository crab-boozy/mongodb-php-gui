<?php

namespace MPG;

class UsersController extends Controller {

    public function manage() : ViewResponse {

        AuthController::ensureUserIsLogged();
        
        return new ViewResponse(200, 'manageUsers', [
            'databaseNames' => DatabasesController::getDatabaseNames(),
            'viewName' => 'manageUsers'
        ]);

    }

    /**
     * @see https://docs.mongodb.com/manual/reference/command/createUser/
     */
    public function create() : JsonResponse {

        try {
            $decodedRequestBody = $this->getDecodedRequestBody();
        } catch (\Throwable $th) {
            return new JsonResponse(400, ErrorNormalizer::normalize($th, __METHOD__));
        }

        try {

            $database = MongoDBHelper::getClient()->selectDatabase(
                $decodedRequestBody['databaseName']
            );

            // TODO: Check createUser result?
            $database->command([
                'createUser' => $decodedRequestBody['userName'],
                'pwd' => $decodedRequestBody['userPassword'],
                'roles' => $decodedRequestBody['userRoles']
            ]);
            Audit::success('user.create', $decodedRequestBody['databaseName']);

        } catch (\Throwable $th) {
            Audit::error('user.create', $decodedRequestBody['databaseName'] ?? '');
            return new JsonResponse(self::errorStatus($th), ErrorNormalizer::normalize($th, __METHOD__));
        }

        return new JsonResponse(200, true);

    }

    /**
     * @see https://docs.mongodb.com/manual/reference/command/usersInfo/
     */
    public function list() : JsonResponse {

        try {
            $decodedRequestBody = $this->getDecodedRequestBody();
        } catch (\Throwable $th) {
            return new JsonResponse(400, ErrorNormalizer::normalize($th, __METHOD__));
        }

        try {

            $database = MongoDBHelper::getClient()->selectDatabase(
                $decodedRequestBody['databaseName']
            );

            $usersInfoCommandResult = $database->command([
                'usersInfo' => 1,
                'maxTimeMS' => AppConfig::queryMaxTimeMs()
            ]);
            $usersInfo = $usersInfoCommandResult->toArray()[0];

        } catch (\Throwable $th) {
            return new JsonResponse(self::errorStatus($th), ErrorNormalizer::normalize($th, __METHOD__));
        }

        return new JsonResponse(200, $usersInfo);

    }

    /**
     * @see https://docs.mongodb.com/manual/reference/command/dropUser/
     */
    public function drop() : JsonResponse {

        try {
            $decodedRequestBody = $this->getDecodedRequestBody();
        } catch (\Throwable $th) {
            return new JsonResponse(400, ErrorNormalizer::normalize($th, __METHOD__));
        }

        try {

            $database = MongoDBHelper::getClient()->selectDatabase(
                $decodedRequestBody['databaseName']
            );

            // TODO: Check dropUser result?
            $database->command(['dropUser' => $decodedRequestBody['userName']]);
            Audit::success('user.drop', $decodedRequestBody['databaseName']);

        } catch (\Throwable $th) {
            Audit::error('user.drop', $decodedRequestBody['databaseName'] ?? '');
            return new JsonResponse(self::errorStatus($th), ErrorNormalizer::normalize($th, __METHOD__));
        }

        return new JsonResponse(200, true);

    }

}
