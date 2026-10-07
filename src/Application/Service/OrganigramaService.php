<?php

declare(strict_types=1);

namespace App\Application\Service;

use App\Domain\Exception\HierarchyException;
use App\Domain\Exception\NotFoundException;
use App\Domain\Exception\ValidationException;
use App\Domain\Model\Client;
use App\Domain\Model\DeletionStrategy;
use App\Domain\Model\HierarchyTree;
use App\Domain\Model\User;
use App\Domain\Model\UserInput;
use App\Domain\Model\UserView;
use App\Domain\Repository\OrganigramaRepositoryInterface;

final class OrganigramaService
{
    public function __construct(
        private readonly OrganigramaRepositoryInterface $repository,
    ) {
    }

    /**
     * Devuelve la lista de empresas clientes (tenants).
     *
     * @return list<Client>
     */
    public function listClients(): array
    {
        return $this->repository->findAllClients();
    }

    public function getClient(int $clientId): Client
    {
        return $this->repository->findClientById($clientId) ?? throw NotFoundException::client($clientId);
    }

    /**
     * Devuelve la estructura jerárquica (nodos PrimeNG) y las métricas de negocio para un cliente.
     *
     * @return array{client: Client, metrics: array<string, mixed>, nodes: list<array<string, mixed>>}
     */
    public function getOrganigrama(int $clientId): array
    {
        $client = $this->getClient($clientId);
        $users = $this->repository->findUsersByClientId($clientId);
        $tree = new HierarchyTree($users);

        return [
            'client'  => $client,
            'metrics' => $tree->metrics(),
            'nodes'   => $tree->toOrgChartNodes(),
        ];
    }

    /**
     * Devuelve la lista de usuarios de un cliente ordenada jerárquicamente por nivel.
     *
     * @return array{client: Client, users: list<UserView>}
     */
    public function listUsersOrderedByLevel(int $clientId): array
    {
        $client = $this->getClient($clientId);
        $users = $this->repository->findUsersByClientId($clientId);
        $tree = new HierarchyTree($users);

        return [
            'client' => $client,
            'users'  => $tree->viewsOrderedByLevel(),
        ];
    }

    public function getUser(int $clientId, int $userId): UserView
    {
        $this->getClient($clientId);
        $users = $this->repository->findUsersByClientId($clientId);
        $tree = new HierarchyTree($users);

        if (!$tree->has($userId)) {
            throw NotFoundException::user($userId);
        }

        return $tree->view($userId);
    }

    /**
     * Crea un nuevo usuario validando campos obligatorios, email único y jerarquía.
     */
    public function createUser(UserInput $input): UserView
    {
        $this->validateUserInput($input);

        // Validar supervisor si se indica uno
        if ($input->supervisorId !== null) {
            $this->validateSupervisor($input->clientId, $input->supervisorId);
        }

        $userHash = bin2hex(random_bytes(32));
        $password = $input->plainPassword ?? 'ChangeMe123!';
        $passwordHash = password_hash($password, PASSWORD_BCRYPT);

        $user = $this->repository->createUser($input, $userHash, $passwordHash);

        return $this->getUser($input->clientId, $user->id);
    }

    /**
     * Actualiza un usuario existente con validación de ciclos y reestructuración fluida.
     */
    public function updateUser(int $clientId, int $userId, UserInput $input): UserView
    {
        $currentUser = $this->repository->findUserByIdAndClientId($userId, $clientId)
            ?? throw NotFoundException::user($userId);

        $this->validateUserInput($input, isUpdate: true, currentUserId: $userId);

        $newSupervisorId = $input->hasSupervisor ? $input->supervisorId : $currentUser->supervisorId;
        $profile = $input->toProfile($currentUser);

        if ($newSupervisorId !== null) {
            // Regla: No puede ser su propio supervisor
            if ($newSupervisorId === $userId) {
                throw HierarchyException::selfReference();
            }

            // Regla: Supervisor debe pertenecer al mismo tenant
            $this->validateSupervisor($clientId, $newSupervisorId);

            // Reestructuración fluida: si se asigna como nuevo jefe a un subordinado (directo o indirecto),
            // en lugar de lanzar error, se promueve al subordinado para que tome la posición superior e invierte la cadena.
            $users = $this->repository->findUsersByClientId($clientId);
            $tree = new HierarchyTree($users);
            if ($tree->isDescendantOf($newSupervisorId, $userId)) {
                $pathNodeIds = $tree->getAncestorPath($newSupervisorId, $userId);
                if ($pathNodeIds !== null && count($pathNodeIds) >= 2) {
                    $this->repository->updateUserWithInversion(
                        $userId,
                        $profile,
                        $pathNodeIds,
                        $currentUser->supervisorId,
                        $clientId
                    );

                    return $this->getUser($clientId, $userId);
                }
            }
        }

        $this->repository->updateUser($userId, $profile, $newSupervisorId);

        return $this->getUser($clientId, $userId);
    }

