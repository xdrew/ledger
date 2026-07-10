<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php70\Rector\FuncCall\RandomFunctionRector;
use Rector\Php80\Rector\Class_\StringableForToStringRector;
use Rector\Php83\Rector\ClassMethod\AddOverrideAttributeToOverriddenMethodsRector;
use Rector\Set\ValueObject\LevelSetList;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->parallel();
    $rectorConfig->cacheDirectory(__DIR__ . '/var/rector');
    $rectorConfig->paths([
        __DIR__ . '/bin/console',
        __DIR__ . '/config',
        __DIR__ . '/migrations',
        __DIR__ . '/public',
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ]);
    $rectorConfig->sets([
        LevelSetList::UP_TO_PHP_85,
    ]);
    $rectorConfig->skip([
        RandomFunctionRector::class,
        StringableForToStringRector::class,
        AddOverrideAttributeToOverriddenMethodsRector::class,
    ]);
};
