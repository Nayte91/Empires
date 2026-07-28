<?php

declare(strict_types=1);

namespace App\Rules\Shop;

use App\Rules\Ruleset\AdvanceRegistry;
use Userforged\ShopEngine\ProductInterface;
use Userforged\ShopEngine\ProductProviderInterface;

/**
 * The Game→Shop seam for ProductProviderInterface: wraps AdvanceRegistry,
 * whose getAdvances() already drives catalog render order via
 * sortByCost() — that ordering is preserved here untouched.
 */
final readonly class AdvanceProductProvider implements ProductProviderInterface
{
    public function __construct(private AdvanceRegistry $advanceRegistry) {}

    /** @return list<ProductInterface> */
    public function products(): array
    {
        return $this->advanceRegistry->getAdvances();
    }

    /**
     * @param list<string> $keys
     *
     * @return list<ProductInterface>
     */
    public function productsByKeys(array $keys): array
    {
        // getAdvancesByNames() returns a non-reindexed array (array_filter
        // preserves the original keys), so array_values is required here to
        // hand back a genuine list — callers no longer need to do it themselves.
        return array_values($this->advanceRegistry->getAdvancesByNames($keys));
    }
}
