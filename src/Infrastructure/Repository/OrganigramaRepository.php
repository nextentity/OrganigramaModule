<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Domain\Model\Client;
use App\Domain\Model\User;
use App\Domain\Model\UserInput;
use App\Domain\Model\UserProfile;
use App\Domain\Repository\OrganigramaRepositoryInterface;
use App\Infrastructure\Database\SchemaMigrator;
use App\Infrastructure\Database\Seeding\OrganizationFixture;
use App\Infrastructure\Database\Seeding\OrganizationSeeder;
use PDO;

final class OrganigramaRepository implements OrganigramaRepositoryInterface
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $projectRoot,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function findAllClients(): array
    {
        $stmt = $this->pdo->prepare('SELECT id, client_hash, name, description, created FROM clients ORDER BY name ASC');
        $stmt->execute();

        return array_map($this->hydrateClient(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function findClientById(int $id): ?Client
    {
        $stmt = $this->pdo->prepare('SELECT id, client_hash, name, description, created FROM clients WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        return $this->hydrateClient($row);
    }

    /**
     * {@inheritdoc}
     */
    public function findUsersByClientId(int $clientId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, user_hash, client_id, user_id, email, name, last_name,
                    identification_number, identification_code, block_login, created, modified
             FROM users
             WHERE client_id = :client_id
             ORDER BY id ASC'
        );
        $stmt->bindValue(':client_id', $clientId, PDO::PARAM_INT);
        $stmt->execute();

        return array_map($this->hydrateUser(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function findUserById(int $id): ?User
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, user_hash, client_id, user_id, email, name, last_name,
                    identification_number, identification_code, block_login, created, modified
             FROM users
             WHERE id = :id LIMIT 1'
        );
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        return $this->hydrateUser($row);
    }

    public function findUserByIdAndClientId(int $id, int $clientId): ?User
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, user_hash, client_id, user_id, email, name, last_name,
                    identification_number, identification_code, block_login, created, modified
             FROM users
             WHERE id = :id AND client_id = :client_id LIMIT 1'
        );
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':client_id', $clientId, PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        return $this->hydrateUser($row);
    }

    public function existsEmail(string $email, ?int $excludeUserId = null): bool
    {
        $sql = 'SELECT 1 FROM users WHERE LOWER(email) = LOWER(:email)';
        if ($excludeUserId !== null) {
            $sql .= ' AND id <> :exclude_id';
        }
        $sql .= ' LIMIT 1';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':email', trim($email), PDO::PARAM_STR);
        if ($excludeUserId !== null) {
            $stmt->bindValue(':exclude_id', $excludeUserId, PDO::PARAM_INT);
        }
        $stmt->execute();

        return (bool) $stmt->fetchColumn();
    }

    public function createUser(UserInput $input, string $userHash, string $passwordHash): User
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (
                user_hash, client_id, user_id, email, password, name, last_name,
                identification_number, identification_code, block_login, created_by
             ) VALUES (
                :user_hash, :client_id, :user_id, :email, :password, :name, :last_name,
                :identification_number, :identification_code, :block_login, :created_by
             )'
        );

        $stmt->bindValue(':user_hash', $userHash, PDO::PARAM_STR);
        $stmt->bindValue(':client_id', $input->clientId, PDO::PARAM_INT);
        $stmt->bindValue(':user_id', $input->supervisorId, $input->supervisorId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':email', strtolower($input->email), PDO::PARAM_STR);
        $stmt->bindValue(':password', $passwordHash, PDO::PARAM_STR);
        $stmt->bindValue(':name', $input->name, PDO::PARAM_STR);
        $stmt->bindValue(':last_name', $input->lastName, PDO::PARAM_STR);
        $stmt->bindValue(':identification_number', $input->identificationNumber, PDO::PARAM_INT);
        $stmt->bindValue(
            ':identification_code',
            $input->identificationCode,
            $input->identificationCode === null ? PDO::PARAM_NULL : PDO::PARAM_INT
        );
        $stmt->bindValue(':block_login', ($input->blockLogin ?? false) ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':created_by', 'api:user', PDO::PARAM_STR);

        $stmt->execute();

        $id = (int) $this->pdo->lastInsertId();

        return $this->findUserById($id) ?? throw new \RuntimeException("No se pudo recuperar el usuario recién creado con ID: {$id}");
    }

    public function updateUser(int $userId, UserProfile $profile, ?int $supervisorId): User
    {
        $stmt = $this->pdo->prepare(
            'UPDATE users
             SET email = :email,
                 name = :name,
                 last_name = :last_name,
                 identification_number = :identification_number,
                 identification_code = :identification_code,
                 block_login = :block_login,
                 user_id = :user_id,
                 modified_by = :modified_by
             WHERE id = :id'
        );

        $stmt->bindValue(':email', strtolower($profile->email), PDO::PARAM_STR);
        $stmt->bindValue(':name', $profile->name, PDO::PARAM_STR);
        $stmt->bindValue(':last_name', $profile->lastName, PDO::PARAM_STR);
        $stmt->bindValue(':identification_number', $profile->identificationNumber, PDO::PARAM_INT);
        $stmt->bindValue(
            ':identification_code',
            $profile->identificationCode,
            $profile->identificationCode === null ? PDO::PARAM_NULL : PDO::PARAM_INT
        );
        $stmt->bindValue(':block_login', $profile->blockLogin ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':user_id', $supervisorId, $supervisorId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':modified_by', 'api:user', PDO::PARAM_STR);
        $stmt->bindValue(':id', $userId, PDO::PARAM_INT);

        $stmt->execute();

        return $this->findUserById($userId) ?? throw new \RuntimeException("No se pudo recuperar el usuario actualizado con ID: {$userId}");
    }

    public function updateUserWithInversion(
        int $userId,
        UserProfile $profile,
        array $pathNodeIds,
        ?int $newTopSupervisorId,
        int $clientId
    ): User {
        $this->pdo->beginTransaction();

        try {
            // 1. Promover el nodo inferior de la ruta ($pathNodeIds[0]) para que tome el lugar superior
            $topPromotedId = $pathNodeIds[0];
            $stmtTop = $this->pdo->prepare(
                'UPDATE users SET user_id = :new_supervisor, modified_by = :modified_by WHERE id = :id AND client_id = :client_id'
            );
            $stmtTop->bindValue(':new_supervisor', $newTopSupervisorId, $newTopSupervisorId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmtTop->bindValue(':modified_by', 'api:promote', PDO::PARAM_STR);
            $stmtTop->bindValue(':id', $topPromotedId, PDO::PARAM_INT);
            $stmtTop->bindValue(':client_id', $clientId, PDO::PARAM_INT);
            $stmtTop->execute();

            // 2. Invertir la dependencia en los nodos intermedios
            $count = count($pathNodeIds);
            if ($count > 2) {
                $stmtNode = $this->pdo->prepare(
                    'UPDATE users SET user_id = :new_supervisor, modified_by = :modified_by WHERE id = :id AND client_id = :client_id'
                );

                for ($i = 1; $i < $count - 1; $i++) {
                    $currentNodeId = $pathNodeIds[$i];
                    $newParentId = $pathNodeIds[$i - 1];

                    $stmtNode->bindValue(':new_supervisor', $newParentId, PDO::PARAM_INT);
                    $stmtNode->bindValue(':modified_by', 'api:promote', PDO::PARAM_STR);
                    $stmtNode->bindValue(':id', $currentNodeId, PDO::PARAM_INT);
                    $stmtNode->bindValue(':client_id', $clientId, PDO::PARAM_INT);
                    $stmtNode->execute();
                }
            }

            // 3. Actualizar el usuario editado con su nuevo perfil y su nuevo supervisor (el nodo anterior en la ruta invertida)
            $newDirectSupervisorForUser = $pathNodeIds[$count - 2];
            $this->updateUser($userId, $profile, $newDirectSupervisorForUser);

            $this->pdo->commit();

            return $this->findUserById($userId) ?? throw new \RuntimeException("No se pudo recuperar el usuario actualizado con ID: {$userId}");
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    public function countUsersByClientId(int $clientId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM users WHERE client_id = :client_id');
        $stmt->bindValue(':client_id', $clientId, PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    public function reassignSubordinates(int $oldSupervisorId, ?int $newSupervisorId, int $clientId): int
    {
        $stmt = $this->pdo->prepare(
            'UPDATE users
             SET user_id = :new_supervisor_id,
                 modified_by = :modified_by
             WHERE user_id = :old_supervisor_id AND client_id = :client_id'
        );

        $stmt->bindValue(':new_supervisor_id', $newSupervisorId, $newSupervisorId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':old_supervisor_id', $oldSupervisorId, PDO::PARAM_INT);
        $stmt->bindValue(':client_id', $clientId, PDO::PARAM_INT);
        $stmt->bindValue(':modified_by', 'api:reassign', PDO::PARAM_STR);

        $stmt->execute();

        return $stmt->rowCount();
    }

    public function deleteUser(int $userId, int $clientId): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM users WHERE id = :id AND client_id = :client_id');
        $stmt->bindValue(':id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':client_id', $clientId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    public function deleteUserAtomic(
        int $userIdToDelete,
        int $clientId,
        ?int $targetSupervisorId,
        bool $isTargetSubordinate,
        ?int $promotedNewSupervisorId
    ): int {
        $this->pdo->beginTransaction();

        try {
            $reassignedCount = 0;

            if ($targetSupervisorId !== null && $isTargetSubordinate) {
                // 1. Promover al subordinado a la posición vacante que deja el usuario eliminado
                $stmtPromote = $this->pdo->prepare(
                    'UPDATE users SET user_id = :promoted_supervisor, modified_by = :modified_by WHERE id = :id AND client_id = :client_id'
                );
                $stmtPromote->bindValue(
                    ':promoted_supervisor',
                    $promotedNewSupervisorId,
                    $promotedNewSupervisorId === null ? PDO::PARAM_NULL : PDO::PARAM_INT
                );
                $stmtPromote->bindValue(':modified_by', 'api:delete-promote', PDO::PARAM_STR);
                $stmtPromote->bindValue(':id', $targetSupervisorId, PDO::PARAM_INT);
                $stmtPromote->bindValue(':client_id', $clientId, PDO::PARAM_INT);
                $stmtPromote->execute();

                // 2. Reasignar todos los subordinados directos del usuario eliminado al subordinado promovido (excepto a él mismo)
                $stmtReassign = $this->pdo->prepare(
                    'UPDATE users SET user_id = :target_supervisor, modified_by = :modified_by WHERE user_id = :old_supervisor AND id <> :target_id AND client_id = :client_id'
                );
                $stmtReassign->bindValue(':target_supervisor', $targetSupervisorId, PDO::PARAM_INT);
                $stmtReassign->bindValue(':modified_by', 'api:reassign', PDO::PARAM_STR);
                $stmtReassign->bindValue(':old_supervisor', $userIdToDelete, PDO::PARAM_INT);
                $stmtReassign->bindValue(':target_id', $targetSupervisorId, PDO::PARAM_INT);
                $stmtReassign->bindValue(':client_id', $clientId, PDO::PARAM_INT);
                $stmtReassign->execute();
                $reassignedCount = $stmtReassign->rowCount() + 1; // +1 por el propio subordinado que fue promovido
            } else {
                // Reasignación directa o a grandparent
                $stmtReassign = $this->pdo->prepare(
                    'UPDATE users SET user_id = :target_supervisor, modified_by = :modified_by WHERE user_id = :old_supervisor AND client_id = :client_id'
                );
                $stmtReassign->bindValue(
                    ':target_supervisor',
                    $targetSupervisorId,
                    $targetSupervisorId === null ? PDO::PARAM_NULL : PDO::PARAM_INT
                );
                $stmtReassign->bindValue(':modified_by', 'api:reassign', PDO::PARAM_STR);
                $stmtReassign->bindValue(':old_supervisor', $userIdToDelete, PDO::PARAM_INT);
                $stmtReassign->bindValue(':client_id', $clientId, PDO::PARAM_INT);
                $stmtReassign->execute();
                $reassignedCount = $stmtReassign->rowCount();
            }

            // 3. Eliminar al usuario objetivo dentro de la misma transacción
            $stmtDelete = $this->pdo->prepare('DELETE FROM users WHERE id = :id AND client_id = :client_id');
            $stmtDelete->bindValue(':id', $userIdToDelete, PDO::PARAM_INT);
            $stmtDelete->bindValue(':client_id', $clientId, PDO::PARAM_INT);
            $stmtDelete->execute();

            $this->pdo->commit();

            return $reassignedCount;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    public function resetSeedData(): void
    {
        $migrator = new SchemaMigrator($this->pdo, $this->projectRoot . '/database/schema');
        $migrator->dropAllTables();
        $migrator->migrate();

        $fixturesPath = $this->projectRoot . '/database/fixtures/demo_organizations.php';
        if (file_exists($fixturesPath)) {
            $organizations = OrganizationFixture::collectionFromArray(require $fixturesPath);
            $seeder = new OrganizationSeeder($this->pdo, 'Demo1234!', 'api:reset', 4);
            $seeder->seed($organizations);
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateUser(array $row): User
    {
        return new User(
            id: (int) $row['id'],
            userHash: (string) $row['user_hash'],
            clientId: (int) $row['client_id'],
            supervisorId: $row['user_id'] === null ? null : (int) $row['user_id'],
            email: (string) $row['email'],
            name: (string) $row['name'],
            lastName: (string) $row['last_name'],
            identificationNumber: (int) $row['identification_number'],
            identificationCode: $row['identification_code'] === null ? null : (int) $row['identification_code'],
            blockLogin: (bool) $row['block_login'],
            created: (string) $row['created'],
            modified: $row['modified'] !== null ? (string) $row['modified'] : null,
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateClient(array $row): Client
    {
        return new Client(
            id: (int) $row['id'],
            clientHash: (string) $row['client_hash'],
            name: (string) $row['name'],
            description: $row['description'] !== null ? (string) $row['description'] : null,
            created: (string) $row['created'],
        );
    }
}
