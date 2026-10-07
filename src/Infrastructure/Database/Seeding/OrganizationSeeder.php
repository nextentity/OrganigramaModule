<?php

declare(strict_types=1);

namespace App\Infrastructure\Database\Seeding;

use PDO;
use PDOStatement;
use Throwable;

/**
 * Inserta tenants y sus jerarquías de forma transaccional e idempotente.
 *
 * - Todas las consultas usan sentencias preparadas (prepare + bindValue + execute).
 * - Cada organización se inserta en su propia transacción: o entra completa o no entra.
 * - Si el cliente ya existe (por nombre) se omite, lo que permite re-ejecutar el seed.
 * - La jerarquía se inserta en pre-orden (jefe antes que subordinados), por lo que
 *   cada user_id referencia siempre a un usuario ya persistido del mismo client_id.
 */
final class OrganizationSeeder
{
    private const HASH_BYTES = 32;

    private ?PDOStatement $findClientIdByName = null;
    private ?PDOStatement $insertClient = null;
    private ?PDOStatement $insertUser = null;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $defaultPlainPassword,
        private readonly string $actor = 'system:seeder',
        private readonly int $passwordHashCost = 10,
    ) {
    }

    /**
     * @param list<OrganizationFixture> $organizations
     */
    public function seed(array $organizations): SeedReport
    {
        $this->prepareStatements();

        $createdClients = [];
        $skippedClients = [];
        $usersCreated = 0;

        foreach ($organizations as $organization) {
            if ($this->findClientId($organization->name) !== null) {
                $skippedClients[] = $organization->name;
                continue;
            }

            $usersCreated += $this->seedOrganizationAtomically($organization);
            $createdClients[] = $organization->name;
        }

        return new SeedReport($createdClients, $skippedClients, $usersCreated);
    }

    private function seedOrganizationAtomically(OrganizationFixture $organization): int
    {
        $this->pdo->beginTransaction();

        try {
            $clientId = $this->persistClient($organization);
            $usersCreated = $this->persistEmployeeTree($clientId, $organization->head, supervisorId: null);
            $this->pdo->commit();

            return $usersCreated;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    private function persistClient(OrganizationFixture $organization): int
    {
        $statement = $this->insertClient;
        $statement->bindValue(':client_hash', $this->generateHash(), PDO::PARAM_STR);
        $statement->bindValue(':name', $organization->name, PDO::PARAM_STR);
        $statement->bindValue(
            ':description',
            $organization->description,
            $organization->description === null ? PDO::PARAM_NULL : PDO::PARAM_STR,
        );
        $statement->bindValue(':created_by', $this->actor, PDO::PARAM_STR);
        $statement->execute();

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Inserta el empleado y, recursivamente, a sus subordinados.
     *
     * @return int Cantidad de usuarios insertados en la rama.
     */
    private function persistEmployeeTree(int $clientId, EmployeeFixture $employee, ?int $supervisorId): int
    {
        $statement = $this->insertUser;
        $statement->bindValue(':user_hash', $this->generateHash(), PDO::PARAM_STR);
        $statement->bindValue(':client_id', $clientId, PDO::PARAM_INT);
        $statement->bindValue(':user_id', $supervisorId, $supervisorId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $statement->bindValue(':email', strtolower($employee->email), PDO::PARAM_STR);
        $statement->bindValue(':password', $this->hashPassword(), PDO::PARAM_STR);
        $statement->bindValue(':name', $employee->name, PDO::PARAM_STR);
        $statement->bindValue(':last_name', $employee->lastName, PDO::PARAM_STR);
        $statement->bindValue(':identification_number', $employee->identificationNumber, PDO::PARAM_INT);
        $statement->bindValue(
            ':identification_code',
            $employee->identificationCode,
            $employee->identificationCode === null ? PDO::PARAM_NULL : PDO::PARAM_INT,
        );
        $statement->bindValue(':block_login', $employee->blockLogin ? 1 : 0, PDO::PARAM_INT);
        $statement->bindValue(':created_by', $this->actor, PDO::PARAM_STR);
        $statement->execute();

        $employeeId = (int) $this->pdo->lastInsertId();
        $usersCreated = 1;

        foreach ($employee->subordinates as $subordinate) {
            $usersCreated += $this->persistEmployeeTree($clientId, $subordinate, $employeeId);
        }

        return $usersCreated;
    }

    private function findClientId(string $clientName): ?int
    {
        $statement = $this->findClientIdByName;
        $statement->bindValue(':name', $clientName, PDO::PARAM_STR);
        $statement->execute();
        $clientId = $statement->fetchColumn();
        $statement->closeCursor();

        return $clientId === false ? null : (int) $clientId;
    }

    /**
     * Se preparan una sola vez y se reutilizan para todo el lote.
     */
    private function prepareStatements(): void
    {
        $this->findClientIdByName ??= $this->pdo->prepare(
            'SELECT id FROM clients WHERE name = :name LIMIT 1'
        );

        $this->insertClient ??= $this->pdo->prepare(
            'INSERT INTO clients (client_hash, name, description, created_by)
             VALUES (:client_hash, :name, :description, :created_by)'
        );

        $this->insertUser ??= $this->pdo->prepare(
            'INSERT INTO users (
                user_hash, client_id, user_id, email, password, name, last_name,
                identification_number, identification_code, block_login, created_by
             ) VALUES (
                :user_hash, :client_id, :user_id, :email, :password, :name, :last_name,
                :identification_number, :identification_code, :block_login, :created_by
             )'
        );
    }

    private function generateHash(): string
    {
        return bin2hex(random_bytes(self::HASH_BYTES));
    }

    private function hashPassword(): string
    {
        return password_hash($this->defaultPlainPassword, PASSWORD_BCRYPT, ['cost' => $this->passwordHashCost]);
    }
}