    /**
     * Elimina un usuario garantizando la integridad jerárquica y la auto-reaparación del árbol.
     * Reasigna automáticamente a los subordinados directos según la estrategia elegida.
     *
     * @param int $clientId
     * @param int $userId
     * @param DeletionStrategy $strategy
     * @param int|null $reassignToUserId
     * @return array{deleted_user_id: int, reassigned_subordinates_count: int, new_supervisor_id: int|null}
     */
    public function deleteUser(
        int $clientId,
        int $userId,
        DeletionStrategy $strategy = DeletionStrategy::ReassignToGrandparent,
        ?int $reassignToUserId = null
    ): array {
        // Regla: Nunca permitir eliminar el último usuario de la empresa
        $totalUsers = $this->repository->countUsersByClientId($clientId);
        if ($totalUsers <= 1) {
            throw ValidationException::single('user_id', 'No se puede eliminar el único usuario registrado de la empresa.');
        }

        $userToDelete = $this->repository->findUserByIdAndClientId($userId, $clientId)
            ?? throw NotFoundException::user($userId);

        $users = $this->repository->findUsersByClientId($clientId);
        $tree = new HierarchyTree($users);
        $directSubordinates = $tree->childrenOf($userId);

        $targetSupervisorId = null;
        $isTargetSubordinate = false;
        $promotedNewSupervisorId = null;

        if ($directSubordinates !== [] || $userToDelete->supervisorId === null) {
            if ($strategy === DeletionStrategy::ReassignToSpecificUser) {
                if ($reassignToUserId === null) {
                    throw HierarchyException::invalidReassignmentTarget();
                }

                if ($reassignToUserId === $userId) {
                    throw HierarchyException::cannotReassignToDeletedUser();
                }

                $targetSupervisor = $this->repository->findUserByIdAndClientId($reassignToUserId, $clientId);
                if ($targetSupervisor === null) {
                    throw HierarchyException::invalidReassignmentTarget();
                }

                // Si el usuario destino es un subordinado (directo o indirecto) de quien se elimina:
                // Se promueve automáticamente a la posición vacante que deja el usuario eliminado.
                if ($tree->isDescendantOf($reassignToUserId, $userId)) {
                    $isTargetSubordinate = true;
                    $targetSupervisorId = $reassignToUserId;
                    $promotedNewSupervisorId = $userToDelete->supervisorId; // Si era CEO pasa a null
                } else {
                    $targetSupervisorId = $reassignToUserId;
                }
            } else { // ReassignToGrandparent
                // Si el eliminado tenía un supervisor (abuelo), los subordinados pasan a él.
                // Si el eliminado era el CEO (null), sus subordinados se convierten en raíces.
                $targetSupervisorId = $userToDelete->supervisorId;
            }
        }

        // Ejecutar reasignación, promoción y borrado dentro de la misma transacción atómica
        $reassignedCount = $this->repository->deleteUserAtomic(
            $userId,
            $clientId,
            $targetSupervisorId,
            $isTargetSubordinate,
            $promotedNewSupervisorId
        );

        return [
            'deleted_user_id'                 => $userId,
            'reassigned_subordinates_count'   => $reassignedCount,
            'new_supervisor_id'               => $targetSupervisorId,
            'promoted'                        => $isTargetSubordinate,
        ];
    }

    /**
     * Resetea la base de datos y la vuelve a sembrar con los datos de prueba.
     */
    public function resetSeedData(): void
    {
        $this->repository->resetSeedData();
    }

    private function validateUserInput(UserInput $input, bool $isUpdate = false, ?int $currentUserId = null): void
    {
        $errors = [];

        if (trim($input->name) === '') {
            $errors['name'] = 'El nombre es un campo obligatorio.';
        }

        if (trim($input->lastName) === '') {
            $errors['last_name'] = 'El apellido es un campo obligatorio.';
        }

        if (trim($input->email) === '') {
            $errors['email'] = 'El email es un campo obligatorio.';
        } elseif (filter_var($input->email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'El formato del email es inválido.';
        } elseif ($this->repository->existsEmail($input->email, $currentUserId)) {
            $errors['email'] = 'El email especificado ya se encuentra registrado.';
        }

        if ($input->identificationNumber <= 0) {
            $errors['identification_number'] = 'El número de identificación debe ser mayor a 0.';
        }

        if ($errors !== []) {
            throw ValidationException::withErrors($errors);
        }
    }

    private function validateSupervisor(int $clientId, int $supervisorId): void
    {
        $supervisor = $this->repository->findUserById($supervisorId);
        if ($supervisor === null || $supervisor->clientId !== $clientId) {
            throw HierarchyException::crossTenantSupervisor();
        }
    }
}

