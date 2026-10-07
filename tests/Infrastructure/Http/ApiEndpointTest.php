<?php

declare(strict_types=1);

namespace Tests\Infrastructure\Http;

use App\Application\Service\OrganigramaService;
use App\Infrastructure\Http\OrganigramaController;
use App\Infrastructure\Http\Router;
use App\Infrastructure\Repository\OrganigramaRepository;
use PDO;
use Tests\Support\TestCase;

final class ApiEndpointTest extends TestCase
{
    private PDO $pdo;
    private Router $router;
    private int $clientId;

    public function setUp(): void
    {
        $this->pdo = $this->createMigratedDatabase();
        $repository = new OrganigramaRepository($this->pdo, PROJECT_ROOT);
        $service = new OrganigramaService($repository);
        $service->resetSeedData();

        $controller = new OrganigramaController($service);

        $this->router = new Router();
        $this->router->add('GET', '/api/clients', [$controller, 'listClients']);
        $this->router->add('GET', '/api/clients/{clientId}/organigrama', [$controller, 'getOrganigrama']);
        $this->router->add('GET', '/api/clients/{clientId}/users', [$controller, 'listUsers']);
        $this->router->add('GET', '/api/clients/{clientId}/users/{userId}', [$controller, 'getUser']);
        $this->router->add('POST', '/api/clients/{clientId}/users', [$controller, 'createUser']);
        $this->router->add('PUT', '/api/clients/{clientId}/users/{userId}', [$controller, 'updateUser']);
        $this->router->add('DELETE', '/api/clients/{clientId}/users/{userId}', [$controller, 'deleteUser']);
        $this->router->add('POST', '/api/seed/reset', [$controller, 'resetSeed']);

        $clients = $repository->findAllClients();
        $this->clientId = $clients[0]->id;
    }

    public function testGetClientsEndpoint(): void
    {
        $response = $this->dispatch('GET', '/api/clients');

        $this->assertSame('success', $response['status']);
        $this->assertTrue(is_array($response['data']));
        $this->assertTrue(count($response['data']) >= 2);
    }

    public function testGetOrganigramaEndpoint(): void
    {
        $response = $this->dispatch('GET', "/api/clients/{$this->clientId}/organigrama");

        $this->assertSame('success', $response['status']);
        $this->assertTrue(isset($response['data']['metrics']));
        $this->assertTrue(isset($response['data']['nodes']));
        $this->assertSame(10, $response['data']['metrics']['total_users']);
    }

    public function testListUsersOrderedByLevelEndpoint(): void
    {
        $response = $this->dispatch('GET', "/api/clients/{$this->clientId}/users");

        $this->assertSame('success', $response['status']);
        $users = $response['data']['users'];
        $this->assertSame(10, count($users));
        $this->assertSame(1, $users[0]['level']);
    }

    public function testCreateUserEndpointValidationError(): void
    {
        $response = $this->dispatch('POST', "/api/clients/{$this->clientId}/users", [
            'name'      => '',
            'last_name' => 'Prueba',
            'email'     => 'invalid-email',
        ]);

        $this->assertSame('error', $response['status']);
        $this->assertTrue(isset($response['details']['email']) || isset($response['details']['name']));
    }

    public function testCreateAndUpdateUserEndpointFlow(): void
    {
        // 1. Create user
        $createResponse = $this->dispatch('POST', "/api/clients/{$this->clientId}/users", [
            'name'                  => 'Test',
            'last_name'             => 'UserAPI',
            'email'                 => 'api.user@acme.test',
            'identification_number' => 88776655,
        ]);

        $this->assertSame('success', $createResponse['status']);
        $userId = $createResponse['data']['id'];
        $this->assertSame('Test UserAPI', $createResponse['data']['full_name']);

        // 2. Edit user
        $updateResponse = $this->dispatch('PUT', "/api/clients/{$this->clientId}/users/{$userId}", [
            'name'                  => 'TestUpdated',
            'last_name'             => 'UserAPI',
            'email'                 => 'api.user.updated@acme.test',
            'identification_number' => 88776655,
        ]);

        $this->assertSame('success', $updateResponse['status']);
        $this->assertSame('TestUpdated UserAPI', $updateResponse['data']['full_name']);
        $this->assertSame('api.user.updated@acme.test', $updateResponse['data']['email']);
    }

