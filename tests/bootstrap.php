<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

// The test suite always runs in the "test" environment. Pin it on both $_ENV
// and $_SERVER *before* bootEnv, because PHPUnit applies its <php> vars after
// this bootstrap runs — otherwise bootEnv would lock APP_ENV to .env's value
// and KernelTestCase (which prefers $_ENV) would boot the wrong environment.
$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';

new Dotenv()->bootEnv(dirname(__DIR__) . '/.env');
