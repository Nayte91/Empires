<?php

declare(strict_types=1);

namespace App\Tests\Shop\Support;

use App\Shop\ProductInterface;
use App\Shop\Promotion\ProductPromotion;

final readonly class FakeProduct implements ProductInterface
{
    /**
     * @param list<string>       $facets
     * @param array<string, int> $credits
     */
    public function __construct(
        public string $key,
        public int $cost = 0,
        public array $facets = [],
        public array $credits = [],
        public ?ProductPromotion $promotion = null,
    ) {}
}
