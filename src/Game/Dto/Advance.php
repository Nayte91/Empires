<?php

declare(strict_types=1);

namespace App\Game\Dto;

use Userforged\ShopEngine\ProductInterface;
use Userforged\ShopEngine\Promotion\ProductPromotion;

final readonly class Advance implements ProductInterface
{
    /**
     * @param list<string>       $facets
     * @param array<string, int> $credits
     * @param list<string>       $mitigations
     * @param list<string>       $aggravations
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $fileName,
        public int $cost,
        public int $points,
        public array $facets,
        public array $credits,
        public array $mitigations,
        public array $aggravations,
        public ?ProductPromotion $promotion = null,
    ) {}
}
