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

    if ($httpCode === 200 || $httpCode === 401) {
        $responseBody = json_decode($filemakerResponse, true);

        if (isset($responseBody['response']['data'])) {

            $filteredResponse = array_map(function ($posts) {
                return [
                    'time_time_start' => $posts['fieldData']['time_time_start'] ?? null,
                    'time_time_end' => $posts['fieldData']['time_time_end'] ?? null,
                    'common_article_no' => $posts['fieldData']['common_article_no'] ?? null,
                    '!Project' => $posts['fieldData']['!Project'] ?? null,
                    'common_comment_customer' => $posts['fieldData']['common_comment_customer'] ?? null,
                    'common_comment_internal' => $posts['fieldData']['common_comment_internal'] ?? null,
                    'time_employee_id' => $posts['fieldData']['time_employee_id'] ?? null,
                    'recordId' => $posts['recordId'] ?? null,
                    'time_chargeable' => $posts['fieldData']['time_chargeable'] ?? null,
                    'time_not_worked' => $posts['fieldData']['time_not_worked'] ?? null,
                    'common_item_price' => $posts['fieldData']['common_item_price'] ?? null,
                    'time_eventuser::eventuser_done' => $posts['fieldData']['time_eventuser::eventuser_done'] ?? null,
                    '!ID' => $posts['fieldData']['!ID'] ?? null,
                    '!todo' => $posts['fieldData']['!todo'] ?? null

                ];
            }, $responseBody['response']['data']);

            $response->getBody()->write(json_encode(["data" => $filteredResponse]));
        } else {

            $response->getBody()->write(json_encode(["data" => []]));
        }
    } else {
        $response->getBody()->write(json_encode([
            "error" => "Failed to fetch posts",
            "status_code" => $httpCode
        ]));
    }

    return $response->withHeader('Content-Type', 'application/json')->withStatus($httpCode);
});

// FETCH TODO

$app->post('/fetchTodo', function (Request $request, Response $response, $args) {

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

    $url = "https://hp5.positionett.se/fmi/data/vLatest/databases/PositionEtt_P1PR_PROJECT/layouts/todoDataAPI/_find";

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

            // Apply filter and remove any todo_done that is "1"
            $filteredResponse = array_filter($responseBody['response']['data'], function ($posts) {
                // Ensure that todo_done is not "1"
                return $posts['fieldData']['todo_done'] !== "1";
            });

            $filteredResponse = array_map(function ($posts) {
                return [
                    '!ID' => $posts['fieldData']['!ID'] ?? null,
                    'todo_date' => $posts['fieldData']['todo_date'] ?? null,
                    'todo_head' => $posts['fieldData']['todo_head'] ?? null,
                    'todo_text' => $posts['fieldData']['todo_text'] ?? null,
                    'todo_start' => $posts['fieldData']['todo_start'] ?? null,
                    'todo_stop' => $posts['fieldData']['todo_stop'] ?? null,
                    'todo_done' => $posts['fieldData']['todo_done'] ?? null,
                    'recordId' => $posts['recordId'] ?? null,
                    'todo_arendenr' => $posts['fieldData']['todo_arendenr'] ?? null,
                    '!common_our_reference' => $posts['fieldData']['!common_our_reference'] ?? null,
                    '!project' => $posts['fieldData']['!project'] ?? null,
                    '!Article' => $posts['fieldData']['!Article'] ?? null
                ];
            }, $filteredResponse);

            $response->getBody()->write(json_encode(["data" => $filteredResponse]));
        } else {

            $response->getBody()->write(json_encode(["error" => "No todo data found"]));
        }
    } else {
        $response->getBody()->write(json_encode([
            "error" => "Failed to fetch todo posts",
            "status_code" => $httpCode
        ]));
    }

    return $response->withHeader('Content-Type', 'application/json')->withStatus($httpCode);
});

$app->post('/fetchEvents', function (Request $request, Response $response, $args) {

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

    $url = "https://hp5.positionett.se/fmi/data/vLatest/databases/PositionEtt_P1PR_PROJECT/layouts/EventAPI/_find";

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
                    '!ID' => $posts['fieldData']['!ID'] ?? null,
                    '!todo' => $posts['fieldData']['!todo'] ?? null,
                    'event_date_end' => $posts['fieldData']['event_date_end'] ?? null,
                    'event_date_start' => $posts['fieldData']['event_date_start'] ?? null,
                    'event_time_end' => $posts['fieldData']['event_time_end'] ?? null,
                    'event_time_start' => $posts['fieldData']['event_time_start'] ?? null,
                    'recordId' => $posts['recordId'] ?? null,
                    'event_EVENTUSER::!user' => array_map(
                        fn($user) => $user['event_EVENTUSER::!user'] ?? null,
                        $posts['portalData']['event_EVENTUSER'] ?? []
                    ),
                ];
            }, $responseBody['response']['data']);

            $response->getBody()->write(json_encode(["data" => $filteredResponse]));
        } else {

            $response->getBody()->write(json_encode(["error" => "No todo data found"]));
        }
    } else {
        $response->getBody()->write(json_encode([
            "error" => "Failed to fetch event posts",
            "status_code" => $httpCode
        ]));
    }

    return $response->withHeader('Content-Type', 'application/json')->withStatus($httpCode);
});

