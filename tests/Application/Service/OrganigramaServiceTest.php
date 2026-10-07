<?php

declare(strict_types=1);

namespace Tests\Application\Service;

use App\Application\Service\OrganigramaService;
use App\Domain\Exception\HierarchyException;
use App\Domain\Exception\NotFoundException;
use App\Domain\Exception\ValidationException;
use App\Domain\Model\DeletionStrategy;
use App\Domain\Model\UserInput;
use App\Infrastructure\Repository\OrganigramaRepository;
use PDO;
use Tests\Support\HierarchySnapshot;
use Tests\Support\TestCase;

final class OrganigramaServiceTest extends TestCase
{
    private PDO $pdo;
    private OrganigramaRepository $repository;
    private OrganigramaService $service;
    private int $clientId;

    public function setUp(): void
    {
        $this->pdo = $this->createMigratedDatabase();
        $this->repository = new OrganigramaRepository($this->pdo, PROJECT_ROOT);
        $this->service = new OrganigramaService($this->repository);

        // Seed de datos base para los tests
        $this->service->resetSeedData();

        $clients = $this->service->listClients();
        $this->clientId = $clients[0]->id;
    }

    public function testListClientsReturnsAvailableTenants(): void
    {
        $clients = $this->service->listClients();
        $this->assertTrue(count($clients) >= 2);
        $this->assertSame('Acme Tecnología S.A.', $clients[0]->name);
    }

    public function testGetOrganigramaReturnsMetricsAndPrimeNgNodes(): void
    {
        $organigrama = $this->service->getOrganigrama($this->clientId);

        $this->assertTrue(isset($organigrama['client']));
        $this->assertTrue(isset($organigrama['metrics']));
        $this->assertTrue(isset($organigrama['nodes']));

        $metrics = $organigrama['metrics'];
        $this->assertSame(10, $metrics['total_users']);
        $this->assertSame(3, $metrics['hierarchy_depth']);

        $nodes = $organigrama['nodes'];
        $this->assertSame(1, count($nodes), 'Debe haber 1 solo nodo raíz en PrimeNG chart');
        $this->assertSame('person', $nodes[0]['type']);
        $this->assertSame(1, $nodes[0]['level']);
        $this->assertSame(3, count($nodes[0]['children']), 'El CEO de Acme debe tener 3 directores');
    }

    public function testListUsersOrderedByLevelReturnsCorrectHierarchyOrder(): void
    {
        $result = $this->service->listUsersOrderedByLevel($this->clientId);
        $users = $result['users'];

        $this->assertSame(10, count($users));

        // El primer usuario debe ser de Nivel 1 (CEO)
        $this->assertSame(1, $users[0]->level);
        $this->assertNull($users[0]->user->supervisorId);

        // Verificar que los niveles estén ordenados asendentemente (1, 2, 3...)
        $previousLevel = 1;
        foreach ($users as $userView) {
            $this->assertTrue($userView->level >= $previousLevel);
            $previousLevel = $userView->level;
        }
    }

    public function testCreateUserValidatesRequiredFields(): void
    {
        $this->assertThrows(
            fn () => $this->service->createUser(new UserInput(
                clientId: $this->clientId,
                email: '',
                name: '',
                lastName: '',
                identificationNumber: 0
            )),
            ValidationException::class,
            'validación'
        );
    }

    public function testCreateUserSuccess(): void
    {
        $users = $this->service->listUsersOrderedByLevel($this->clientId)['users'];
        $ceoId = $users[0]->user->id;

        $input = new UserInput(
            clientId: $this->clientId,
            email: 'nuevo.director@acme.test',
            name: 'Carlos',
            lastName: 'Nuevo',
            identificationNumber: 99887766,
            hasSupervisor: true,
            supervisorId: $ceoId
        );

        $createdView = $this->service->createUser($input);

        $this->assertSame('nuevo.director@acme.test', $createdView->user->email);
        $this->assertSame(2, $createdView->level);
        $this->assertSame($ceoId, $createdView->user->supervisorId);
    }

    public function testUpdateUserPromotesSubordinateAndInvertsHierarchy(): void
    {
        $users = $this->service->listUsersOrderedByLevel($this->clientId)['users'];
        $ceoView = $users[0];
        $ceoId = $ceoView->user->id;

        // Buscar un usuario de nivel 3 (subordinado indirecto del CEO)
        $level3UserView = null;
        foreach ($users as $u) {
            if ($u->level === 3) {
                $level3UserView = $u;
                break;
            }
        }

        $this->assertTrue($level3UserView !== null);
        $level3UserId = $level3UserView->user->id;

        // Asignar al subordinado Nivel 3 como supervisor del CEO
        $input = new UserInput(
            clientId: $this->clientId,
            email: $ceoView->user->email,
            name: $ceoView->user->name,
            lastName: $ceoView->user->lastName,
            identificationNumber: $ceoView->user->identificationNumber,
            hasSupervisor: true,
            supervisorId: $level3UserId
        );

        $updatedCeoView = $this->service->updateUser($this->clientId, $ceoId, $input);

        // El usuario Nivel 3 ahora debe ser el nuevo CEO (Nivel 1, supervisor = null)
        $newCeoView = $this->service->getUser($this->clientId, $level3UserId);
        $this->assertSame(1, $newCeoView->level);
        $this->assertNull($newCeoView->user->supervisorId);

        // El antiguo CEO ya no es Nivel 1 y cuelga de la jerarquía bajo el nuevo CEO
        $this->assertTrue($updatedCeoView->level > 1);
        $this->assertTrue($updatedCeoView->user->supervisorId !== null);

        // Verificar que el árbol esté 100% sano (raíz única, sin huérfanos ni ciclos)
        $snapshot = HierarchySnapshot::capture($this->pdo, $this->clientId);
        $this->assertTrue($snapshot->isHealthy());
    }

