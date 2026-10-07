<?php

declare(strict_types=1);

namespace App\Infrastructure\Database\Seeding;

use InvalidArgumentException;

/**
 * Empresa (tenant) de prueba con su jerarquía completa, encabezada por un único nodo raíz.
 */
final readonly class OrganizationFixture
{
    public function __construct(
        public string $name,
        public ?string $description,
        public EmployeeFixture $head,
    ) {
        if (trim($this->name) === '') {
            throw new InvalidArgumentException('El nombre del cliente es obligatorio en el fixture.');
        }
    }

    /**
     * @param array{client: array{name: string, description?: string|null}, head: array<string, mixed>} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['client']['name'],
            description: $data['client']['description'] ?? null,
            head: EmployeeFixture::fromArray($data['head']),
        );
    }

    /**
     * @param list<array<string, mixed>> $organizations
     * @return list<self>
     */
    public static function collectionFromArray(array $organizations): array
    {
        return array_map(self::fromArray(...), $organizations);
    }
}

