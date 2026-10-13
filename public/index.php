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

// Rutas del módulo de Soporte y Triaging Automatizado con IA
$ticketRepository = new App\Infrastructure\Repository\PdoTicketRepository($pdo);
$ticketIngestionService = new App\Application\Service\TicketIngestionService($ticketRepository);
$ticketController = new App\Infrastructure\Http\SupportTicketController($ticketIngestionService, $ticketRepository);

$router->add('POST', '/api/v1/tickets', [$ticketController, 'createTicket']);
$router->add('GET', '/api/v1/tickets/{ticketId}', [$ticketController, 'getTicket']);
$router->add('POST', '/api/v1/tickets/{ticketId}/retry', [$ticketController, 'retryTicket']);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = $_SERVER['REQUEST_URI'] ?? '/';

$router->dispatch($method, $uri);

