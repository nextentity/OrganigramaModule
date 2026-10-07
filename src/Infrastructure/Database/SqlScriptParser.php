<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

/**
 * Divide un script SQL en sentencias individuales ejecutables por PDO.
 *
 * Soporta la directiva `DELIMITER` (estilo cliente mysql) para poder declarar
 * triggers con cuerpos BEGIN ... END que contienen `;` internos.
 */
final class SqlScriptParser
{
    private const DEFAULT_DELIMITER = ';';

    /**
     * @return list<string>
     */
    public function parse(string $sqlScript): array
    {
        $statements = [];
        $delimiter = self::DEFAULT_DELIMITER;
        $buffer = '';

        foreach (preg_split('/\R/', $sqlScript) ?: [] as $line) {
            $trimmedLine = trim($line);

            if ($trimmedLine === '' || str_starts_with($trimmedLine, '--')) {
                continue;
            }

            if (preg_match('/^DELIMITER\s+(\S+)$/i', $trimmedLine, $matches) === 1) {
                $delimiter = $matches[1];
                continue;
            }

            $buffer .= $line . "\n";

            if (str_ends_with($trimmedLine, $delimiter)) {
                $statement = trim(substr(rtrim($buffer), 0, -strlen($delimiter)));

                if ($statement !== '') {
                    $statements[] = $statement;
                }

                $buffer = '';
            }
        }

        if (trim($buffer) !== '') {
            $statements[] = trim($buffer);
        }

        return $statements;
    }
}

