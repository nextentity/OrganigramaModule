<?php

declare(strict_types=1);

namespace App\Domain\Model;

use JsonSerializable;

final readonly class Client implements JsonSerializable
{
    public function __construct(
        public int $id,
        public string $clientHash,
        public string $name,
        public ?string $description,
        public string $created,
    ) {
    }

    /**
     * @return array{id: int, client_hash: string, name: string, description: ?string, created: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'id'          => $this->id,
            'client_hash' => $this->clientHash,
            'name'        => $this->name,
            'description' => $this->description,
            'created'     => $this->created,
        ];
    }
}

