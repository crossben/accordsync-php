<?php

declare(strict_types=1);

/*
 * The sync API with the conformance profile, as a front controller for PHP's built-in server:
 *
 *   PHP_CLI_SERVER_WORKERS=8 ACCORD_DATABASE_URL=postgres://… php -S 127.0.0.1:8801 tools/conformance/server.php
 *
 * Run tools/conformance/migrate.php once first.
 */

use Accord\Server\AccordServer;
use Accord\Server\RateLimit\RateLimiter;
use Accord\Server\ServerDefinition;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;

/** @var array{0: ServerDefinition, 1: Closure(): PDO, 2: RateLimiter} $boot */
$boot = require __DIR__ . '/bootstrap.php';
[$definition, $connect, $rateLimiter] = $boot;

$factory = new Psr17Factory();
$request = (new ServerRequestCreator($factory, $factory, $factory, $factory))->fromGlobals();
$response = AccordServer::handler($definition, $connect, $factory, $factory, $rateLimiter)->handle($request);

http_response_code($response->getStatusCode());
foreach ($response->getHeaders() as $name => $values) {
    foreach ($values as $value) {
        header("$name: $value", false);
    }
}
echo $response->getBody();
