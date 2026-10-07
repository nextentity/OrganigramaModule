#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Ejecuta la suite de pruebas automatizadas.
 *
 * Uso:
 *   php tests/run.php                 Ejecuta todo.
 *   php tests/run.php --filter=Cycle  Ejecuta solo los tests cuyo nombre contenga "Cycle".
 */

define('TESTING_MODE', true);
require_once dirname(__DIR__) . '/bootstrap.php';

use Tests\Support\TestRunner;

$options = getopt('', ['filter:']);
$filter = isset($options['filter']) && is_string($options['filter']) ? $options['filter'] : null;

exit((new TestRunner(__DIR__, $filter))->run());

