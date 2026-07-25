<?php

declare(strict_types=1);

namespace App\Game\Shop;

use App\Game\AdvanceCatalog;
use App\Shop\ProductInterface;
use App\Shop\ProductProviderInterface;

/**
 * The Game→Shop seam for ProductProviderInterface: wraps AdvanceCatalog,
 * whose getAdvances() already drives product-grid render order via
 * sortByCost() — that ordering is preserved here untouched.
 */
final readonly class AdvanceProductProvider implements ProductProviderInterface
{
    public function __construct(private AdvanceCatalog $advanceCatalog) {}

    /** @return list<ProductInterface> */
    public function products(): array
    {
        return $this->advanceCatalog->getAdvances();
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
        return array_values($this->advanceCatalog->getAdvancesByNames($keys));
    }
}
