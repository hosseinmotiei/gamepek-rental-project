<?php

namespace App\Enums;

/**
 * A generated contract's lifecycle. Void is reachable from anything not yet
 * Signed; a signed contract is immutable.
 */
enum ContractState: string
{
    case Draft = 'draft';
    case Generated = 'generated';
    case Accepted = 'accepted';
    case Signed = 'signed';
    case Void = 'void';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Generated, self::Void],
            self::Generated => [self::Accepted, self::Void],
            self::Accepted => [self::Signed, self::Void],
            self::Signed => [],
            self::Void => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'پیش‌نویس',
            self::Generated => 'صادر شده',
            self::Accepted => 'پذیرفته شده',
            self::Signed => 'امضا شده',
            self::Void => 'باطل شده',
        };
    }
}
