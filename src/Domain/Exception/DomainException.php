<?php

declare(strict_types=1);

namespace App\Domain\Exception;

use DomainException as BaseDomainException;

abstract class DomainException extends BaseDomainException
{
    public function __construct(
        string $message,
        private readonly int $statusCode = 400,
        private readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getDetails(): array
    {
        return $this->details;
    }
}