$app->post('/fetchEventUsers', function (Request $request, Response $response, $args) {

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

    $url = "https://hp5.positionett.se/fmi/data/vLatest/databases/PositionEtt_P1PR_PROJECT/layouts/EventuserAPI/_find";

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
                    '!user' => $posts['fieldData']['!user'] ?? null,
                    '!ID' => $posts['fieldData']['!ID'] ?? null,
                    '!event' => $posts['fieldData']['!event'] ?? null,
                    'eventuser_done' => $posts['fieldData']['eventuser_done'] ?? null,
                    'recordId' => $posts['recordId'] ?? null,
                    
                ];
            }, $responseBody['response']['data']);

            $response->getBody()->write(json_encode(["data" => $filteredResponse]));
        } else {

            $response->getBody()->write(json_encode(["error" => "No todo data found"]));
        }
    } else {
        $response->getBody()->write(json_encode([
            "error" => "Failed to fetch event user posts",
            "status_code" => $httpCode
        ]));
    }

    return $response->withHeader('Content-Type', 'application/json')->withStatus($httpCode);
});

// ADD NEW POST

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

// MODIFY EXISTING POST 

$app->patch("/modifyTime/{recordId}", function (Request $request, Response $response, array $args) {

    $recordId = $args['recordId'];
    $authHeader = $request->getHeaderLine('Authorization');
    $token = substr($authHeader, 7);


    error_log("Parsed body: " . print_r($request->getParsedBody(), true));

    if (empty($authHeader)) {
        return $response->withStatus(400, 'Authorization header is missing')->withHeader('Content-Type', 'application/json');
    }

    if (!$token) {
        $response->getBody()->write(json_encode(["Error" => "Missing Token"]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
    }

    if (empty($recordId)) {
        return $response->withStatus(400, 'Record ID is missing')->withHeader('Content-Type', 'application/json');
    }

    $headers = [
        "Authorization: bearer $token",
        'Content-Type: application/json'
    ];

    $data = json_decode($request->getBody()->getContents(), true);

    // Make sure fieldData is present
    if (!isset($data['fieldData'])) {
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    };

    // Remove the recordId from the data since it's already in the URL
    unset($data['fieldData']['recordId']);

    $url = "https://hp5.positionett.se/fmi/data/vLatest/databases/PositionEtt_P1PR_PROJECT/layouts/timeDataAPI/records/$recordId";

    $ch = curl_init($url);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PATCH");
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $filemakerResponse = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($httpCode === 200) {
        $responseBody = json_decode($filemakerResponse, true);

        if (isset($responseBody['messages'][0]['code']) && $responseBody['messages'][0]['code'] === "0") {
            $response->getBody()->write(json_encode(["success" => true, "message" => "Time modified successfully"]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
        }
    }

    error_log("FileMaker Response: " . $filemakerResponse);
    error_log("HTTP Code: " . $httpCode);

    $response->getBody()->write(json_encode(["success" => false, "message" => "Failed to modify time"]));
    return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
});

$app->patch("/modifyEventUser/{recordId}", function (Request $request, Response $response, array $args) {

    $recordId = $args['recordId'];
    $authHeader = $request->getHeaderLine('Authorization');
    $token = substr($authHeader, 7);


    error_log("Parsed body: " . print_r($request->getParsedBody(), true));

    if (empty($authHeader)) {
        return $response->withStatus(400, 'Authorization header is missing')->withHeader('Content-Type', 'application/json');
    }

    if (!$token) {
        $response->getBody()->write(json_encode(["Error" => "Missing Token"]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
    }

    if (empty($recordId)) {
        return $response->withStatus(400, 'Record ID is missing')->withHeader('Content-Type', 'application/json');
    }

    $headers = [
        "Authorization: bearer $token",
        'Content-Type: application/json'
    ];

    $data = json_decode($request->getBody()->getContents(), true);

    // Make sure fieldData is present
    if (!isset($data['fieldData'])) {
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    };

    // Remove the recordId from the data since it's already in the URL
    unset($data['fieldData']['recordId']);

    $url = "https://hp5.positionett.se/fmi/data/vLatest/databases/PositionEtt_P1PR_PROJECT/layouts/EventuserAPI/records/$recordId";

    $ch = curl_init($url);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PATCH");
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $filemakerResponse = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($httpCode === 200) {
        $responseBody = json_decode($filemakerResponse, true);

        if (isset($responseBody['messages'][0]['code']) && $responseBody['messages'][0]['code'] === "0") {
            $response->getBody()->write(json_encode(["success" => true, "message" => "Time modified successfully"]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
        }
    }

    error_log("FileMaker Response: " . $filemakerResponse);
    error_log("HTTP Code: " . $httpCode);

    $response->getBody()->write(json_encode(["success" => false, "message" => "Failed to modify time"]));
    return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
});

$app->patch("/modifyTodo/{recordId}", function (Request $request, Response $response, array $args) {

    $recordId = $args['recordId'];
    $authHeader = $request->getHeaderLine('Authorization');
    $token = substr($authHeader, 7);

    error_log("Parsed body: " . print_r($request->getParsedBody(), true));

    if (empty($authHeader)) {
        return $response->withStatus(400, 'Authorization header is missing')->withHeader('Content-Type', 'application/json');
    }

    if (!$token) {
        $response->getBody()->write(json_encode(["Error" => "Missing Token"]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
    }

    if (empty($recordId)) {
        return $response->withStatus(400, 'Record ID is missing')->withHeader('Content-Type', 'application/json');
    }

    $headers = [
        "Authorization: bearer $token",
        'Content-Type: application/json'
    ];

    $data = json_decode($request->getBody()->getContents(), true);

    // Make sure fieldData is present
    if (!isset($data['fieldData'])) {
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    };

    // Remove the recordId from the data since it's already in the URL
    unset($data['fieldData']['recordId']);

    $url = "https://hp5.positionett.se/fmi/data/vLatest/databases/PositionEtt_P1PR_PROJECT/layouts/todoDataAPI/records/$recordId";

    $ch = curl_init($url);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PATCH");
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $filemakerResponse = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($httpCode === 200) {
        $responseBody = json_decode($filemakerResponse, true);

        if (isset($responseBody['messages'][0]['code']) && $responseBody['messages'][0]['code'] === "0") {
            $response->getBody()->write(json_encode(["success" => true, "message" => "Time modified successfully"]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
        }
    }

    error_log("FileMaker Response: " . $filemakerResponse);
    error_log("HTTP Code: " . $httpCode);

    $response->getBody()->write(json_encode(["success" => false, "message" => "Failed to modify time"]));
    return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
});

$app->get("/fetchProjectValueList", function (Request $request, Response $response, array $args) {

    $authHeader = $request->getHeaderLine('Authorization');

    if (empty($authHeader)) {
        return $response->withStatus(400, 'Authorization header is missing')->withHeader('Content-Type', 'application/json');
    };

    $headers = [
        "Authorization: $authHeader",
        'Content-Type: application/json'
    ];

    $url = "https://hp5.positionett.se/fmi/data/vLatest/databases/PositionEtt_P1PR_PROJECT/layouts/timeDataAPI";

    $data = [];

    $ch = curl_init($url);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

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

    if ($httpCode === 401) {
        return $response->withStatus(401, "Invalid Credentials")->withHeader('Content-Type', 'appliction/json');
    };

    if ($httpCode === 200) {
        $decodedResponse = json_decode($responseData, true);

        if (isset($decodedResponse['response']['valueLists'])) {
            $filteredResponse = array_map(function ($posts) {
                return [
                    'displayValue' => $posts['displayValue'] ?? null,
                    'value' => $posts['value'] ?? null
                ];
            }, $decodedResponse['response']['valueLists'][1]['values']);
            $response->getBody()->write(json_encode(["valueLists" => $filteredResponse]));
        } else {
            $response->getBody()->write(json_encode(["error" => "No value list found"]));
        };
    };

    return $response->withHeader('Content-Type', 'application/json')->withStatus($httpCode);
});

$app->get("/fetchArticleValueList", function (Request $request, Response $response, array $args) {

    $authHeader = $request->getHeaderLine('Authorization');

    if (empty($authHeader)) {
        return $response->withStatus(400, 'Authorization header is missing')->withHeader('Content-Type', 'application/json');
    };

    $headers = [
        "Authorization: $authHeader",
        'Content-Type: application/json'
    ];

    $url = "https://hp5.positionett.se/fmi/data/vLatest/databases/PositionEtt_P1PR_PROJECT/layouts/timeDataAPI";

    $data = [];

    $ch = curl_init($url);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

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

    if ($httpCode === 401) {
        return $response->withStatus(401, "Invalid Credentials")->withHeader('Content-Type', 'appliction/json');
    };

    if ($httpCode === 200) {
        $decodedResponse = json_decode($responseData, true);

        if (isset($decodedResponse['response']['valueLists'])) {
            $filteredResponse = array_map(function ($posts) {
                return [
                    'displayValue' => $posts['displayValue'] ?? null,
                    'value' => $posts['value'] ?? null
                ];
            }, $decodedResponse['response']['valueLists'][0]['values']);
            $response->getBody()->write(json_encode(["valueLists" => $filteredResponse]));
        } else {
            $response->getBody()->write(json_encode(["error" => "No value list found"]));
        };
    };

    return $response->withHeader('Content-Type', 'application/json')->withStatus($httpCode);
});

$app->addErrorMiddleware(true, false, false);

$app->get('/favicon.ico', function (Request $request, Response $response, array $args) {
    return $response->withStatus(204); // No Content
});

$app->run();
