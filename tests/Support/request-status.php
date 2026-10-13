<?php

/**
 * Boots the application exactly as a web server would (bootstrap/app.php, the real global middleware) and handles one
 * GET request, then prints {"status": ..., "hsts": ...}. Used by TrustedProxiesTest in a subprocess, because
 * TRUSTED_PROXIES is read from the environment when the application is built.
 *
 * Environment: REQ_PATH, REQ_REMOTE_ADDR, optional REQ_FORWARDED_FOR and REQ_FORWARDED_PROTO.
 */

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$server = ['REMOTE_ADDR' => getenv('REQ_REMOTE_ADDR') ?: '127.0.0.1'];

if (getenv('REQ_FORWARDED_FOR')) {
    $server['HTTP_X_FORWARDED_FOR'] = getenv('REQ_FORWARDED_FOR');
}

if (getenv('REQ_FORWARDED_PROTO')) {
    $server['HTTP_X_FORWARDED_PROTO'] = getenv('REQ_FORWARDED_PROTO');
}

$response = $app->make(Kernel::class)->handle(Request::create(getenv('REQ_PATH') ?: '/login', 'GET', [], [], [], $server));

echo json_encode(['status' => $response->getStatusCode(), 'hsts' => $response->headers->get('Strict-Transport-Security')]);
