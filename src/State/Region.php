<?php

declare(strict_types=1);

namespace App\State;

enum Region: string
{
    case West = 'west';
    case East = 'east';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