    public function testDeleteUserWithReassignToGrandparent(): void
    {
        // Obtener un gerente (Nivel 2) que tenga subordinados (Nivel 3)
        $users = $this->service->listUsersOrderedByLevel($this->clientId)['users'];
        $ceoId = $users[0]->user->id;

        $managerView = null;
        foreach ($users as $u) {
            if ($u->level === 2 && $u->directReports > 0) {
                $managerView = $u;
                break;
            }
        }

        $this->assertTrue($managerView !== null);
        $managerId = $managerView->user->id;

        // Eliminar al gerente con estrategia ReassignToGrandparent
        $result = $this->service->deleteUser(
            $this->clientId,
            $managerId,
            DeletionStrategy::ReassignToGrandparent
        );

        $this->assertSame($managerId, $result['deleted_user_id']);
        $this->assertTrue($result['reassigned_subordinates_count'] > 0);
        $this->assertSame($ceoId, $result['new_supervisor_id']);

        // Verificar que el árbol siga 100% sano (sin huérfanos ni ciclos)
        $snapshot = HierarchySnapshot::capture($this->pdo, $this->clientId);
        $this->assertTrue($snapshot->isHealthy());
    }

    public function testDeleteUserWithReassignToSpecificUser(): void
    {
        $users = $this->service->listUsersOrderedByLevel($this->clientId)['users'];

        $managers = array_values(array_filter($users, fn ($u) => $u->level === 2));
        $this->assertTrue(count($managers) >= 2);

        $managerToDelete = $managers[0]->user->id;
        $targetManager = $managers[1]->user->id;

        $result = $this->service->deleteUser(
            $this->clientId,
            $managerToDelete,
            DeletionStrategy::ReassignToSpecificUser,
            reassignToUserId: $targetManager
        );

        $this->assertSame($managerToDelete, $result['deleted_user_id']);
        $this->assertSame($targetManager, $result['new_supervisor_id']);

        $snapshot = HierarchySnapshot::capture($this->pdo, $this->clientId);
        $this->assertTrue($snapshot->isHealthy());
    }

    public function testDeleteCeoPromotesChildrenToRoots(): void
    {
        $users = $this->service->listUsersOrderedByLevel($this->clientId)['users'];
        $ceoId = $users[0]->user->id;

        $result = $this->service->deleteUser($this->clientId, $ceoId, DeletionStrategy::ReassignToGrandparent);

        $this->assertSame($ceoId, $result['deleted_user_id']);
        $this->assertNull($result['new_supervisor_id'], 'Los ex-directores ahora no tienen supervisor (pasan a nivel 1)');

        // El nuevo árbol ahora tiene 3 raíces (los ex-directores)
        $snapshot = HierarchySnapshot::capture($this->pdo, $this->clientId);
        $this->assertSame(3, count($snapshot->rootIds));
        $this->assertSame([], $snapshot->unreachableIds);
    }

    public function testDeleteCeoPromotesSelectedSubordinateToNewCeo(): void
    {
        $users = $this->service->listUsersOrderedByLevel($this->clientId)['users'];
        $ceoId = $users[0]->user->id;

        // Elegir a uno de sus directores (subordinado directo) para sucederlo como CEO
        $director = null;
        foreach ($users as $u) {
            if ($u->level === 2) {
                $director = $u->user;
                break;
            }
        }
        $this->assertTrue($director !== null);

        $result = $this->service->deleteUser(
            $this->clientId,
            $ceoId,
            DeletionStrategy::ReassignToSpecificUser,
            reassignToUserId: $director->id
        );

        $this->assertSame($ceoId, $result['deleted_user_id']);
        $this->assertTrue($result['promoted']);

        // El director elegido ahora debe ser el nuevo CEO (Nivel 1, sin jefe)
        $newCeoView = $this->service->getUser($this->clientId, $director->id);
        $this->assertSame(1, $newCeoView->level);
        $this->assertNull($newCeoView->user->supervisorId);

        // El árbol debe mantenerse completamente íntegro con una única raíz (el nuevo CEO)
        $snapshot = HierarchySnapshot::capture($this->pdo, $this->clientId);
        $this->assertTrue($snapshot->isHealthy());
        $this->assertSame(1, count($snapshot->rootIds));
        $this->assertSame($director->id, $snapshot->rootIds[0]);
    }

    public function testCannotDeleteLastUserInCompany(): void
    {
        // Crear un cliente con un solo usuario
        $clientStmt = $this->pdo->prepare('INSERT INTO clients (client_hash, name) VALUES (:hash, :name)');
        $clientStmt->execute([':hash' => bin2hex(random_bytes(16)), ':name' => 'Empresa Unipersonal']);
        $singleClientId = (int) $this->pdo->lastInsertId();

        $userStmt = $this->pdo->prepare(
            'INSERT INTO users (user_hash, client_id, email, password, name, last_name, identification_number)
             VALUES (:hash, :client_id, :email, :password, :name, :last_name, :id_num)'
        );
        $userStmt->execute([
            ':hash' => bin2hex(random_bytes(16)),
            ':client_id' => $singleClientId,
            ':email' => 'solo@unipersonal.test',
            ':password' => 'hash',
            ':name' => 'Solo',
            ':last_name' => 'User',
            ':id_num' => 12345678,
        ]);
        $soloUserId = (int) $this->pdo->lastInsertId();

        // Debe lanzar ValidationException impidiendo la eliminación del único usuario
        $this->assertThrows(
            fn () => $this->service->deleteUser($singleClientId, $soloUserId),
            ValidationException::class,
            'único usuario'
        );
    }
}
