<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

require __DIR__ . '/../vendor/autoload.php';

$app = AppFactory::create();

$app->get('/', function (Request $request, Response $response, array $args) {
    $response->getBody()->write("P1 API!");

    return $response;
});


$app->post('/login', function (Request $request, Response $response, array $args) {

    $authHeader = $request->getHeaderLine('Authorization');

    if (empty($authHeader)) {
        return $response->withStatus(400, 'Authorization header is missing')->withHeader('Content-Type', 'application/json');
    }


    $headers = [
        "Authorization: $authHeader",
        'Content-Type: application/json'
    ];

    error_log("Received Headers: " . json_encode($headers));

    $url = 'https://hp5.positionett.se/fmi/data/vLatest/databases/PositionEtt_P1PR_PROJECT/sessions';

    $data = [];

    $ch = curl_init($url);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);

    $responseData = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($responseData === false) {
        $error = curl_error($ch);
        error_log("cURL Error: $error");
        $response->getBody()->write(json_encode([
            'success' => false,
            'message' => 'cURL request failed',
            'error' => $error
        ]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }

    curl_close($ch);

    error_log("FileMaker Response Data: " . $responseData);
    error_log("HTTP Status Code: $httpCode");

    if ($httpCode === 401) {
        return $response->withStatus(401, 'Invalid Credentials')->withHeader('Content-Type', 'application/json');
    }

    if ($httpCode === 200) {
        $decodedResponse = json_decode($responseData, true);

        if (isset($decodedResponse['response']['token'])) {
            $token = $decodedResponse['response']['token'];
            error_log("Recieved Token: $token");
            $response->getBody()->write(
                json_encode([
                    'success' => true,
                    'token' => $token
                ])
            );
        }
    }

    return $response->withHeader('Content-Type', 'application/json');
});

//  LOGOUT

$app->delete('/logout', function (Request $request, Response $response, array $args) {

    $authHeader = $request->getHeaderLine('Authorization');

    $token = substr($authHeader, 7);

    error_log($token);

    $url = "https://hp5.positionett.se/fmi/data/vLatest/databases/PositionEtt_P1PR_PROJECT/sessions/$token";

    // $url = "https://hp5.positionett.se/fmi/data/vLatest/databases/dev-Bamse_ph/sessions/$token";

    $ch = curl_init($url);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');

    $responseData = curl_exec($ch);

    if (curl_errno($ch)) {
        $response->getBody()->write('Error: ' . curl_error($ch));
    } else {
        $response->getBody()->write($responseData);
    }

    curl_close($ch);

    return $response;
});

// FETCH ALL USERS

$app->post('/fetchTimeRecords', function (Request $request, Response $response, $args) {

    $authHeader = $request->getHeaderLine('Authorization');
    // $data = $request->getParsedBody();
    $token = substr($authHeader, 7);

    if (empty($authHeader)) {
        return $response->withStatus(400, 'Authorization header is missing')->withHeader('Content-Type', 'application/json');
    }

    if (!$token) {
        $response->getBody()->write(json_encode(["Error" => "Missing Token"]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
    }

    $headers = [
        "Authorization: bearer $token",
        'Content-Type: application/json'
    ];

    $data = json_decode($request->getBody()->getContents(), true);

    if (!isset($data['query']) || !is_array($data['query'])) {
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    };

    $url = "https://hp5.positionett.se/fmi/data/vLatest/databases/PositionEtt_P1PR_PROJECT/layouts/timeDataAPI/_find";

    $ch = curl_init($url);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $filemakerResponse = curl_exec($ch);
    $httpCode = curl_getInfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        $responseBody = json_decode($filemakerResponse, true);

        if (isset($responseBody['response']['data'])) {

            $filteredResponse = array_map(function ($posts) {
                return [
                    'startTime' => $posts['fieldData']['time_time_start'] ?? null,
                    'endTime' => $posts['fieldData']['time_time_end'] ?? null,
                    'projectName' => $posts['fieldData']['common_article_no'] ?? null,
                    'projectId' => $posts['fieldData']['!Project'] ?? null,
                    'commentCustomer' => $posts['fieldData']['common_comment_customer'] ?? null,
                    'commentInternal' => $posts['fieldData']['common_comment_internal'] ?? null,
                    'userInitials' => $posts['fieldData']['time_employee_id'] ?? null,
                    'recordId' => $posts['recordId'] ?? null

                ];
            }, $responseBody['response']['data']);

            $response->getBody()->write(json_encode(["data" => $filteredResponse]));
        } else {

            $response->getBody()->write(json_encode(["error" => "No user data found"]));
        }
    } else {
        $response->getBody()->write(json_encode([
            "error" => "Failed to fetch posts",
            "status_code" => $httpCode
        ]));
    }

    return $response->withHeader('Content-Type', 'application/json')->withStatus($httpCode);
});

$app->post('/registerTime', function (Request $request, Response $response, array $args) {

    $authHeader = $request->getHeaderLine('Authorization');
    $token = substr($authHeader, 7);

    if (empty($authHeader)) {
        return $response->withStatus(400, 'Authorization header missing')->withHeader('Content-Type', 'application/json');
    }

    if (!$token) {
        $response->getBody()->write(json_encode(["Error" => "Missing Token"]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
    }

    $headers = [
        "Authorization: bearer $token",
        'Content-Type: application/json'
    ];

    $data = json_decode($request->getBody()->getContents(), true);

    $url = "https://hp5.positionett.se/fmi/data/vLatest/databases/PositionEtt_P1PR_PROJECT/layouts/timeDataAPI/records";

    $ch = curl_init($url);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $filemakerResponse = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        $responseBody = json_decode($filemakerResponse, true);

        if (isset($responseBody['messages'][0]['code']) && $responseBody['messages'][0]['code'] === "0") {
            $response->getBody()->write(json_encode(["success" => true, "message" => "Time registered successfully"]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
        }
    }

    $response->getBody()->write(json_encode(["success" => false, "message" => "Failed to register time"]));
    return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
});

$app->addErrorMiddleware(true, false, false);

$app->get('/favicon.ico', function (Request $request, Response $response, array $args) {
    return $response->withStatus(204); // No Content
});


$app->run();
