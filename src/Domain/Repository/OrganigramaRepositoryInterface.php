<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Model\Client;
use App\Domain\Model\User;
use App\Domain\Model\UserInput;
use App\Domain\Model\UserProfile;

interface OrganigramaRepositoryInterface
{
    /**
     * @return list<Client>
     */
    public function findAllClients(): array;

    public function findClientById(int $id): ?Client;

    /**
     * Devuelve todos los usuarios pertenecientes a un client_id.
     *
     * @return list<User>
     */
    public function findUsersByClientId(int $clientId): array;

    public function findUserById(int $id): ?User;

    public function findUserByIdAndClientId(int $id, int $clientId): ?User;

    public function existsEmail(string $email, ?int $excludeUserId = null): bool;

    public function createUser(UserInput $input, string $userHash, string $passwordHash): User;

    public function updateUser(int $userId, UserProfile $profile, ?int $supervisorId): User;

    /**
     * Actualiza un usuario invirtiendo atómicamente la cadena de jerarquía dada por $pathNodeIds.
     *
     * @param list<int> $pathNodeIds Ruta de IDs desde el subordinado promovido hasta el usuario editado.
     */
    public function updateUserWithInversion(
        int $userId,
        UserProfile $profile,
        array $pathNodeIds,
        ?int $newTopSupervisorId,
        int $clientId
    ): User;

    /**
     * Cuenta la cantidad total de usuarios en un tenant.
     */
    public function countUsersByClientId(int $clientId): int;

    /**
     * Reasigna los subordinados directos de un usuario a un nuevo supervisor.
     */
    public function reassignSubordinates(int $oldSupervisorId, ?int $newSupervisorId, int $clientId): int;

    public function deleteUser(int $userId, int $clientId): bool;

    /**
     * Elimina un usuario de forma atómica reasignando subordinados y promoviendo si corresponde.
     *
     * @param int $userIdToDelete
     * @param int $clientId
     * @param int|null $targetSupervisorId Nuevo supervisor asignado o promovido
     * @param bool $isTargetSubordinate Si el nuevo supervisor era subordinado del eliminado (requiere promoción)
     * @param int|null $promotedNewSupervisorId El supervisor que debe asumir el promovido (ej. null si el eliminado era CEO)
     * @return int Cantidad de subordinados reasignados
     */
    public function deleteUserAtomic(
        int $userIdToDelete,
        int $clientId,
        ?int $targetSupervisorId,
        bool $isTargetSubordinate,
        ?int $promotedNewSupervisorId
    ): int;

    /**
     * Recrea las tablas y vuelve a sembrar los datos de prueba.
     */
    public function resetSeedData(): void;
}

