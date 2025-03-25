<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

require __DIR__ . '/../vendor/autoload.php';

$app = AppFactory::create();

$app->get('/', function (Request $request, Response $response, array $args) {

    $response->getBody()->write("Hello, world!");

    return $response;
});

$app->post('/login', function (Request $request, Response $response, array $args) {
    $url = 'https://hp5.positionett.se/fmi/data/vLatest/databases/dev-Bamse_ph/sessions';

    $username = "Web";
    $password = "fysik-dev-kva-hp5-250220";

    $headers = [
        'Authorization: Basic ' . base64_encode("$username:$password"),
        'Content-Type: application/json'
    ];

    $ch = curl_init($url);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    
    // // Remove POSTFIELDS since FileMaker expects an empty body for authentication
    // curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([]));

    $responseData = curl_exec($ch);

    if (curl_errno($ch)) {
        $response->getBody()->write('Error: ' . curl_error($ch));
    } else {
        $response->getBody()->write($responseData);
    }

    curl_close($ch);


    return $response->withHeader('Content-Type', 'application/json');
});


$app->addErrorMiddleware(true, false, false);

$app->get('/favicon.ico', function (Request $request, Response $response, array $args) {
    return $response->withStatus(204); // No Content
});


$app->run();
