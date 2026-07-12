<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;
use PHPyh\CodingStandard\PhpCsFixerCodingStandard;

$finder = new Finder()
    ->in([__DIR__ . '/src', __DIR__ . '/tests'])
    ->append([
        __FILE__,
        __DIR__ . '/bin/console',
    ]);

$config = new Config()
    ->setCacheFile(__DIR__ . '/var/cache/.php-cs-fixer.cache')
    ->setFinder($finder);

new PhpCsFixerCodingStandard()->applyTo($config);

return $config;
