<?php

declare(strict_types=1);

namespace App\Shop\Promotion;

enum PromotionType: string
{
    case Gift = 'gift';
    case Discount = 'discount';
    case Option = 'option';
}
