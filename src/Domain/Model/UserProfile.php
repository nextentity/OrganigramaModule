<?php

declare(strict_types=1);

namespace App\Domain\Model;

/**
 * Atributos editables y ya resueltos de un usuario (lo que efectivamente se persiste).
 */
final readonly class UserProfile
{
    public function __construct(
        public string $email,
        public string $name,
        public string $lastName,
        public int $identificationNumber,
        public ?int $identificationCode,
        public bool $blockLogin,
    ) {
    }
}

