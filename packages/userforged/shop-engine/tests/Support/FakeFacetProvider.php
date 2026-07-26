<?php

declare(strict_types=1);

namespace Userforged\ShopEngine\Tests\Support;

use Userforged\ShopEngine\FacetProviderInterface;

final readonly class FakeFacetProvider implements FacetProviderInterface
{
    /** @param list<string> $facets */
    public function __construct(
        private array $facets = [],
    ) {}

    public function facets(): array
    {
        return $this->facets;
    }
}
