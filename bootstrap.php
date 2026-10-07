<?php

declare(strict_types=1);

/**
 * Bootstrap común para CLI, API y tests.
 *
 * - Registra un autoloader PSR-4 liviano (sin dependencia de Composer).
 * - Carga variables de entorno desde `.env` (sin sobrescribir las ya definidas).
 */

if (defined('PROJECT_ROOT')) {
    return;
}

define('PROJECT_ROOT', __DIR__);

error_reporting(E_ALL);

spl_autoload_register(static function (string $fullyQualifiedClassName): void {
    /** @var array<string, string> $namespaceMap */
    static $namespaceMap = [
        'App\\'   => PROJECT_ROOT . '/src/',
        'Tests\\' => PROJECT_ROOT . '/tests/',
    ];

    foreach ($namespaceMap as $namespacePrefix => $baseDirectory) {
        if (!str_starts_with($fullyQualifiedClassName, $namespacePrefix)) {
            continue;
        }

        $relativeClassName = substr($fullyQualifiedClassName, strlen($namespacePrefix));
        $classFile = $baseDirectory . str_replace('\\', '/', $relativeClassName) . '.php';

        if (is_file($classFile)) {
            require_once $classFile;
        }

        return;
    }
});

App\Infrastructure\Config\EnvironmentLoader::loadFile(PROJECT_ROOT . '/.env');

