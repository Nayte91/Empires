<?php

declare(strict_types=1);

namespace App\Shop;

enum OrderStatus: string
{
    case Pending = 'pending';
    case Validated = 'validated';
    case Rejected = 'rejected';
}
