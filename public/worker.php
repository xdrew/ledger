<?php

declare(strict_types=1);

use App\Kernel;
use Nyholm\Psr7\Factory\Psr17Factory;
use Spiral\RoadRunner\Http\PSR7Worker;
use Spiral\RoadRunner\Worker;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\Dotenv\Dotenv;

require_once dirname(__DIR__) . '/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__) . '/.env');

/**
 * RoadRunner HTTP worker. Symfony's RoadRunner integration (baldinof/roadrunner-
 * bundle) does not yet support Symfony 8, so this is a thin hand-rolled worker
 * over spiral/roadrunner-http — the same runtime the bundle wraps — bridging
 * PSR-7 to the Symfony HttpKernel. A proper production runtime is set up in
 * add-deployment.
 */
$appEnv = \is_string($_SERVER['APP_ENV'] ?? null) ? $_SERVER['APP_ENV'] : 'dev';
$kernel = new Kernel($appEnv, (bool) ($_SERVER['APP_DEBUG'] ?? false));
$kernel->boot();

$psr17 = new Psr17Factory();
$worker = new PSR7Worker(Worker::create(), $psr17, $psr17, $psr17);
$toSymfony = new HttpFoundationFactory();
$toPsr = new PsrHttpFactory($psr17, $psr17, $psr17, $psr17);

while (true) {
    try {
        $psrRequest = $worker->waitRequest();
        if ($psrRequest === null) {
            break;
        }
    } catch (\Throwable $error) {
        $worker->getWorker()->error($error->getMessage());

        continue;
    }

    try {
        $symfonyRequest = $toSymfony->createRequest($psrRequest);
        $symfonyResponse = $kernel->handle($symfonyRequest);
        $worker->respond($toPsr->createResponse($symfonyResponse));
        $kernel->terminate($symfonyRequest, $symfonyResponse);
    } catch (\Throwable $error) {
        $worker->getWorker()->error($error->getMessage());
    }
}
