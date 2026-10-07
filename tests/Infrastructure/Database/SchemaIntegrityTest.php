<?php

declare(strict_types=1);

namespace Tests\Infrastructure\Database;

use App\Infrastructure\Database\SchemaMigrator;
use PDO;
use PDOException;
use Tests\Support\TestCase;

/**
 * Verifica que la propia base de datos impida estados jerárquicos inválidos
 * (defensa en profundidad, independiente de la capa de servicios).
 */
final class SchemaIntegrityTest extends TestCase
{
    private PDO $pdo;

    public function setUp(): void
    {
        $this->pdo = $this->createMigratedDatabase();
    }

    public function testMigrationIsIdempotent(): void
    {
        $executed = (new SchemaMigrator($this->pdo, PROJECT_ROOT . '/database/schema'))->migrate();

        $this->assertTrue($executed > 0);
    }

    public function testHierarchyIndexesExist(): void
    {
        $indexes = $this->pdo->query("SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = 'users'")
            ->fetchAll(PDO::FETCH_COLUMN);

        foreach (['idx_users_client_id', 'idx_users_user_id', 'idx_users_client_hierarchy'] as $expectedIndex) {
            $this->assertTrue(in_array($expectedIndex, $indexes, true), "Falta el índice {$expectedIndex}");
        }
    }

    public function testRejectsSupervisorFromAnotherTenant(): void
    {
        $acmeId = $this->insertClient('Acme');
        $globexId = $this->insertClient('Globex');
        $acmeCeoId = $this->insertUser($acmeId, null, 'ceo@acme.test');

        $this->assertThrows(
            fn () => $this->insertUser($globexId, $acmeCeoId, 'intruso@globex.test'),
            PDOException::class,
            'HIERARCHY_CROSS_TENANT',
        );

        $globexCeoId = $this->insertUser($globexId, null, 'ceo@globex.test');
        $this->assertThrows(
            fn () => $this->assignSupervisor($globexCeoId, $acmeCeoId),
            PDOException::class,
            'HIERARCHY_CROSS_TENANT',
        );
    }

    public function testRejectsSelfReference(): void
    {
        $clientId = $this->insertClient('Acme');
        $ceoId = $this->insertUser($clientId, null, 'ceo@acme.test');

        $this->assertThrows(
            fn () => $this->assignSupervisor($ceoId, $ceoId),
            PDOException::class,
            'HIERARCHY_SELF_REFERENCE',
        );
    }

    public function testRejectsDirectAndIndirectCycles(): void
    {
        $clientId = $this->insertClient('Acme');
        $ceoId = $this->insertUser($clientId, null, 'ceo@acme.test');
        $managerId = $this->insertUser($clientId, $ceoId, 'manager@acme.test');
        $analystId = $this->insertUser($clientId, $managerId, 'analyst@acme.test');

        $this->assertThrows(fn () => $this->assignSupervisor($ceoId, $managerId), PDOException::class, 'HIERARCHY_CYCLE');
        $this->assertThrows(fn () => $this->assignSupervisor($ceoId, $analystId), PDOException::class, 'HIERARCHY_CYCLE');
    }

    public function testAllowsValidRestructuring(): void
    {
        $clientId = $this->insertClient('Acme');
        $ceoId = $this->insertUser($clientId, null, 'ceo@acme.test');
        $ctoId = $this->insertUser($clientId, $ceoId, 'cto@acme.test');
        $cfoId = $this->insertUser($clientId, $ceoId, 'cfo@acme.test');
        $devId = $this->insertUser($clientId, $ctoId, 'dev@acme.test');

        $this->assignSupervisor($devId, $cfoId);

        $this->assertSame($cfoId, $this->supervisorOf($devId));
    }

    public function testCannotMoveUserWithSubordinatesToAnotherTenant(): void
    {
        $acmeId = $this->insertClient('Acme');
        $globexId = $this->insertClient('Globex');
        $ceoId = $this->insertUser($acmeId, null, 'ceo@acme.test');
        $this->insertUser($acmeId, $ceoId, 'dev@acme.test');

        $statement = $this->pdo->prepare('UPDATE users SET client_id = :client_id WHERE id = :id');

        $this->assertThrows(
            static fn () => $statement->execute([':client_id' => $globexId, ':id' => $ceoId]),
            PDOException::class,
            'HIERARCHY_TENANT_MOVE',
        );
    }

    public function testDeletingClientCascadesToItsUsersOnly(): void
    {
        $acmeId = $this->insertClient('Acme');
        $globexId = $this->insertClient('Globex');
        $acmeCeoId = $this->insertUser($acmeId, null, 'ceo@acme.test');
        $this->insertUser($acmeId, $acmeCeoId, 'dev@acme.test');
        $this->insertUser($globexId, null, 'ceo@globex.test');

        $this->pdo->prepare('DELETE FROM clients WHERE id = :id')->execute([':id' => $acmeId]);

        $this->assertSame(0, $this->countUsers($acmeId));
        $this->assertSame(1, $this->countUsers($globexId));
    }

    public function testModifiedTimestampIsSetOnUpdate(): void
    {
        $clientId = $this->insertClient('Acme');
        $ceoId = $this->insertUser($clientId, null, 'ceo@acme.test');

        $this->pdo->prepare('UPDATE users SET name = :name WHERE id = :id')->execute([':name' => 'Nuevo', ':id' => $ceoId]);

        $statement = $this->pdo->prepare('SELECT modified FROM users WHERE id = :id');
        $statement->execute([':id' => $ceoId]);

        $this->assertTrue($statement->fetchColumn() !== null, 'modified debe completarse al actualizar');
    }

    private function insertClient(string $name): int
    {
        $this->pdo->prepare('INSERT INTO clients (client_hash, name) VALUES (:hash, :name)')
            ->execute([':hash' => bin2hex(random_bytes(16)), ':name' => $name]);

        return (int) $this->pdo->lastInsertId();
    }

    private function insertUser(int $clientId, ?int $supervisorId, string $email): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO users (user_hash, client_id, user_id, email, password, name, last_name, identification_number)
             VALUES (:hash, :client_id, :user_id, :email, :password, :name, :last_name, :identification_number)'
        );
        $statement->bindValue(':hash', bin2hex(random_bytes(16)));
        $statement->bindValue(':client_id', $clientId, PDO::PARAM_INT);
        $statement->bindValue(':user_id', $supervisorId, $supervisorId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $statement->bindValue(':email', $email);
        $statement->bindValue(':password', 'hash');
        $statement->bindValue(':name', 'Test');
        $statement->bindValue(':last_name', 'User');
        $statement->bindValue(':identification_number', random_int(1, 99_999_999), PDO::PARAM_INT);
        $statement->execute();

        return (int) $this->pdo->lastInsertId();
    }

    private function assignSupervisor(int $userId, int $supervisorId): void
    {
        $this->pdo->prepare('UPDATE users SET user_id = :supervisor_id WHERE id = :id')
            ->execute([':supervisor_id' => $supervisorId, ':id' => $userId]);
    }

    private function supervisorOf(int $userId): ?int
    {
        $statement = $this->pdo->prepare('SELECT user_id FROM users WHERE id = :id');
        $statement->execute([':id' => $userId]);
        $supervisorId = $statement->fetchColumn();

        return $supervisorId === null ? null : (int) $supervisorId;
    }

    private function countUsers(int $clientId): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM users WHERE client_id = :client_id');
        $statement->execute([':client_id' => $clientId]);

        return (int) $statement->fetchColumn();
    }
}

