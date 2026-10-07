<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Domain\Exception\ValidationException;
use App\Domain\Model\UserInput;

final class Request
{
    /**
     * @return array<string, mixed>
     */
    public static function getJsonBody(): array
    {
        if (defined('TESTING_MODE') && TESTING_MODE && isset($GLOBALS['__TEST_REQUEST_BODY__'])) {
            return is_array($GLOBALS['__TEST_REQUEST_BODY__']) ? $GLOBALS['__TEST_REQUEST_BODY__'] : [];
        }

        $rawInput = file_get_contents('php://input');
        if ($rawInput === false || trim($rawInput) === '') {
            return [];
        }

        $decoded = json_decode($rawInput, true);
        if (!is_array($decoded)) {
            throw ValidationException::single('body', 'El cuerpo de la solicitud debe ser un JSON válido.');
        }

        return $decoded;
    }

    /**
     * Parsea y valida el payload JSON para alta/edición de usuario.
     * Validando presencia de campos requeridos antes de instanciar el UserInput.
     *
     * @param array<string, mixed> $body
     * @param int|null $forcedClientId Si viene de la ruta
     * @param bool $isUpdate
     */
    public static function parseUserInput(array $body, ?int $forcedClientId = null, bool $isUpdate = false): UserInput
    {
        $clientId = $forcedClientId ?? (int) ($body['client_id'] ?? 0);
        $errors = [];

        if (!$isUpdate && $clientId <= 0) {
            $errors['client_id'] = 'El parámetro client_id es obligatorio.';
        }

        if (!array_key_exists('email', $body)) {
            $errors['email'] = 'El campo email es obligatorio.';
        }

        if (!array_key_exists('name', $body)) {
            $errors['name'] = 'El campo name es obligatorio.';
        }

        if (!array_key_exists('last_name', $body)) {
            $errors['last_name'] = 'El campo last_name es obligatorio.';
        }

        if ($errors !== []) {
            throw ValidationException::withErrors($errors);
        }

        $hasSupervisor = array_key_exists('user_id', $body);
        $supervisorId = null;
        if ($hasSupervisor && $body['user_id'] !== null && $body['user_id'] !== '') {
            $supervisorId = (int) $body['user_id'];
        }

        $hasIdentificationCode = array_key_exists('identification_code', $body);
        $identificationCode = null;
        if ($hasIdentificationCode && $body['identification_code'] !== null && $body['identification_code'] !== '') {
            $identificationCode = (int) $body['identification_code'];
        }

        return new UserInput(
            clientId: $clientId,
            email: (string) ($body['email'] ?? ''),
            name: (string) ($body['name'] ?? ''),
            lastName: (string) ($body['last_name'] ?? ''),
            identificationNumber: (int) ($body['identification_number'] ?? 0),
            hasSupervisor: $hasSupervisor,
            supervisorId: $supervisorId,
            hasIdentificationCode: $hasIdentificationCode,
            identificationCode: $identificationCode,
            blockLogin: isset($body['block_login']) ? (bool) $body['block_login'] : null,
            plainPassword: isset($body['password']) ? (string) $body['password'] : null,
        );
    }
}
