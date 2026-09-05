<?php

declare(strict_types=1);

namespace App\Presentation\Shop;

/** @phpstan-import-type OrderCard from OrderCardProvider */
enum OrderCardFilter: string
{
    case Pending = 'pending';
    case Missing = 'missing';
    case Validated = 'validated';
    case All = 'all';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Missing => 'Empty',
            self::Validated => 'Valid',
            self::All => 'All',
        };
    }

    /** @param OrderCard $card */
    public function accepts(array $card): bool
    {
        return match ($this) {
            self::Pending => 'pending' === $card['status'],
            self::Missing => 'missing' === $card['status'],
            self::Validated => 'validated' === $card['status'],
            self::All => true,
        };
    }
}
