<?php

namespace MPG;

class IndexesController extends Controller {

    public function manage() : ViewResponse {

        AuthController::ensureUserIsLogged();

        return new ViewResponse(200, 'manageIndexes', [
            'databaseNames' => DatabasesController::getDatabaseNames(),
            'viewName' => 'manageIndexes'
        ]);

    }

    /**
     * @see https://docs.mongodb.com/php-library/v1.12/reference/method/MongoDBCollection-createIndex/index.html
     */
    public function create() : JsonResponse {

        try {
            $decodedRequestBody = $this->getDecodedRequestBody();
        } catch (\Throwable $th) {
            return new JsonResponse(400, ErrorNormalizer::normalize($th, __METHOD__));
        }

        try {

            $collection = MongoDBHelper::getClient()->selectCollection(
                $decodedRequestBody['databaseName'], $decodedRequestBody['collectionName']
            );

            $createdIndexName = $collection->createIndex(
                $decodedRequestBody['key'], $decodedRequestBody['options']
            );
            Audit::success('index.create', $decodedRequestBody['databaseName'], $decodedRequestBody['collectionName']);

        } catch (\Throwable $th) {
            Audit::error('index.create', $decodedRequestBody['databaseName'] ?? '', $decodedRequestBody['collectionName'] ?? null);
            return new JsonResponse(self::errorStatus($th), ErrorNormalizer::normalize($th, __METHOD__));
        }

        return new JsonResponse(200, $createdIndexName);

    }

    /**
     * @see https://docs.mongodb.com/php-library/v1.12/reference/method/MongoDBCollection-listIndexes/index.html
     */
    public function list() : JsonResponse {

        try {
            $decodedRequestBody = $this->getDecodedRequestBody();
        } catch (\Throwable $th) {
            return new JsonResponse(400, ErrorNormalizer::normalize($th, __METHOD__));
        }

        $indexes = [];

        try {

            $collection = MongoDBHelper::getClient()->selectCollection(
                $decodedRequestBody['databaseName'], $decodedRequestBody['collectionName']
            );

            foreach ($collection->listIndexes(['maxTimeMS' => AppConfig::queryMaxTimeMs()]) as $indexInfo) {
                $indexes[] = [
                    'name' => $indexInfo->getName(),
                    'keys' => $indexInfo->getKey(),
                    'isUnique' => $indexInfo->isUnique()
                ];
            }

        } catch (\Throwable $th) {
            return new JsonResponse(self::errorStatus($th), ErrorNormalizer::normalize($th, __METHOD__));
        }

        return new JsonResponse(200, $indexes);

    }

    /**
     * @see https://docs.mongodb.com/php-library/v1.12/reference/method/MongoDBCollection-dropIndex/index.html
     */
    public function drop() : JsonResponse {

        try {
            $decodedRequestBody = $this->getDecodedRequestBody();
        } catch (\Throwable $th) {
            return new JsonResponse(400, ErrorNormalizer::normalize($th, __METHOD__));
        }

        try {

            $collection = MongoDBHelper::getClient()->selectCollection(
                $decodedRequestBody['databaseName'], $decodedRequestBody['collectionName']
            );

            // TODO: Check dropIndex result?
            $collection->dropIndex($decodedRequestBody['indexName']);
            Audit::success('index.drop', $decodedRequestBody['databaseName'], $decodedRequestBody['collectionName']);

        } catch (\Throwable $th) {
            Audit::error('index.drop', $decodedRequestBody['databaseName'] ?? '', $decodedRequestBody['collectionName'] ?? null);
            return new JsonResponse(self::errorStatus($th), ErrorNormalizer::normalize($th, __METHOD__));
        }

        return new JsonResponse(200, true);

    }

}
