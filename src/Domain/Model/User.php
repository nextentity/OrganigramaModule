<?php

declare(strict_types=1);

namespace App\Domain\Model;

/**
 * Entidad de usuario (persona del organigrama). Nunca expone la contraseña.
 */
final readonly class User
{
    public function __construct(
        public int $id,
        public string $userHash,
        public int $clientId,
        public ?int $supervisorId,
        public string $email,
        public string $name,
        public string $lastName,
        public int $identificationNumber,
        public ?int $identificationCode,
        public bool $blockLogin,
        public string $created,
        public ?string $modified,
    ) {
    }

    public function fullName(): string
    {
        return trim("{$this->name} {$this->lastName}");
    }

    public function isRoot(): bool
    {
        return $this->supervisorId === null;
    }
}

