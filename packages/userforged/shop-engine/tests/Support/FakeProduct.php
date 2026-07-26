<?php

declare(strict_types=1);

namespace Userforged\ShopEngine\Tests\Support;

use Userforged\ShopEngine\ProductInterface;
use Userforged\ShopEngine\Promotion\ProductPromotion;

final readonly class FakeProduct implements ProductInterface
{
    /** @param list<string> $facets */
    public function __construct(
        public string $key,
        public int $cost = 0,
        public array $facets = [],
        public ?ProductPromotion $promotion = null,
    ) {}
}
