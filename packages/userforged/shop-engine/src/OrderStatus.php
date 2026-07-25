<?php

declare(strict_types=1);

namespace Userforged\ShopEngine;

enum OrderStatus: string
{
    case Pending = 'pending';
    case Validated = 'validated';
    case Rejected = 'rejected';
}
