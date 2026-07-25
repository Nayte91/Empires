<?php

declare(strict_types=1);

namespace Userforged\ShopEngine\Promotion;

enum PromotionType: string
{
    case Gift = 'gift';
    case Discount = 'discount';
    case Option = 'option';
}
