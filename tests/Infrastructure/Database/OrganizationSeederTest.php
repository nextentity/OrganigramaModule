<?php

declare(strict_types=1);

namespace Tests\Infrastructure\Database;

use App\Infrastructure\Database\Seeding\OrganizationFixture;
use App\Infrastructure\Database\Seeding\OrganizationSeeder;
use PDO;
use Tests\Support\HierarchySnapshot;
use Tests\Support\TestCase;

final class OrganizationSeederTest extends TestCase
{
    private const PLAIN_PASSWORD = 'Test1234!';

    private PDO $pdo;

    /** @var list<OrganizationFixture> */
    private array $fixtures;

    public function setUp(): void
    {
        $this->pdo = $this->createMigratedDatabase();
        $this->fixtures = OrganizationFixture::collectionFromArray(
            require PROJECT_ROOT . '/database/fixtures/demo_organizations.php'
        );
    }

    public function testFixturesDescribeTwoCompaniesWithThreeLevels(): void
    {
        $this->assertSame(2, count($this->fixtures));

        foreach ($this->fixtures as $organization) {
            $this->assertSame(3, $organization->head->depth(), "{$organization->name} debe tener 3 niveles");
        }
    }

    public function testSeedsEveryCompanyAsASingleHealthyTree(): void
    {
        $report = $this->createSeeder()->seed($this->fixtures);

        $expectedUsers = array_sum(array_map(
            static fn (OrganizationFixture $o): int => $o->head->countWithDescendants(),
            $this->fixtures,
        ));
        $this->assertSame(2, count($report->createdClients));
        $this->assertSame($expectedUsers, $report->usersCreated);

        foreach ($this->clientIds() as $clientId) {
            $snapshot = HierarchySnapshot::capture($this->pdo, $clientId);

            $this->assertSame(1, count($snapshot->rootIds), 'Debe existir exactamente un nodo raíz (CEO)');
            $this->assertSame([], $snapshot->unreachableIds, 'No debe haber ramas huérfanas ni ciclos');
            $this->assertSame([], $snapshot->crossTenantIds, 'Ningún jefe puede pertenecer a otro cliente');
            $this->assertSame(3, $snapshot->maxDepth);
        }
    }

    public function testSeedingIsIdempotent(): void
    {
        $seeder = $this->createSeeder();
        $firstRun = $seeder->seed($this->fixtures);
        $secondRun = $seeder->seed($this->fixtures);

        $this->assertSame(0, $secondRun->usersCreated);
        $this->assertSame($firstRun->createdClients, $secondRun->skippedClients);
        $this->assertSame($firstRun->usersCreated, (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
    }

    public function testPasswordsAreHashedAndHashesAreUnique(): void
    {
        $this->createSeeder()->seed($this->fixtures);

        $users = $this->pdo->query('SELECT user_hash, password FROM users')->fetchAll();

        $this->assertSame(count($users), count(array_unique(array_column($users, 'user_hash'))));

        foreach ($users as $user) {
            $this->assertFalse($user['password'] === self::PLAIN_PASSWORD, 'La contraseña no puede guardarse en texto plano');
            $this->assertTrue(password_verify(self::PLAIN_PASSWORD, $user['password']));
        }
    }

    public function testFailedOrganizationIsRolledBackCompletely(): void
    {
        $duplicatedEmail = $this->fixtures[0]->head->email;
        $brokenOrganization = OrganizationFixture::fromArray([
            'client' => ['name' => 'Empresa Rota'],
            'head' => [
                'name' => 'Ana', 'last_name' => 'Uno', 'email' => 'ana.uno@rota.test', 'identification_number' => 1,
                'subordinates' => [
                    // Email ya usado por otro tenant: viola UNIQUE(email) a mitad de la inserción.
                    ['name' => 'Beto', 'last_name' => 'Dos', 'email' => $duplicatedEmail, 'identification_number' => 2],
                ],
            ],
        ]);

        $seeder = $this->createSeeder();
        $seeder->seed([$this->fixtures[0]]);

        $this->assertThrows(static fn () => $seeder->seed([$brokenOrganization]), \PDOException::class);

        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM clients WHERE name = :name');
        $statement->execute([':name' => 'Empresa Rota']);
        $this->assertSame(0, (int) $statement->fetchColumn(), 'El cliente no debe persistir parcialmente');

        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM users WHERE email = :email');
        $statement->execute([':email' => 'ana.uno@rota.test']);
        $this->assertSame(0, (int) $statement->fetchColumn(), 'Los usuarios no deben persistir parcialmente');
    }

    public function testMaliciousInputIsStoredLiterallyThanksToPreparedStatements(): void
    {
        $maliciousName = "Robert'); DROP TABLE users; --";
        $organization = OrganizationFixture::fromArray([
            'client' => ['name' => $maliciousName],
            'head' => ['name' => $maliciousName, 'last_name' => 'Tables', 'email' => 'bobby@tables.test', 'identification_number' => 1],
        ]);

        $this->createSeeder()->seed([$organization]);

        $statement = $this->pdo->prepare('SELECT name FROM users WHERE email = :email');
        $statement->execute([':email' => 'bobby@tables.test']);
        $this->assertSame($maliciousName, $statement->fetchColumn());
    }

    private function createSeeder(): OrganizationSeeder
    {
        // Costo mínimo de bcrypt para acelerar la suite; en producción se usa el valor por defecto.
        return new OrganizationSeeder($this->pdo, self::PLAIN_PASSWORD, 'test:seeder', passwordHashCost: 4);
    }

    /**
     * @return list<int>
     */
    private function clientIds(): array
    {
        return array_map('intval', $this->pdo->query('SELECT id FROM clients ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
    }
}

