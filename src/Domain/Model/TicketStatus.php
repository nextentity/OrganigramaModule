<?php

declare(strict_types=1);

namespace App\Domain\Model;

/**
 * Estados del ciclo de vida de un ticket de soporte.
 */
enum TicketStatus: string
{
    case PENDING = 'PENDING';
    case TRIAGED = 'TRIAGED';
    case AUTO_FIXING = 'AUTO_FIXING';
    case OPEN = 'OPEN';
    case RESOLVED = 'RESOLVED';
    case FAILED = 'FAILED';

    public function isTerminal(): bool
    {
        return $this === self::RESOLVED || $this === self::FAILED;
    }

    public function canTransitionTo(self $target): bool
    {
        if ($this === $target) {
            return true;
        }

        if ($this->isTerminal()) {
            return false;
        }

        return match ($this) {
            self::PENDING => in_array($target, [self::TRIAGED, self::OPEN, self::AUTO_FIXING, self::RESOLVED, self::FAILED], true),
            self::TRIAGED => in_array($target, [self::OPEN, self::AUTO_FIXING, self::RESOLVED, self::FAILED], true),
            self::AUTO_FIXING => in_array($target, [self::OPEN, self::RESOLVED, self::FAILED], true),
            self::OPEN => in_array($target, [self::AUTO_FIXING, self::RESOLVED, self::FAILED], true),
            self::RESOLVED, self::FAILED => false,
        };
    }
}

