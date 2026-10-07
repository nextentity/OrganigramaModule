<?php

declare(strict_types=1);

namespace App\Domain\Model;

use JsonSerializable;

/**
 * Proyección de lectura de un usuario enriquecida con su posición en la jerarquía.
 */
final readonly class UserView implements JsonSerializable
{
    public function __construct(
        public User $user,
        public int $level,
        public ?string $supervisorName,
        public int $directReports,
        public int $totalReports,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id'                    => $this->user->id,
            'user_hash'             => $this->user->userHash,
            'client_id'             => $this->user->clientId,
            'user_id'               => $this->user->supervisorId,
            'supervisor_name'       => $this->supervisorName,
            'email'                 => $this->user->email,
            'name'                  => $this->user->name,
            'last_name'             => $this->user->lastName,
            'full_name'             => $this->user->fullName(),
            'identification_number' => $this->user->identificationNumber,
            'identification_code'   => $this->user->identificationCode,
            'block_login'           => $this->user->blockLogin,
            'level'                 => $this->level,
            'direct_reports'        => $this->directReports,
            'total_reports'         => $this->totalReports,
            'created'               => $this->user->created,
            'modified'              => $this->user->modified,
        ];
    }
}

