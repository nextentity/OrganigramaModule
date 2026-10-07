<?php

declare(strict_types=1);

namespace App\Infrastructure\Database\Seeding;

use InvalidArgumentException;

/**
 * Nodo de la jerarquía de datos de prueba (un empleado y sus subordinados directos).
 */
final readonly class EmployeeFixture
{
    /**
     * @param list<EmployeeFixture> $subordinates
     */
    public function __construct(
        public string $name,
        public string $lastName,
        public string $email,
        public int $identificationNumber,
        public ?int $identificationCode = null,
        public bool $blockLogin = false,
        public array $subordinates = [],
    ) {
        if (filter_var($this->email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException("Email inválido en fixture: {$this->email}");
        }

        if (trim($this->name) === '' || trim($this->lastName) === '') {
            throw new InvalidArgumentException('Nombre y apellido son obligatorios en el fixture.');
        }

        if ($this->identificationNumber <= 0) {
            throw new InvalidArgumentException("Número de identificación inválido para {$this->email}");
        }
    }

    /**
     * @param array{
     *     name: string,
     *     last_name: string,
     *     email: string,
     *     identification_number: int,
     *     identification_code?: int|null,
     *     block_login?: bool,
     *     subordinates?: list<array<string, mixed>>
     * } $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            lastName: $data['last_name'],
            email: $data['email'],
            identificationNumber: $data['identification_number'],
            identificationCode: $data['identification_code'] ?? null,
            blockLogin: $data['block_login'] ?? false,
            subordinates: array_map(self::fromArray(...), $data['subordinates'] ?? []),
        );
    }

    public function countWithDescendants(): int
    {
        return 1 + array_sum(array_map(
            static fn (self $subordinate): int => $subordinate->countWithDescendants(),
            $this->subordinates,
        ));
    }

    public function depth(): int
    {
        if ($this->subordinates === []) {
            return 1;
        }

        return 1 + max(array_map(static fn (self $subordinate): int => $subordinate->depth(), $this->subordinates));
    }
}

