<?php

declare(strict_types=1);

namespace Userforged\ShopEngine;

use Userforged\ShopEngine\Promotion\ProductPromotion;

interface ProductInterface
{
    public string $key { get; }
    public int $cost { get; }

    /** @var list<string> */
    public array $facets { get; }

    public ?ProductPromotion $promotion { get; }
}
