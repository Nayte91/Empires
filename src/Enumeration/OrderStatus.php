<?php

declare(strict_types=1);

namespace App\Enumeration;

enum OrderStatus: string
{
    case Pending = 'pending';
    case Validated = 'validated';
}
