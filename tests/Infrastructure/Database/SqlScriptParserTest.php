<?php

declare(strict_types=1);

namespace Tests\Infrastructure\Database;

use App\Infrastructure\Database\SqlScriptParser;
use Tests\Support\TestCase;

final class SqlScriptParserTest extends TestCase
{
    public function testSplitsStatementsAndIgnoresComments(): void
    {
        $statements = (new SqlScriptParser())->parse(<<<SQL
            -- comentario
            CREATE TABLE a (id INT);

            CREATE TABLE b (
              id INT
            );
            SQL);

        $this->assertSame(2, count($statements));
        $this->assertSame('CREATE TABLE a (id INT)', $statements[0]);
    }

    public function testHonoursCustomDelimiterForTriggerBodies(): void
    {
        $statements = (new SqlScriptParser())->parse(<<<SQL
            DROP TRIGGER IF EXISTS t;
            DELIMITER $$
            CREATE TRIGGER t BEFORE INSERT ON a FOR EACH ROW
            BEGIN
              SET NEW.id = 1;
              SET NEW.id = 2;
            END$$
            DELIMITER ;
            SELECT 1;
            SQL);

        $this->assertSame(3, count($statements));
        $this->assertTrue(str_contains($statements[1], 'SET NEW.id = 2;'), 'El cuerpo del trigger debe quedar íntegro');
        $this->assertTrue(str_ends_with($statements[1], 'END'));
        $this->assertSame('SELECT 1', $statements[2]);
    }
}

