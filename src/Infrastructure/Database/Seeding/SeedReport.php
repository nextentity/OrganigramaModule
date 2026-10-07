<?php

declare(strict_types=1);

namespace App\Infrastructure\Database\Seeding;

final readonly class SeedReport
{
    /**
     * @param list<string> $createdClients
     * @param list<string> $skippedClients
     */
    public function __construct(
        public array $createdClients,
        public array $skippedClients,
        public int $usersCreated,
    ) {
    }
}

