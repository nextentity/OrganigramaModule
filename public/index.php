<?php

declare(strict_types=1);

/**
 * Front Controller REST API para gestión de organigramas y usuarios.
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use App\Application\Service\OrganigramaService;
use App\Infrastructure\Http\OrganigramaController;
use App\Infrastructure\Http\Router;
use App\Infrastructure\Repository\OrganigramaRepository;

/** @var PDO $pdo */
$pdo = require PROJECT_ROOT . '/config/db.php';

$repository = new OrganigramaRepository($pdo, PROJECT_ROOT);
$service = new OrganigramaService($repository);
$controller = new OrganigramaController($service);

$router = new Router();

// Rutas de clientes (Tenants)
$router->add('GET', '/api/clients', [$controller, 'listClients']);

// Rutas de organigrama y usuarios acotadas por tenant (client_id)
$router->add('GET', '/api/clients/{clientId}/organigrama', [$controller, 'getOrganigrama']);
$router->add('GET', '/api/clients/{clientId}/users', [$controller, 'listUsers']);
$router->add('GET', '/api/clients/{clientId}/users/{userId}', [$controller, 'getUser']);
$router->add('POST', '/api/clients/{clientId}/users', [$controller, 'createUser']);
$router->add('PUT', '/api/clients/{clientId}/users/{userId}', [$controller, 'updateUser']);
$router->add('DELETE', '/api/clients/{clientId}/users/{userId}', [$controller, 'deleteUser']);

// Reset de datos de prueba
$router->add('POST', '/api/seed/reset', [$controller, 'resetSeed']);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = $_SERVER['REQUEST_URI'] ?? '/';

$router->dispatch($method, $uri);

