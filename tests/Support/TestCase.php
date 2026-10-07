<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Database\DatabaseConfig;
use App\Infrastructure\Database\SchemaMigrator;
use PDO;
use Throwable;

/**
 * Clase base mínima de pruebas (sin dependencias externas).
 * Cada método público con prefijo `test` se ejecuta sobre una instancia nueva.
 */
abstract class TestCase
{
    private int $assertionCount = 0;

    public function setUp(): void
    {
    }

    public function tearDown(): void
    {
    }

    final public function assertionCount(): int
    {
        return $this->assertionCount;
    }

    /**
     * Base SQLite en memoria con el esquema aplicado: aislada y descartable por test.
     */
    protected function createMigratedDatabase(): PDO
    {
        $pdo = (new ConnectionFactory(DatabaseConfig::forSqlite()))->create();
        (new SchemaMigrator($pdo, PROJECT_ROOT . '/database/schema'))->migrate();

        return $pdo;
    }

    protected function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->assertionCount++;

        if ($expected !== $actual) {
            throw new AssertionFailed(sprintf(
                '%sSe esperaba %s, se obtuvo %s',
                $message !== '' ? "{$message}. " : '',
                var_export($expected, true),
                var_export($actual, true),
            ));
        }
    }

    protected function assertTrue(bool $condition, string $message = 'Se esperaba true'): void
    {
        $this->assertSame(true, $condition, $message);
    }

    protected function assertFalse(bool $condition, string $message = 'Se esperaba false'): void
    {
        $this->assertSame(false, $condition, $message);
    }

    protected function assertNull(mixed $value, string $message = 'Se esperaba null'): void
    {
        $this->assertSame(null, $value, $message);
    }

    /**
     * @param class-string<Throwable> $expectedException
     */
    protected function assertThrows(
        callable $action,
        string $expectedException,
        ?string $expectedMessageFragment = null,
    ): void {
        $this->assertionCount++;

        try {
            $action();
        } catch (Throwable $thrown) {
            if (!$thrown instanceof $expectedException) {
                throw new AssertionFailed(sprintf(
                    'Se esperaba %s, se lanzó %s: %s',
                    $expectedException,
                    $thrown::class,
                    $thrown->getMessage(),
                ));
            }

            if ($expectedMessageFragment !== null && !str_contains($thrown->getMessage(), $expectedMessageFragment)) {
                throw new AssertionFailed(sprintf(
                    "El mensaje '%s' no contiene '%s'",
                    $thrown->getMessage(),
                    $expectedMessageFragment,
                ));
            }

            return;
        }

        throw new AssertionFailed("Se esperaba la excepción {$expectedException} y no se lanzó ninguna");
    }
}