    public function testDeleteUserEndpoint(): void
    {
        // Fetch users to get a level 3 user
        $usersResponse = $this->dispatch('GET', "/api/clients/{$this->clientId}/users");
        $users = $usersResponse['data']['users'];

        $targetUser = null;
        foreach ($users as $u) {
            if ($u['level'] === 3) {
                $targetUser = $u;
                break;
            }
        }

        $this->assertTrue($targetUser !== null);

        $deleteResponse = $this->dispatch('DELETE', "/api/clients/{$this->clientId}/users/{$targetUser['id']}");
        $this->assertSame('success', $deleteResponse['status']);
        $this->assertSame($targetUser['id'], $deleteResponse['data']['deleted_user_id']);
    }

    public function testDeleteUserEndpointWithSubordinatePromotion(): void
    {
        // Fetch CEO (level 1)
        $usersResponse = $this->dispatch('GET', "/api/clients/{$this->clientId}/users");
        $users = $usersResponse['data']['users'];

        $ceo = null;
        $director = null;
        foreach ($users as $u) {
            if ($u['level'] === 1) {
                $ceo = $u;
            } elseif ($u['level'] === 2 && $director === null) {
                $director = $u;
            }
        }

        $this->assertTrue($ceo !== null);
        $this->assertTrue($director !== null);

        // Delete CEO promoting director
        $deleteResponse = $this->dispatch('DELETE', "/api/clients/{$this->clientId}/users/{$ceo['id']}", [
            'strategy' => 'reassign_to_specific',
            'reassign_to_user_id' => $director['id'],
        ]);

        $this->assertSame('success', $deleteResponse['status']);
        $this->assertSame($ceo['id'], $deleteResponse['data']['deleted_user_id']);

        // Check new organigrama: promoted director is now root (level 1)
        $orgResponse = $this->dispatch('GET', "/api/clients/{$this->clientId}/organigrama");
        $this->assertSame('success', $orgResponse['status']);
        $this->assertSame($director['id'], $orgResponse['data']['nodes'][0]['data']['id']);
        $this->assertNull($orgResponse['data']['nodes'][0]['data']['user_id']);
    }

    public function testCannotDeleteLastUserEndpoint(): void
    {
        // Create an isolated client with only 1 user
        $stmt = $this->pdo->prepare('INSERT INTO clients (client_hash, name) VALUES (:hash, :name)');
        $stmt->execute(['hash' => 'isolated-tenant-hash', 'name' => 'Solo Corp']);
        $soloClientId = (int) $this->pdo->lastInsertId();

        $userStmt = $this->pdo->prepare('
            INSERT INTO users (user_hash, client_id, user_id, email, password, name, last_name, identification_number)
            VALUES (:hash, :cid, NULL, :email, :pass, :name, :last, :dni)
        ');
        $userStmt->execute([
            'hash'  => 'solo-user-hash',
            'cid'   => $soloClientId,
            'email' => 'solo@corp.test',
            'pass'  => 'secret',
            'name'  => 'Solo',
            'last'  => 'Founder',
            'dni'   => 12345678,
        ]);
        $soloUserId = (int) $this->pdo->lastInsertId();

        $deleteResponse = $this->dispatch('DELETE', "/api/clients/{$soloClientId}/users/{$soloUserId}");
        $this->assertSame('error', $deleteResponse['status']);
        $this->assertTrue(str_contains($deleteResponse['message'] ?? '', 'único usuario'));
    }

    public function testResetSeedEndpoint(): void
    {
        $response = $this->dispatch('POST', '/api/seed/reset');
        $this->assertSame('success', $response['status']);
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    private function dispatch(string $method, string $uri, ?array $body = null): array
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = $uri;
        $GLOBALS['__TEST_REQUEST_BODY__'] = $body ?? [];

        ob_start();
        $this->router->dispatch($method, $uri);
        $output = ob_get_clean();

        unset($GLOBALS['__TEST_REQUEST_BODY__']);

        $decoded = json_decode((string) $output, true);
        return is_array($decoded) ? $decoded : ['output' => $output];
    }
}
