<?php

declare(strict_types=1);

namespace App\Domain\Model;

/**
 * Comando validado de alta/edición de usuario.
 *
 * Los flags `has*` distinguen "campo ausente" (conservar valor actual en edición)
 * de "campo presente con null" (p. ej. user_id = null ⇒ designar como nivel 1).
 */
final readonly class UserInput
{
    public function __construct(
        public int $clientId,
        public string $email,
        public string $name,
        public string $lastName,
        public int $identificationNumber,
        public bool $hasSupervisor = false,
        public ?int $supervisorId = null,
        public bool $hasIdentificationCode = false,
        public ?int $identificationCode = null,
        public ?bool $blockLogin = null,
        public ?string $plainPassword = null,
    ) {
    }

    public function toProfile(?User $current = null): UserProfile
    {
        return new UserProfile(
            email: $this->email,
            name: $this->name,
            lastName: $this->lastName,
            identificationNumber: $this->identificationNumber,
            identificationCode: $this->hasIdentificationCode ? $this->identificationCode : $current?->identificationCode,
            blockLogin: $this->blockLogin ?? $current?->blockLogin ?? false,
        );
    }
}

